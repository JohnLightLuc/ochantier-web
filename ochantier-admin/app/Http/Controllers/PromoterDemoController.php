<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chantiers, paiements, offres et avis vus depuis la fiche promoteur.
 *
 * Ces données viennent de `demo_relations` : ce contrôleur disparaîtra quand les modules
 * Chantiers, Paiements, Besoins et Avis auront leurs propres tables.
 */
class PromoterDemoController extends Controller
{
    /**
     * Part des encaissements reversée à O’CHANTIER, et frais de l’opérateur de paiement.
     */
    private const COMMISSION_RATE = 0.08;

    private const OPERATOR_FEE_RATE = 0.01;

    public function site(Promoter $promoter, string $site): Response
    {
        $item = collect($promoter->demo('sites'))->firstWhere('id', $site);
        abort_unless($item, 404);

        $payments = collect($promoter->demo('payments'))->where('site', $item['name'])->values();
        $offers = collect($promoter->demo('offers'))->where('site', $item['name'])->values();
        $team = [['Koffi Adjoumani', 'Maçon', 'Présent'], ['Issa Traoré', 'Électricien', 'Présent'], ['Mamadou Koné', 'Plombier', 'En retard'], ['Aïcha Traoré', 'Peintre', 'Absent']];
        $milestones = [['Études & permis', 10], ['Terrassement & fondations', 25], ['Gros œuvre', 60], ['Second œuvre', 85], ['Finitions & réception', 100]];
        $started = ! in_array($item['status'], ['Brouillon', 'À démarrer'], true);
        $current = false;
        $base = "/promoteurs/{$promoter->slug}";

        return Inertia::render('Promoters/Site', [
            'promoter' => ['slug' => $promoter->slug, 'name' => $promoter->name],
            'site' => [...$item, 'statusTone' => $this->badge($item['status'])['tone']],
            'paid' => $payments->where('status', 'Réussi')->sum('amount'),
            'milestones' => array_map(function (array $m) use ($item, $started, &$current): array {
                $done = $item['progress'] >= $m[1];
                $now = ! $done && ! $current && $started;
                $current = $current || $now;

                return ['label' => $m[0], 'state' => $done ? 'done' : ($now ? 'current' : 'todo')];
            }, $milestones),
            'team' => array_map(fn (array $w): array => ['name' => $w[0], 'job' => $w[1], ...$this->badge($w[2])], array_slice($team, 0, min(4, $item['workers']))),
            'offers' => $offers->map(fn (array $o): array => [...$o, 'statusTone' => $this->badge($o['status'])['tone']])->all(),
            'payments' => $payments->map(fn (array $x): array => [...$x, 'statusTone' => $this->badge($x['status'])['tone']])->all(),
            'header' => $this->header('Détail du chantier', '', [['Promoteurs', '/promoteurs'], [$promoter->name, $base], ['Chantiers', "{$base}/chantiers"], [$item['name']]]),
        ]);
    }

    public function payment(Promoter $promoter, string $ref): Response
    {
        $payment = $this->findPayment($promoter, $ref);
        $base = "/promoteurs/{$promoter->slug}";

        return Inertia::render('Promoters/Payment', [
            'promoter' => ['slug' => $promoter->slug, 'name' => $promoter->name, 'representative' => $promoter->representative, 'phone' => $promoter->phone],
            'payment' => [...$payment, 'statusTone' => $this->badge($payment['status'])['tone']],
            'split' => $this->split($payment),
            'history' => $this->history($promoter, $payment),
            'header' => $this->header('Détail du paiement', '', [['Promoteurs', '/promoteurs'], [$promoter->name, $base], ['Paiements', "{$base}/paiements"], [$payment['ref']]]),
        ]);
    }

    public function authorizePayment(Promoter $promoter, string $ref): RedirectResponse
    {
        abort_unless($this->findPayment($promoter, $ref)['status'] === 'À autoriser', 422);

        $promoter->updateDemo('payments', 'ref', $ref, ['status' => 'Réussi']);
        $promoter->logActivity('a autorisé un paiement', $ref, 'payments', 'success', "/promoteurs/{$promoter->slug}/paiements/{$ref}");

        Inertia::flash('toast', 'Paiement autorisé.');

        return back();
    }

    public function retryPayment(Promoter $promoter, string $ref): RedirectResponse
    {
        abort_unless($this->findPayment($promoter, $ref)['status'] === 'Échoué', 422);

        $promoter->updateDemo('payments', 'ref', $ref, ['status' => 'En traitement']);
        $promoter->logActivity('a relancé un paiement', $ref, 'refresh', 'info', "/promoteurs/{$promoter->slug}/paiements/{$ref}");

        Inertia::flash('toast', 'Paiement relancé auprès de l’opérateur.');

        return back();
    }

    public function receipt(Promoter $promoter, string $ref): HttpResponse
    {
        $payment = $this->findPayment($promoter, $ref);
        $promoter->logActivity('a téléchargé un reçu', $ref, 'download', 'neutral', "/promoteurs/{$promoter->slug}/paiements/{$ref}");

        $html = view('exports.payment-receipt', [
            'promoter' => $promoter,
            'payment' => $payment,
            'split' => $this->split($payment),
            'money' => fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA',
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"recu-{$ref}.html\"",
        ]);
    }

    public function approveOffer(Promoter $promoter, string $offer): RedirectResponse
    {
        $item = collect($promoter->demo('offers'))->firstWhere('id', $offer);
        abort_unless($item && $item['status'] === 'À valider', 422);

        $promoter->updateDemo('offers', 'id', $offer, ['status' => 'Validée']);
        $promoter->logActivity('a validé une offre', $item['name'], 'verified', 'success', "/promoteurs/{$promoter->slug}/offres/{$offer}");

        Inertia::flash('toast', 'Offre validée.');

        return back();
    }

    public function moderateReview(Request $request, Promoter $promoter, string $review): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(['Publié', 'Masqué'])]])['status'];
        abort_unless(collect($promoter->demo('reviews'))->contains('id', $review), 404);

        $promoter->updateDemo('reviews', 'id', $review, ['status' => $status]);

        Inertia::flash('toast', $status === 'Publié' ? 'Avis publié.' : 'Avis masqué.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function findPayment(Promoter $promoter, string $ref): array
    {
        $payment = collect($promoter->demo('payments'))->firstWhere('ref', $ref);
        abort_unless($payment, 404);

        return $payment;
    }

    /**
     * Répartition du montant brut : commission O’CHANTIER, frais opérateur, net versé.
     *
     * @param  array<string, mixed>  $payment
     * @return array{commission: int, fee: int, net: int}
     */
    private function split(array $payment): array
    {
        $exempt = in_array($payment['type'], ['Commission', 'Remboursement'], true);
        $commission = $exempt ? 0 : (int) round($payment['amount'] * self::COMMISSION_RATE);
        $fee = $exempt ? 0 : (int) round($payment['amount'] * self::OPERATOR_FEE_RATE);

        return ['commission' => $commission, 'fee' => $fee, 'net' => $payment['amount'] - $commission - $fee];
    }

    /**
     * Historique des statuts reconstitué depuis le statut courant.
     *
     * @param  array<string, mixed>  $payment
     * @return array<int, array{label: string, when: string, icon: string, color: string}>
     */
    private function history(Promoter $promoter, array $payment): array
    {
        [$day, $time] = array_pad(explode(' · ', $payment['date']), 2, '10:00');
        $step = fn (string $label, string $when, string $icon, string $color): array => compact('label', 'when', 'icon', 'color');

        $history = [$step("Paiement initié par {$promoter->representative}", "{$day} · 08:02", 'add_circle', 'var(--c-primary-500)')];

        if (! in_array($payment['status'], ['À autoriser', 'En attente'], true)) {
            $history[] = $step('Autorisé par N. Yao', "{$day} · 09:15", 'verified', 'var(--c-primary-500)');
            $history[] = $step("Transmis à l’opérateur {$payment['method']}", "{$day} · {$time}", 'send', 'var(--c-primary-500)');
        }

        $history[] = match ($payment['status']) {
            'Réussi' => $step('Paiement réussi', $payment['date'], 'check_circle', 'var(--c-success)'),
            'Échoué' => $step('Échec : fonds insuffisants chez l’opérateur', $payment['date'], 'cancel', 'var(--c-danger)'),
            'En traitement' => $step('En cours de traitement', 'Mise à jour il y a 12 min', 'pending', 'var(--c-info)'),
            'À autoriser' => $step('En attente d’autorisation', "Depuis le {$day}", 'pending', 'var(--c-accent-600)'),
            'En attente' => $step('En attente des fonds du promoteur', "Depuis le {$day}", 'pending', 'var(--c-accent-600)'),
            'Remboursé' => $step('Remboursement effectué', $payment['date'], 'undo', 'var(--c-purple)'),
            default => $step("Statut : {$payment['status']}", $payment['date'], 'info', 'var(--c-subtle)'),
        };

        return $history;
    }
}
