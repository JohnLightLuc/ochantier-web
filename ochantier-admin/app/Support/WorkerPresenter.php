<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/workers) mises en forme pour les pages Ouvriers : statuts et leurs couleurs,
 * pièces par emplacement, alertes et indicateurs de la fiche.
 */
class WorkerPresenter
{
    public const STATUS_TONES = ['brouillon' => 'n', 'soumis' => 'a', 'en_controle' => 'b', 'a_corriger' => 'o', 'valide' => 'g', 'refuse' => 'r', 'suspendu' => 'r', 'exclu' => 'n'];

    public const STATUS_LABELS = ['brouillon' => 'Brouillon', 'soumis' => 'À vérifier', 'en_controle' => 'En vérification', 'a_corriger' => 'À corriger', 'valide' => 'Validé', 'refuse' => 'Refusé', 'suspendu' => 'Suspendu', 'exclu' => 'Exclu'];

    private const AVAILABILITY_TONES = ['disponible' => 'g', 'occupe' => 'a', 'indisponible' => 'r'];

    private const AVAILABILITY_LABELS = ['disponible' => 'Disponible', 'occupe' => 'En mission', 'indisponible' => 'Indisponible'];

    private const DOCUMENT_STATES = [
        'en_attente' => ['À vérifier', 'a'],
        'valide' => ['Vérifié', 'g'],
        'rejete' => ['Rejeté', 'r'],
        'expire' => ['Expiré', 'o'],
        'remplace' => ['Remplacé', 'n'],
        'manquant' => ['Manquant', 'n'],
        'non_fourni' => ['Non fourni', 'n'],
    ];

    private const REVIEW_TONES = ['soumis' => 'a', 'en_controle' => 'b', 'a_corriger' => 'o', 'valide' => 'g', 'refuse' => 'r'];

    /**
     * Informations du dossier qu’une correction peut viser (WorkerCorrectionsRequest::FIELDS de l’API).
     */
    public const CORRECTION_FIELDS = [
        'name' => 'Nom et prénom', 'phone' => 'Téléphone', 'birth' => 'Date et lieu de naissance', 'residence' => 'Adresse de résidence',
        'trade' => 'Métier principal', 'experience' => 'Années d’expérience', 'daily_rate' => 'Coût journalier', 'emergency_contact' => 'Contact d’urgence',
    ];

    /**
     * @param  array{value: string, label?: string}|null  $status
     * @return array{value: string, label: string, tone: string}
     */
    public static function status(?array $status): array
    {
        $value = $status['value'] ?? 'soumis';

        return ['value' => $value, 'label' => self::STATUS_LABELS[$value] ?? ($status['label'] ?? $value), 'tone' => self::STATUS_TONES[$value] ?? 'n'];
    }

    /**
     * @param  array{value: string, label?: string}|null  $availability
     * @return array{value: string, label: string, tone: string}
     */
    public static function availability(?array $availability): array
    {
        $value = $availability['value'] ?? 'indisponible';

        return ['value' => $value, 'label' => self::AVAILABILITY_LABELS[$value] ?? ($availability['label'] ?? $value), 'tone' => self::AVAILABILITY_TONES[$value] ?? 'n'];
    }

    /**
     * Ligne de la liste.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    public static function row(array $w): array
    {
        return [
            'id' => $w['id'],
            'slug' => $w['id'],
            'code' => $w['reference'],
            'name' => $w['full_name'] ?: 'Ouvrier sans nom',
            'trade' => $w['trade']['label'] ?? '—',
            'experienceYears' => (int) ($w['experience_years'] ?? 0),
            'dailyRate' => (int) ($w['daily_rate'] ?? 0),
            'rating' => (float) ($w['rating']['average'] ?? 0),
            'commune' => $w['commune']['name'] ?? null,
            'recruiter' => $w['commercial']['full_name'] ?? null,
            'expiredDocuments' => (int) $w['expired_documents_count'],
            'availability' => self::availability($w['availability']),
            'status' => self::status($w['status']),
        ];
    }

    /**
     * Fiche : identité, métiers, conditions de travail, recrutement, contrôle et sanction en cours.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    public static function worker(array $w): array
    {
        $primary = collect($w['trades'])->firstWhere('is_primary', true) ?? ($w['trades'][0] ?? null);
        $work = $w['work'];
        $yes = fn (?bool $value) => $value ? 'Oui' : 'Non';

        return [
            ...self::row($w),
            'firstName' => $w['first_name'],
            'lastName' => $w['last_name'],
            'gender' => $w['identity']['gender']['label'] ?? null,
            'birthDate' => $w['identity']['birth_date'],
            'birthPlace' => $w['identity']['birth_place'],
            'nationality' => $w['identity']['nationality'],
            'phone' => $w['contact']['phone'] ?? '—',
            'phoneSecondary' => $w['contact']['secondary_phone'],
            'emergencyContact' => trim(implode(' · ', array_filter([$w['contact']['emergency_contact']['name'], $w['contact']['emergency_contact']['relation']]))) ?: null,
            'emergencyPhone' => $w['contact']['emergency_contact']['phone'],
            'address' => trim(implode(', ', array_filter([$w['residence']['address'], $w['residence']['landmark']]))) ?: null,
            'neighborhood' => $w['residence']['neighborhood']['name'] ?? null,
            'level' => $primary['level']['label'] ?? null,
            'secondaryTrades' => collect($w['trades'])->reject(fn (array $t) => $t['is_primary'])->map(fn (array $t) => ($t['trade']['label'] ?? '—').($t['experience_years'] !== null ? " · {$t['experience_years']} ans" : ''))->values()->all(),
            'specialties' => array_column($w['specialties'], 'label'),
            'skills' => array_column($w['skills'], 'label'),
            'references' => array_map(fn (array $r) => [
                'project' => $r['project'],
                'role' => $r['role'],
                'employer' => $r['employer'],
                'period' => self::period($r['start_date'], $r['end_date']),
            ], $w['references']),
            'work' => [
                ['Rayon de mobilité', $work['mobility_radius_km'] ? "{$work['mobility_radius_km']} km" : null],
                ['Longs déplacements', $yes($work['accepts_long_travel'])],
                ['Besoin d’hébergement', $yes($work['needs_accommodation'])],
                ['Moyen de transport', $work['transport_mode'] ? ucfirst(str_replace('_', ' ', $work['transport_mode'])) : null],
                ['Travaille seul', $yes($work['works_solo'])],
                ['Travaille en équipe', $yes($work['works_in_team'])],
                ['Types de mission acceptés', $work['accepted_offer_types'] ? implode(', ', array_map(fn ($t) => ucfirst((string) $t), $work['accepted_offer_types'])) : null],
                ['Rythmes de paiement acceptés', $work['accepted_payment_frequencies'] ? implode(', ', array_map(fn ($t) => ucfirst((string) $t), $work['accepted_payment_frequencies'])) : null],
                ['Disponible à partir du', self::date($work['available_from'])],
            ],
            'reviewsCount' => (int) ($w['rating']['count'] ?? 0),
            'registeredAt' => $w['submitted_at'],
            'validatedAt' => self::date($w['validated_at']),
            'validatedBy' => $w['validated_by']['full_name'] ?? null,
            'originRecruiter' => $w['recruitment']['origin_commercial']['full_name'] ?? null,
            'recruiterPhone' => $w['recruitment']['current_commercial']['phone'] ?? null,
            'acquisitionChannel' => $w['recruitment']['acquisition_channel'] ? ucfirst(str_replace('_', ' ', $w['recruitment']['acquisition_channel'])) : null,
            'identityMismatch' => (bool) $w['identity_mismatch'],
            'stats' => $w['stats'],
            'sanction' => $w['sanction'] ? [
                'type' => $w['sanction']['type']['value'],
                'label' => $w['sanction']['type']['value'] === 'exclusion' ? 'Exclusion' : 'Suspension',
                'reason' => $w['sanction']['reason'],
                'startsAt' => self::date($w['sanction']['starts_at']),
                'endsAt' => self::date($w['sanction']['ends_at']),
                'decidedBy' => $w['sanction']['decided_by']['full_name'] ?? null,
            ] : null,
            'review' => [
                'canDecide' => $w['review']['can_decide'],
                'canApprove' => $w['review']['can_approve'],
                'approvalRefusal' => $w['review']['approval_refusal'],
                'current' => $w['review']['current'] ? self::review($w['review']['current']) : null,
                'history' => array_map(self::review(...), $w['review']['history']),
            ],
            'missing' => $w['missing'] ?? [],
            'can' => ['suspend' => $w['can']['suspend'], 'reactivate' => $w['can']['reactivate'], 'exclude' => $w['can']['exclude'], 'edit' => $w['can']['edit'] ?? false],
        ];
    }

    /**
     * Pièces : emplacements (identité, photo, pièces facultatives), puis autres pièces.
     *
     * @param  array<string, mixed>  $w
     * @return list<array<string, mixed>>
     */
    public static function documents(array $w): array
    {
        $slot = fn (array $s) => self::document($s['document'], $s['key'], $s['label'], $s['required'], $s['state']['value']);

        return [
            ...array_map($slot, $w['documents']['slots']),
            ...array_map(fn (array $d) => self::document($d, $d['type']['code'], $d['type']['label'], false, $d['status']['value']), $w['documents']['others']),
        ];
    }

    /**
     * Indicateurs de la fiche.
     *
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    public static function kpis(array $w, array $documents): array
    {
        $provided = array_filter($documents, fn (array $d) => $d['hasFile']);
        $verified = count(array_filter($provided, fn (array $d) => $d['status']['value'] === 'valide'));
        $rating = $w['rating']['average'];
        $attendance = $w['stats']['attendance_rate'];

        return [
            ['label' => 'Note moyenne', 'value' => $rating !== null ? str_replace('.', ',', number_format((float) $rating, 1)) : '—', 'unit' => $rating !== null ? '/ 5' : null, 'icon' => 'star', 'tone' => 'a', 'hint' => $w['rating']['count'].' avis'],
            ['label' => 'Missions réalisées', 'value' => (string) $w['stats']['missions_completed'], 'icon' => 'engineering', 'tone' => 'g', 'hint' => $w['stats']['last_assignment_at'] ? 'Dernière le '.self::date($w['stats']['last_assignment_at']) : 'Aucune mission'],
            ['label' => 'Taux de présence', 'value' => $attendance !== null ? str_replace('.', ',', (string) round((float) $attendance)) : '—', 'unit' => $attendance !== null ? '%' : null, 'icon' => 'how_to_reg', 'tone' => 'b', 'hint' => $w['stats']['replacement_count'].' remplacement(s)'],
            ['label' => 'Pièces vérifiées', 'value' => $verified.' / '.count($provided), 'icon' => 'folder_shared', 'tone' => $verified === count($provided) && $provided ? 'g' : 'a', 'hint' => $w['review']['can_approve'] ? 'Prêt à valider' : ($w['review']['can_decide'] ? 'Contrôle en cours' : self::status($w['status'])['label'])],
        ];
    }

    /**
     * Alertes de la fiche : contrôle à faire, corrections attendues, sanction, incohérence d’identité, pièces
     * expirées.
     *
     * @param  array<string, mixed>  $w
     * @return list<array<string, mixed>>
     */
    public static function alerts(array $w, string $base): array
    {
        $alerts = [];
        $status = $w['status']['value'];
        $docs = ['type' => 'link', 'href' => "{$base}/identite-documents"];

        if (($w['missing'] ?? []) !== []) {
            $alerts[] = ['tone' => 'warning', 'icon' => 'rule', 'title' => 'Dossier incomplet', 'text' => 'À compléter avant la transmission au contrôle : '.implode(', ', $w['missing']).'.', 'cta' => 'Voir les pièces', 'action' => $docs];
        }

        if ($w['review']['can_decide']) {
            $alerts[] = $w['review']['can_approve']
                ? ['tone' => 'success', 'icon' => 'verified', 'title' => 'Dossier prêt à valider', 'text' => 'Les pièces obligatoires sont vérifiées : vous pouvez valider le dossier.', 'cta' => null, 'action' => null]
                : ['tone' => 'warning', 'icon' => 'pending_actions', 'title' => 'Dossier à vérifier', 'text' => $w['review']['approval_refusal'], 'cta' => 'Voir les pièces', 'action' => $docs];
        }

        if ($status === 'a_corriger') {
            $alerts[] = ['tone' => 'orange', 'icon' => 'edit_document', 'title' => 'Corrections demandées au commercial', 'text' => 'Le dossier reviendra en vérification quand le commercial l’aura corrigé et renvoyé.', 'cta' => null, 'action' => null];
        }

        if ($w['sanction']) {
            $s = $w['sanction'];
            $alerts[] = $s['type']['value'] === 'exclusion'
                ? ['tone' => 'neutral', 'icon' => 'person_off', 'title' => 'Ouvrier exclu', 'text' => $s['reason'].' · le '.self::date($s['starts_at']), 'cta' => null, 'action' => null]
                : ['tone' => 'danger', 'icon' => 'block', 'title' => 'Ouvrier suspendu', 'text' => $s['reason'].($s['ends_at'] ? ' · jusqu’au '.self::date($s['ends_at']) : ' · jusqu’à réactivation'), 'cta' => null, 'action' => null];
        }

        if ($w['identity_mismatch']) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'report', 'title' => 'Identité incohérente', 'text' => 'Les informations saisies ne correspondent pas à la pièce d’identité.', 'cta' => 'Voir les pièces', 'action' => $docs];
        }

        if ($w['expired_documents_count'] > 0) {
            $alerts[] = ['tone' => 'orange', 'icon' => 'event_busy', 'title' => 'Pièce expirée', 'text' => $w['expired_documents_count'].' pièce(s) à renouveler par le commercial.', 'cta' => 'Voir les pièces', 'action' => $docs];
        }

        return $alerts;
    }

    public static function date(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y') : null;
    }

    private static function period(?string $start, ?string $end): string
    {
        return match (true) {
            $start && $end => self::date($start).' → '.self::date($end),
            (bool) $start => 'Depuis le '.self::date($start),
            default => '—',
        };
    }

    /**
     * @param  array<string, mixed>|null  $d
     * @return array<string, mixed>
     */
    public static function document(?array $d, string $key, string $label, bool $required, string $state): array
    {
        [$stateLabel, $tone] = self::DOCUMENT_STATES[$state] ?? [$state, 'n'];

        return [
            'id' => $d['id'] ?? $key,
            'key' => $key,
            'label' => $label,
            'kind' => $d['type']['label'] ?? $label,
            'required' => $required,
            'reference' => ($d['number_last4'] ?? null) ? '•••• '.$d['number_last4'] : null,
            'fileName' => $d['files'][0]['name'] ?? null,
            'addedAt' => $d['created_at'] ?? null,
            'expiresAt' => $d['expires_at'] ?? null,
            'status' => ['value' => $state, 'label' => $stateLabel, 'tone' => $tone],
            'rejectionReason' => $d['rejection_reason'] ?? null,
            'hasFile' => $d !== null && ($d['files'] ?? []) !== [],
            'canDecide' => $state === 'en_attente',
            'files' => array_map(fn (array $f) => ['name' => $f['name'], 'side' => $f['side'], 'mime' => $f['mime_type'], 'url' => $f['url']], $d['files'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private static function review(array $r): array
    {
        return [
            'number' => $r['submission_number'],
            'status' => ['label' => $r['status']['label'], 'tone' => self::REVIEW_TONES[$r['status']['value']] ?? 'n'],
            'submittedAt' => self::date($r['submitted_at']),
            'assignedTo' => $r['assigned_admin']['full_name'] ?? null,
            'decidedBy' => $r['decided_by']['full_name'] ?? null,
            'decidedAt' => self::date($r['decided_at']),
            'reason' => $r['decision_reason'],
            'corrections' => array_map(fn (array $c) => [
                'target' => $c['document']['type']['label'] ?? (self::CORRECTION_FIELDS[$c['field']] ?? 'Dossier'),
                'reason' => $c['reason'],
                'resolved' => $c['resolved_at'] !== null,
            ], $r['corrections']),
        ];
    }
}
