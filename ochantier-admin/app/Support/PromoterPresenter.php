<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Met en forme pour les pages Promoteurs les données de l’API (api/v1/admin/promoters) : badges colorés, dates
 * lisibles et pièces du dossier. L’identifiant d’URL (`slug`) est l’identifiant du profil promoteur dans l’API.
 */
class PromoterPresenter
{
    /**
     * Statut affiché (vérification du dossier, ou « Suspendu ») => couleur.
     */
    public const STATUS_TONES = ['brouillon' => 'n', 'soumis' => 'b', 'en_controle' => 'a', 'a_corriger' => 'a', 'valide' => 'g', 'refuse' => 'r', 'suspendu' => 'r'];

    /**
     * État d’une pièce du dossier => [libellé, couleur, icône].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const DOCUMENT_STATES = [
        'manquant' => ['Manquant', 'n', 'upload_file'],
        'non_fourni' => ['Non fourni', 'n', null],
        'incomplet' => ['Incomplet', 'a', 'error'],
        'en_verification' => ['À vérifier', 'a', 'schedule'],
        'verifie' => ['Vérifié', 'g', 'verified'],
        'refuse' => ['Rejeté', 'r', 'cancel'],
        'expire' => ['Expiré', 'r', 'event_busy'],
    ];

    /**
     * État de la vérification vu par le promoteur => couleur.
     */
    private const VERIFICATION_TONES = ['a_completer' => 'n', 'en_verification' => 'a', 'verifie' => 'g', 'a_corriger' => 'a', 'refuse' => 'r', 'suspendu' => 'r'];

    /**
     * @param  array{value: string, label: string}  $status
     * @return array{value: string, label: string, tone: string}
     */
    public static function status(array $status): array
    {
        return [...$status, 'tone' => self::STATUS_TONES[$status['value']] ?? 'n'];
    }

    /**
     * Ligne de la liste.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function row(array $p): array
    {
        return [
            'id' => $p['id'],
            'slug' => $p['id'],
            'representative' => $p['full_name'] ?? 'Profil sans nom',
            'name' => $p['company']['legal_name'] ?? '—',
            'phone' => $p['phone'] ?? '—',
            'email' => $p['email'] ?? '—',
            'location' => $p['commune']['name'] ?? '—',
            'kind' => $p['kind']['label'],
            'sitesCount' => $p['sites_count'],
            'expiredDocuments' => $p['expired_documents_count'],
            'openDisputes' => $p['open_disputes_count'],
            'registeredAt' => self::date($p['registered_at']),
            'status' => self::status($p['status']),
        ];
    }

    /**
     * En-tête et informations de la fiche.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function promoter(array $p): array
    {
        $company = $p['company'];
        $account = $p['account'];
        $state = $p['verification']['state'];

        return [
            'id' => $p['id'],
            'slug' => $p['id'],
            'isCompany' => $company !== null,
            'name' => $company['legal_name'] ?? ($p['full_name'] ?? 'Profil sans nom'),
            'representative' => $p['full_name'] ?? '—',
            'representativeRole' => $p['job_title'],
            'legalForm' => $company['legal_form'] ?? null,
            'rccm' => $company['rccm'] ?? null,
            'ncc' => $company['ncc'] ?? null,
            'headOffice' => $company['headquarters']['address'] ?? ($p['address']['address'] ?? null),
            'phone' => $p['phone'] ?? '—',
            'email' => $p['email'] ?? '—',
            'accountType' => $p['kind']['label'],
            'location' => $company['headquarters']['commune']['name'] ?? ($p['address']['commune']['name'] ?? '—'),
            'registeredAt' => self::date($account['registered_at']),
            'lastActiveAt' => self::date($account['last_login_at']),
            'validatedAt' => self::date($account['validated_at']),
            'validatedBy' => $account['validated_by']['full_name'] ?? null,
            'status' => self::status($p['status']),
            'kyc' => ['label' => $state['label'], 'tone' => self::VERIFICATION_TONES[$state['value']] ?? 'n'],
            'completion' => ['percent' => $p['completion']['percent'], 'missing' => array_column($p['completion']['missing'], 'label')],
            'suspension' => $account['status']['value'] === 'suspendu'
                ? ['date' => self::date($account['suspended_at']) ?? '—', 'reason' => $account['suspended_reason']]
                : null,
            'review' => [
                'canDecide' => $p['review']['can_decide'],
                'canApprove' => $p['review']['can_approve'],
                'approvalRefusal' => $p['review']['approval_refusal'],
                'current' => $p['review']['current'] ? self::review($p['review']['current']) : null,
                'history' => array_map(self::review(...), $p['review']['history']),
            ],
            'subscription' => $p['subscription'] ? [
                'plan' => $p['subscription']['plan']['name'] ?? '—',
                'status' => $p['subscription']['status']['label'],
                'periodEnd' => self::date($p['subscription']['current_period_end']),
            ] : null,
            'stats' => $p['stats'],
            'flags' => $p['flags'],
        ];
    }

    /**
     * Pièces attendues (une ligne par pièce, fournie ou non) puis documents complémentaires.
     *
     * @param  array<string, mixed>  $p
     * @return list<array<string, mixed>>
     */
    public static function documents(array $p): array
    {
        $rows = array_map(fn (array $slot) => self::document($slot['document'], $slot['key'], $slot['label'], $slot['required'], $slot['state']), $p['documents']['slots']);

        foreach ($p['documents']['others'] as $other) {
            $rows[] = self::document($other, $other['id'], $other['type']['label'], false, ['value' => $other['status']['value'] === 'valide' ? 'verifie' : 'en_verification']);
        }

        return $rows;
    }

    /**
     * Indicateurs de la fiche.
     *
     * @param  array<string, mixed>  $p
     * @return list<array<string, mixed>>
     */
    public static function kpis(array $p): array
    {
        $disputes = $p['flags']['open_disputes_count'];
        $expired = $p['flags']['expired_documents_count'];

        return [
            ['label' => 'Chantiers publiés', 'value' => (string) $p['stats']['sites'], 'icon' => 'construction', 'tone' => 'g', 'hint' => $p['stats']['active_sites'].' en cours'],
            ['label' => 'Dossier', 'value' => $p['completion']['percent'].' %', 'icon' => 'folder_shared', 'tone' => $p['completion']['complete'] ? 'g' : 'a', 'hint' => $p['completion']['complete'] ? 'Complet' : count($p['completion']['missing']).' élément(s) manquant(s)'],
            ['label' => 'Pièces expirées', 'value' => (string) $expired, 'icon' => 'event_busy', 'tone' => $expired ? 'r' : 'g', 'hint' => $expired ? 'À renouveler' : 'Aucune', 'highlight' => $expired > 0],
            ['label' => 'Litiges ouverts', 'value' => (string) $disputes, 'icon' => 'gavel', 'tone' => $disputes ? 'r' : 'g', 'hint' => $disputes ? 'Sur ses chantiers' : 'Aucun', 'highlight' => $disputes > 0],
        ];
    }

    public static function date(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y') : null;
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @param  array{value: string}  $state
     * @return array<string, mixed>
     */
    private static function document(?array $document, string $key, string $label, bool $required, array $state): array
    {
        [$stateLabel, $tone, $icon] = self::DOCUMENT_STATES[$state['value']] ?? ['—', 'n', null];
        $files = $document['files'] ?? [];
        $status = $document['status']['value'] ?? null;

        return [
            'id' => $document['id'] ?? $key,
            'type' => $key,
            'label' => $label,
            'kind' => $document['type']['label'] ?? null,
            'required' => $required,
            'reference' => isset($document['number_last4']) ? '•••• '.$document['number_last4'] : '—',
            'addedAt' => self::date($document['created_at'] ?? null) ?? '—',
            'expiresAt' => isset($document['expires_at']) ? Carbon::parse($document['expires_at'])->translatedFormat('d M Y') : '—',
            'status' => ['value' => $state['value'], 'label' => $stateLabel, 'tone' => $tone, 'icon' => $icon],
            'rejectionReason' => $document['rejection_reason'] ?? null,
            'hasFile' => $files !== [],
            'files' => array_map(fn (array $file) => ['name' => $file['name'], 'side' => $file['side'], 'mime' => $file['mime_type'], 'url' => $file['url']], $files),
            'canValidate' => $files !== [] && in_array($status, ['en_attente', 'rejete'], true),
            'canReject' => $files !== [] && in_array($status, ['en_attente', 'valide'], true),
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private static function review(array $review): array
    {
        return [
            'number' => $review['submission_number'],
            'status' => $review['status']['label'],
            'submittedAt' => self::date($review['submitted_at']),
            'assignedTo' => $review['assigned_admin']['full_name'] ?? null,
            'decidedBy' => $review['decided_by']['full_name'] ?? null,
            'decidedAt' => self::date($review['decided_at']),
            'reason' => $review['decision_reason'],
            'corrections' => array_map(fn (array $c) => ['label' => $c['document']['type']['label'] ?? $c['field'], 'reason' => $c['reason'], 'resolved' => $c['resolved_at'] !== null], $review['corrections']),
        ];
    }
}
