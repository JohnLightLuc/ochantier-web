<?php

namespace App\Support\Admin;

use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionStatus;
use App\Enums\ReconciliationDiscrepancy;
use App\Enums\ReconciliationItemStatus;
use App\Enums\ReconciliationRunStatus;
use App\Models\Admin;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rapprochement : compare le relevé CSV d’un opérateur aux opérations de la plateforme. Une ligne concordante marque
 * l’opération comme rapprochée ; le reste devient un écart à traiter avec un motif. Rien ne modifie un montant, un
 * solde ou un paiement : corriger reste une action de Paiements.
 */
class Reconciliations
{
    private const REFERENCE_HEADERS = ['reference', 'ref', 'transaction', 'transaction_id', 'id', 'provider_reference'];

    private const AMOUNT_HEADERS = ['montant', 'amount', 'total'];

    private const STATUS_HEADERS = ['statut', 'status', 'etat', 'state'];

    private const SUCCESS_VALUES = ['succes', 'success', 'successful', 'reussi', 'reussie', 'ok', 'paid', 'paye', 'complete', 'completed'];

    private const MAX_ROWS = 50000;

    /**
     * @return Collection<int, array{id: string, name: string, provider: string}>
     */
    public function operators(): Collection
    {
        return PaymentOperator::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (PaymentOperator $operator) => ['id' => $operator->id, 'name' => $operator->name, 'provider' => $operator->provider]);
    }

    /**
     * @return array{runs: int, open_items: int, unreconciled: int}
     */
    public function counts(): array
    {
        return [
            'runs' => ReconciliationRun::query()->count(),
            'open_items' => ReconciliationItem::query()->where('status', ReconciliationItemStatus::Ouvert)->count(),
            'unreconciled' => PaymentTransaction::query()->where('status', PaymentTransactionStatus::Reussie)->whereNull('reconciled_at')->count(),
        ];
    }

    public function import(PaymentOperator $operator, CarbonImmutable $from, CarbonImmutable $to, UploadedFile $file, Admin $admin, Request $request): ReconciliationRun
    {
        $rows = $this->parse($file);

        return DB::transaction(function () use ($operator, $from, $to, $rows, $admin, $request): ReconciliationRun {
            $overlapping = ReconciliationRun::query()
                ->where('provider', $operator->provider)
                ->where('period_start', '<=', $to->endOfDay())
                ->where('period_end', '>=', $from->startOfDay())
                ->whereHas('reconciliationItems', fn ($query) => $query->where('status', ReconciliationItemStatus::Ouvert))
                ->exists();

            if ($overlapping) {
                throw ValidationException::withMessages(['reconciliation' => 'Des écarts restent à traiter sur cette période pour cet opérateur.']);
            }

            $run = ReconciliationRun::query()->create([
                'provider' => $operator->provider,
                'period_start' => $from->startOfDay(),
                'period_end' => $to->endOfDay(),
                'status' => ReconciliationRunStatus::EnCours,
                'run_by_admin_id' => $admin->getKey(),
                'started_at' => now(),
            ]);
            $summary = $this->compare($run, $rows, $operator->provider, $from, $to);
            $run->update(['status' => ReconciliationRunStatus::Termine, 'summary' => $summary, 'completed_at' => now()]);
            AdminAudit::record($admin, 'finance', 'finance.reconciliation_imported', $request, $run, null, $summary);

            return $run;
        });
    }

    public function decide(ReconciliationItem $item, ReconciliationItemStatus $status, string $resolution, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($item, $status, $resolution, $admin, $request): void {
            $locked = ReconciliationItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($locked->status !== ReconciliationItemStatus::Ouvert) {
                throw ValidationException::withMessages(['reconciliation' => 'Cet écart est déjà traité.']);
            }

            $locked->update(['status' => $status, 'resolution' => $resolution, 'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => now()]);
            $locked->transaction?->forceFill(['reconciled_at' => now()])->save();
            AdminAudit::record($admin, 'finance', 'finance.reconciliation_decided', $request, $locked, null, ['status' => $status->value, 'reason' => $resolution]);
        });
    }

    /**
     * @param  list<array{line: int, reference: string, amount: int, success: bool}>  $rows
     * @return array{file_rows: int, matched: int, matched_amount: int, file_amount: int, discrepancies: int}
     */
    private function compare(ReconciliationRun $run, array $rows, string $provider, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $groups = collect($rows)->groupBy('reference');
        $references = $groups->keys();
        $found = PaymentTransaction::query()
            ->where('provider', $provider)
            ->where(fn ($query) => $query->whereIn('provider_reference', $references)->orWhereIn('reference', $references))
            ->get();
        $byReference = $found->keyBy('provider_reference')->union($found->keyBy('reference'));
        $seen = [];
        $matched = 0;
        $matchedAmount = 0;
        $discrepancies = 0;

        $add = function (ReconciliationDiscrepancy $type, ?PaymentTransaction $transaction, ?string $reference, ?int $providerAmount) use ($run, &$discrepancies): void {
            $run->reconciliationItems()->create([
                'transaction_id' => $transaction?->getKey(),
                'provider_reference' => $reference,
                'provider_amount' => $providerAmount,
                'platform_amount' => $transaction ? $this->expected($transaction) : null,
                'discrepancy_type' => $type,
                'status' => ReconciliationItemStatus::Ouvert,
            ]);
            $discrepancies++;
        };

        foreach ($groups as $reference => $lines) {
            $line = $lines->first();
            /** @var PaymentTransaction|null $transaction */
            $transaction = $byReference->get($reference);

            if ($transaction) {
                $seen[$transaction->getKey()] = true;
            }

            $paid = $transaction?->status === PaymentTransactionStatus::Reussie;

            match (true) {
                $lines->count() > 1 => $add(ReconciliationDiscrepancy::Doublon, $transaction, (string) $reference, $line['amount']),
                $transaction === null => $add(ReconciliationDiscrepancy::AbsentPlateforme, null, (string) $reference, $line['amount']),
                $paid !== $line['success'] => $add(ReconciliationDiscrepancy::StatutDifferent, $transaction, (string) $reference, $line['amount']),
                $this->expected($transaction) !== $line['amount'] => $add(ReconciliationDiscrepancy::EcartMontant, $transaction, (string) $reference, $line['amount']),
                default => null,
            };

            if ($lines->count() === 1 && $transaction && $paid && $line['success'] && $this->expected($transaction) === $line['amount']) {
                $transaction->forceFill(['reconciled_at' => now()])->save();
                $matched++;
                $matchedAmount += $line['amount'];
            }
        }

        PaymentTransaction::query()
            ->where('provider', $provider)
            ->where('status', PaymentTransactionStatus::Reussie)
            ->whereNull('reconciled_at')
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('id', array_keys($seen))
            ->get()
            ->each(fn (PaymentTransaction $transaction) => $add(ReconciliationDiscrepancy::AbsentPrestataire, $transaction, $transaction->provider_reference, null));

        return ['file_rows' => count($rows), 'matched' => $matched, 'matched_amount' => $matchedAmount, 'file_amount' => (int) collect($rows)->sum('amount'), 'discrepancies' => $discrepancies];
    }

    /**
     * Ce que l’opérateur doit afficher : un encaissement inclut ses frais.
     */
    private function expected(PaymentTransaction $transaction): int
    {
        return $transaction->amount + ($transaction->direction === PaymentTransactionDirection::Encaissement ? $transaction->fees_amount : 0);
    }

    /**
     * Lit le relevé : séparateur ; ou virgule, colonnes reconnues par leur titre. Une ligne illisible refuse le fichier.
     *
     * @return list<array{line: int, reference: string, amount: int, success: bool}>
     */
    private function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $first = (string) fgets($handle);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn (?string $title) => $this->normalize((string) $title), str_getcsv(trim($first), $delimiter, '"', '\\'));
        $referenceColumn = $this->column($headers, self::REFERENCE_HEADERS);
        $amountColumn = $this->column($headers, self::AMOUNT_HEADERS);
        $statusColumn = $this->column($headers, self::STATUS_HEADERS);

        if ($referenceColumn === null || $amountColumn === null) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'Le relevé doit contenir une colonne « reference » et une colonne « montant ».']);
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if ($cells === [null] || trim(implode('', $cells)) === '') {
                continue;
            }

            $reference = trim((string) ($cells[$referenceColumn] ?? ''));
            $amount = preg_replace('/[^\d]/', '', (string) ($cells[$amountColumn] ?? ''));

            if ($reference === '' || $amount === '' || count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw ValidationException::withMessages(['file' => 'Ligne '.$line.' illisible : référence ou montant manquant.']);
            }

            $rows[] = [
                'line' => $line,
                'reference' => $reference,
                'amount' => (int) $amount,
                'success' => $statusColumn === null || in_array($this->normalize((string) ($cells[$statusColumn] ?? '')), self::SUCCESS_VALUES, true),
            ];
        }

        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Ce relevé ne contient aucune ligne.']);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $names
     */
    private function column(array $headers, array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return Str::lower(trim(Str::ascii($value)));
    }
}
