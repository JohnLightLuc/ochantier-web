<?php

namespace App\Support\Admin;

use App\Enums\ParameterStatus;
use App\Enums\PricingParameterScope;
use App\Enums\PricingVariable;
use App\Models\Admin;
use App\Models\Integration;
use App\Models\MatchingWeightSet;
use App\Models\PricingParameter;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Trade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres de la plateforme : uniquement ce que le code lit réellement. Chaque changement est motivé et conservé.
 *
 * Une commission se propose avec une date d’effet et se valide par une autre personne ; les missions confirmées gardent
 * leur taux figé. Le barème de matching est versionné : les résultats passés gardent le leur.
 */
class PlatformSettings
{
    /**
     * Clé => [groupe, libellé, type, minimum, maximum, unité].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3?: int, 4?: int, 5?: string}>
     */
    public const FIELDS = [
        'payments.dual_validation_threshold' => ['Paiements', 'Seuil de troisième validation d’un remboursement', 'int', 1000, 1000000000, 'FCFA'],
        'proposals.validity_hours' => ['Propositions', 'Validité d’une proposition d’équipe', 'int', 1, 720, 'h'],
        'reservations.hold_hours' => ['Propositions', 'Blocage des ouvriers avant le paiement de l’acompte', 'int', 1, 720, 'h'],
        'matching.profiles_shown' => ['Matching', 'Profils proposés par besoin', 'int', 1, 50, 'profils'],
        'matching.obsolete_after_days' => ['Matching', 'Ancienneté d’un résultat avant recalcul', 'int', 1, 60, 'jours'],
        'attendance.late_tolerance_minutes' => ['Pointage', 'Tolérance de retard', 'int', 0, 240, 'min'],
        'attendance.early_departure_tolerance_minutes' => ['Pointage', 'Tolérance de départ anticipé', 'int', 0, 240, 'min'],
        'attendance.break_minutes' => ['Pointage', 'Pause exclue du temps travaillé', 'int', 0, 240, 'min'],
        'attendance.rounding_minutes' => ['Pointage', 'Arrondi des durées', 'int', 1, 60, 'min'],
        'attendance.min_day_minutes' => ['Pointage', 'Durée au-delà de laquelle la pause est déduite', 'int', 0, 720, 'min'],
        'attendance.zone_tolerance_meters' => ['Pointage', 'Tolérance hors zone', 'int', 0, 5000, 'm'],
        'attendance.gps_precision_meters' => ['Pointage', 'Précision GPS minimale', 'int', 1, 500, 'm'],
        'support.phone' => ['Support', 'Téléphone', 'text'],
        'support.whatsapp' => ['Support', 'WhatsApp', 'text'],
        'support.email' => ['Support', 'E-mail', 'email'],
        'support.hours' => ['Support', 'Horaires', 'text'],
    ];

    private const VARIABLES = ['X' => 'Acompte initial (X)', 'Y' => 'Commission promoteur (Y)', 'Z' => 'Commission ouvrier (Z)', 'R' => 'Seuil de paiement chantier (R)'];

    private const WEIGHTS = ['metier' => 'Métier', 'disponibilite' => 'Disponibilité', 'experience' => 'Expérience', 'proximite' => 'Proximité', 'cout' => 'Coût', 'qualite_profil' => 'Qualité du profil', 'note' => 'Note', 'historique' => 'Historique'];

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::FIELDS))->pluck('value', 'key');

        return [
            'groups' => collect(self::FIELDS)->map(fn (array $field, string $key) => [
                'key' => $key,
                'group' => $field[0],
                'label' => $field[1],
                'type' => $field[2],
                'min' => $field[3] ?? null,
                'max' => $field[4] ?? null,
                'unit' => $field[5] ?? null,
                'value' => $stored->get($key),
            ])->groupBy('group')->map(fn ($fields, string $group) => ['title' => $group, 'fields' => $fields->values()->all()])->values()->all(),
            'pricing' => $this->pricing(),
            'matching' => $this->matching(),
            'trades' => Trade::query()->withCount('workerTrades as workers_count')->orderBy('label')->get()->map(fn (Trade $trade) => ['id' => $trade->id, 'code' => $trade->code, 'label' => $trade->label, 'active' => $trade->is_active, 'workers' => $trade->workers_count])->all(),
            'integrations' => $this->integrations(),
            'history' => SettingHistory::query()->with('changedByAdmin')->latest()->limit(30)->get()->map(fn (SettingHistory $row) => [
                'id' => $row->id,
                'key' => $row->setting_key,
                'label' => self::FIELDS[$row->setting_key][1] ?? $row->setting_key,
                'old' => $row->old_value,
                'new' => $row->new_value,
                'reason' => $row->reason,
                'by' => $row->changedByAdmin?->fullName(),
                'date' => $row->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function saveValue(string $key, mixed $value, string $reason, Admin $admin, Request $request): void
    {
        $field = self::FIELDS[$key] ?? throw ValidationException::withMessages(['value' => 'Ce réglage n’existe pas.']);
        $clean = $this->validated($field, $value);

        DB::transaction(function () use ($key, $clean, $reason, $admin, $request): void {
            $setting = Setting::query()->lockForUpdate()->find($key) ?? new Setting(['key' => $key]);
            $old = $setting->value;

            if ($old === $clean) {
                throw ValidationException::withMessages(['value' => 'Cette valeur est déjà en place.']);
            }

            $setting->fill(['value' => $clean, 'updated_by_admin_id' => $admin->getKey()])->save();
            $history = SettingHistory::query()->create(['setting_key' => $key, 'old_value' => $old, 'new_value' => $clean, 'reason' => $reason, 'changed_by_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'settings', 'settings.updated', $request, $history, ['value' => $old], ['key' => $key, 'value' => $clean, 'reason' => $reason]);
        });
    }

    /**
     * @param  array{code: string, value: float|int|string, effective_from: string, reason: string}  $data
     */
    public function proposeRate(array $data, Admin $admin, Request $request): PricingParameter
    {
        return DB::transaction(function () use ($data, $admin, $request): PricingParameter {
            $code = PricingVariable::from($data['code']);
            $this->ensure(! $this->rates($code)->where('status', ParameterStatus::EnAttenteApprobation)->lockForUpdate()->exists(), 'Une proposition attend déjà sa validation pour ce taux.', 'pricing');

            $rate = PricingParameter::query()->create([
                'code' => $code,
                'scope' => PricingParameterScope::Global,
                'value' => $data['value'],
                'effective_from' => CarbonImmutable::parse($data['effective_from'])->startOfDay(),
                'reason' => $data['reason'],
                'status' => ParameterStatus::EnAttenteApprobation,
                'created_by_admin_id' => $admin->getKey(),
            ]);
            AdminAudit::record($admin, 'settings', 'settings.rate_proposed', $request, $rate, null, ['code' => $code->value, 'value' => $data['value'], 'effective_from' => $data['effective_from'], 'reason' => $data['reason']]);

            return $rate;
        });
    }

    /**
     * Le taux en place s’arrête à la date d’effet du nouveau. Une date déjà passée s’applique dès la validation.
     */
    public function approveRate(PricingParameter $rate, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($rate, $admin, $request): void {
            $rate = PricingParameter::query()->lockForUpdate()->findOrFail($rate->getKey());
            $this->ensure($rate->status === ParameterStatus::EnAttenteApprobation, 'Cette proposition est déjà traitée.', 'pricing');
            $this->ensure($rate->created_by_admin_id !== $admin->getKey(), 'Une autre personne doit valider ce taux.', 'pricing');

            $from = CarbonImmutable::parse($rate->effective_from)->max(now());
            $previous = $this->rates($rate->code)->where('status', ParameterStatus::Actif)->whereNull('effective_to')->lockForUpdate()->latest('effective_from')->first();
            $this->ensure($previous === null || CarbonImmutable::parse($previous->effective_from)->lessThan($from), 'Un taux plus récent est déjà programmé.', 'pricing');

            $previous?->update(['effective_to' => $from]);
            $rate->update(['status' => ParameterStatus::Actif, 'effective_from' => $from, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => now()]);
            AdminAudit::record($admin, 'settings', 'settings.rate_approved', $request, $rate, ['value' => $previous?->value], ['code' => $rate->code->value, 'value' => $rate->value, 'effective_from' => $from->toIso8601String()]);
        });
    }

    public function refuseRate(PricingParameter $rate, string $reason, Admin $admin, Request $request): void
    {
        $this->ensure($rate->status === ParameterStatus::EnAttenteApprobation, 'Cette proposition est déjà traitée.', 'pricing');

        $rate->update(['status' => ParameterStatus::Refuse]);
        AdminAudit::record($admin, 'settings', 'settings.rate_refused', $request, $rate, null, ['code' => $rate->code->value, 'reason' => $reason]);
    }

    /**
     * Nouvelle version du barème ; la précédente reste rattachée aux résultats déjà calculés.
     *
     * @param  array{weights: array<string, int>, radius: int, radius_max: int, tolerance_max: int, reason: string}  $data
     */
    public function saveMatching(array $data, Admin $admin, Request $request): MatchingWeightSet
    {
        if (array_sum($data['weights']) !== 100) {
            throw ValidationException::withMessages(['weights' => 'La somme des pondérations doit faire 100 %.']);
        }

        if ($data['radius_max'] < $data['radius']) {
            throw ValidationException::withMessages(['radius_max' => 'Le rayon maximal doit dépasser le rayon prioritaire.']);
        }

        return DB::transaction(function () use ($data, $admin, $request): MatchingWeightSet {
            $current = MatchingWeightSet::query()->where('is_active', true)->lockForUpdate()->latest('effective_from')->first();
            MatchingWeightSet::query()->where('is_active', true)->update(['is_active' => false]);

            $set = MatchingWeightSet::query()->create([
                'version' => (int) MatchingWeightSet::query()->max('version') + 1,
                'weights' => collect($data['weights'])->map(fn (int $weight) => $weight / 100)->all(),
                'exclusion_rules' => [...($current?->exclusion_rules ?? []), 'rayon_km' => $data['radius'], 'rayon_max_km' => $data['radius_max'], 'tolerance_budget_max_pct' => $data['tolerance_max']],
                'fairness_rules' => $current?->fairness_rules ?? [],
                'reason' => $data['reason'],
                'is_active' => true,
                'effective_from' => now(),
                'created_by_admin_id' => $admin->getKey(),
            ]);
            AdminAudit::record($admin, 'settings', 'settings.matching_updated', $request, $set, ['version' => $current?->version], ['version' => $set->version, 'reason' => $data['reason']]);

            return $set;
        });
    }

    public function createTrade(string $label, Admin $admin, Request $request): Trade
    {
        $code = Str::limit(Str::slug($label, '_'), 36, '') ?: 'metier';
        $this->ensure(! Trade::query()->where('code', $code)->exists(), 'Ce métier existe déjà.', 'label');

        $trade = Trade::query()->create(['code' => $code, 'label' => trim($label), 'sort_order' => (int) Trade::query()->max('sort_order') + 10, 'is_active' => true]);
        AdminAudit::record($admin, 'settings', 'settings.trade_created', $request, $trade, null, ['label' => $trade->label]);

        return $trade;
    }

    public function renameTrade(Trade $trade, string $label, Admin $admin, Request $request): void
    {
        $before = $trade->label;
        $trade->update(['label' => trim($label)]);
        AdminAudit::record($admin, 'settings', 'settings.trade_renamed', $request, $trade, ['label' => $before], ['label' => $trade->label]);
    }

    /**
     * Un métier retiré n’est plus proposé ; les ouvriers qui l’ont le gardent.
     */
    public function toggleTrade(Trade $trade, Admin $admin, Request $request): void
    {
        $trade->update(['is_active' => ! $trade->is_active, 'archived_at' => $trade->is_active ? now() : null]);
        AdminAudit::record($admin, 'settings', 'settings.trade_toggled', $request, $trade, null, ['active' => $trade->is_active]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(): array
    {
        $now = now();
        $rows = PricingParameter::query()->where('scope', PricingParameterScope::Global)->with(['createdByAdmin', 'approvedByAdmin'])->latest('effective_from')->latest('created_at')->get();
        $row = fn (PricingParameter $rate) => [
            'id' => $rate->id,
            'code' => $rate->code->value,
            'label' => self::VARIABLES[$rate->code->value],
            'value' => (float) $rate->value,
            'status' => ['value' => $rate->status->value, 'label' => $rate->status->label()],
            'effective_from' => $rate->effective_from?->toIso8601String(),
            'effective_to' => $rate->effective_to?->toIso8601String(),
            'reason' => $rate->reason,
            'by' => $rate->createdByAdmin?->fullName(),
            'by_id' => $rate->created_by_admin_id,
            'approved_by' => $rate->approvedByAdmin?->fullName(),
        ];

        return [
            'current' => collect(self::VARIABLES)->map(function (string $label, string $code) use ($rows, $now): array {
                $rate = $rows->first(fn (PricingParameter $rate) => $rate->code->value === $code && $rate->status === ParameterStatus::Actif && $rate->effective_from <= $now && ($rate->effective_to === null || $rate->effective_to > $now));

                return ['code' => $code, 'label' => $label, 'value' => $rate ? (float) $rate->value : null];
            })->values()->all(),
            'pending' => $rows->where('status', ParameterStatus::EnAttenteApprobation)->map($row)->values()->all(),
            'history' => $rows->where('status', '!=', ParameterStatus::EnAttenteApprobation)->take(20)->map($row)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matching(): array
    {
        $set = MatchingWeightSet::query()->where('is_active', true)->latest('effective_from')->first();
        $rules = $set?->exclusion_rules ?? [];

        return [
            'version' => $set?->version,
            'weights' => collect(self::WEIGHTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => (int) round(($set?->weights[$key] ?? 0) * 100)])->values()->all(),
            'radius' => (int) ($rules['rayon_km'] ?? 20),
            'radius_max' => (int) ($rules['rayon_max_km'] ?? 40),
            'tolerance_max' => (int) ($rules['tolerance_budget_max_pct'] ?? 25),
            'reason' => $set?->reason,
        ];
    }

    /**
     * État réel des connexions, sans aucun secret.
     *
     * @return array{runtime: list<array{label: string, value: string, warning: bool}>, providers: list<array<string, mixed>>}
     */
    private function integrations(): array
    {
        // En production, la passerelle simulée est refusée : tant qu’aucun prestataire n’est branché, aucun paiement ne passe.
        $production = app()->isProduction();
        $sms = (string) config('services.sms.driver');
        $mail = (string) config('mail.default');

        return [
            'runtime' => [
                ['label' => 'Passerelle de paiement', 'value' => $production ? 'Aucun prestataire branché : les paiements échouent' : 'Simulée : aucun argent ne circule', 'warning' => true],
                ['label' => 'Envoi de SMS', 'value' => $sms ?: 'Non configuré', 'warning' => in_array($sms, ['', 'log'], true)],
                ['label' => 'Envoi d’e-mails', 'value' => $mail, 'warning' => in_array($mail, ['log', 'array'], true)],
                ['label' => 'Notifications push', 'value' => (string) config('services.push.driver') ?: 'Non configuré', 'warning' => in_array((string) config('services.push.driver'), ['', 'log'], true)],
            ],
            'providers' => Integration::query()->orderBy('provider')->get()->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'provider' => $integration->provider,
                'service' => $integration->service_type,
                'environment' => $integration->environment,
                'status' => $integration->status,
                'expires_on' => $integration->credentials_expire_at?->toDateString(),
                'last_tested_at' => $integration->last_tested_at?->toIso8601String(),
                'last_test_ok' => $integration->last_test_ok,
            ])->all(),
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3?: int, 4?: int, 5?: string}  $field
     */
    private function validated(array $field, mixed $value): int|string
    {
        if ($field[2] === 'int') {
            $this->ensure(filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= $field[3] && (int) $value <= $field[4], 'Saisissez un nombre entre '.$field[3].' et '.$field[4].'.', 'value');

            return (int) $value;
        }

        $text = trim((string) $value);
        $this->ensure($text !== '' && mb_strlen($text) <= 190, 'Saisissez une valeur.', 'value');
        $this->ensure($field[2] !== 'email' || filter_var($text, FILTER_VALIDATE_EMAIL) !== false, 'Saisissez une adresse e-mail valide.', 'value');

        return $text;
    }

    /**
     * @return Builder<PricingParameter>
     */
    private function rates(PricingVariable $code)
    {
        return PricingParameter::query()->where('code', $code)->where('scope', PricingParameterScope::Global);
    }

    private function ensure(bool $condition, string $message, string $key): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}
