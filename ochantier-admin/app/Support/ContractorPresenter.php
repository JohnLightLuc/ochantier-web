<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Données de l’API (api/v1/admin/contractors) mises en forme pour les pages Entrepreneurs / Techniciens : états du
 * dossier et leurs couleurs, pièces KYB/KYC, alertes, indicateurs et onglets en lecture seule de la fiche.
 */
class ContractorPresenter
{
    /**
     * États affichés (onglets de la liste), calculés par l’API (ContractorAccount::state()).
     */
    public const STATES = [
        'en_verification' => ['En vérification', 'a'],
        'verifie' => ['Vérifié', 'g'],
        'a_corriger' => ['À corriger', 'o'],
        'rejete' => ['Rejeté', 'r'],
        'suspendu' => ['Suspendu', 'r'],
        'brouillon' => ['Brouillon', 'n'],
    ];

    private const DOCUMENT_STATES = [
        'en_attente' => ['À vérifier', 'a'],
        'valide' => ['Confirmée', 'g'],
        'rejete' => ['Rejetée', 'r'],
        'expire' => ['Expirée', 'o'],
        'remplace' => ['Remplacée', 'n'],
    ];

    private const REVIEW_TONES = ['soumis' => 'a', 'en_controle' => 'b', 'a_corriger' => 'o', 'valide' => 'g', 'refuse' => 'r'];

    private const RESPONSE_TONES = ['proposee' => 'b', 'choisie' => 'a', 'retenue' => 'g', 'non_retenue' => 'n', 'retiree' => 'n', 'annulee' => 'n'];

    /**
     * @param  array{value: string, label?: string}|null  $state
     * @return array{value: string, label: string, tone: string}
     */
    public static function state(?array $state): array
    {
        $value = $state['value'] ?? 'brouillon';
        [$label, $tone] = self::STATES[$value] ?? [$state['label'] ?? $value, 'n'];

        return ['value' => $value, 'label' => $label, 'tone' => $tone];
    }

    /**
     * Ligne de la liste.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function row(array $c): array
    {
        $company = ($c['kind']['value'] ?? null) === 'entreprise';

        return [
            'id' => $c['id'],
            'code' => $c['reference'],
            'name' => $c['name'] ?: 'Profil sans nom',
            'kind' => ['value' => $c['kind']['value'] ?? 'independant', 'label' => $company ? 'Entreprise' : 'Indépendant', 'check' => $company ? 'KYB' : 'KYC'],
            'phone' => $c['phone'] ?? null,
            'city' => $c['city']['name'] ?? null,
            'trades' => array_column($c['trades'] ?? [], 'label'),
            'zones' => $c['zones'] ?? [],
            'plan' => ['name' => $c['plan']['name'] ?? 'Solo', 'seats' => (int) ($c['plan']['max_users'] ?? 1)],
            'activity' => ['open' => (int) ($c['activity']['open'] ?? 0), 'total' => (int) ($c['activity']['total'] ?? 0)],
            'rating' => ['average' => isset($c['rating']['average']) ? (float) $c['rating']['average'] : null, 'count' => (int) ($c['rating']['count'] ?? 0)],
            'state' => self::state($c['verification_state'] ?? null),
            'submittedAt' => $c['submitted_at'] ?? null,
        ];
    }

    /**
     * Fiche : identité ou entreprise, coordonnées, activité, formule, réputation, compte et contrôle du dossier.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function contractor(array $c): array
    {
        $company = $c['company'];
        $identity = $c['identity'];
        $verification = $c['verification'];

        return [
            'id' => $c['id'],
            'code' => $c['reference'],
            'name' => $c['name'] ?: 'Profil sans nom',
            'photoUrl' => $c['photo_url'],
            'kind' => ['value' => $c['kind']['value'], 'label' => $company ? 'Entreprise' : 'Indépendant', 'check' => $c['verification_type']],
            'state' => self::state($verification['state']),
            'verificationStatus' => $verification['status']['label'] ?? null,
            'reason' => $verification['reason'],
            'submittedAt' => $verification['submitted_at'],
            'validatedAt' => self::date($verification['validated_at']),
            'identity' => $company ? [
                ['Raison sociale', $company['legal_name']],
                ['Forme juridique', $company['legal_form']],
                ['RCCM', $company['rccm']],
                ['NCC (compte contribuable)', $company['ncc']],
                ['Représentant légal', $company['representative_name']],
            ] : [
                ['Nom', $identity['last_name'] ?? null],
                ['Prénoms', $identity['first_name'] ?? null],
                ['Date de naissance', self::date($identity['birth_date'] ?? null)],
            ],
            'contact' => [
                ['Téléphone', $c['contact']['phone']],
                ['E-mail', $c['contact']['email']],
                ['Ville', $c['contact']['city']['name'] ?? null],
                ['Adresse', $c['contact']['address']],
            ],
            'trades' => array_column($c['activity']['trades'], 'label'),
            'zones' => array_column($c['activity']['zones'], 'name'),
            'experienceYears' => $c['activity']['experience_years'],
            'description' => $c['activity']['description'],
            'plan' => [
                'name' => $c['plan']['name'],
                'seats' => (int) $c['plan']['max_users'],
                'status' => $c['plan']['status']['label'] ?? null,
                'until' => self::date($c['plan']['current_period_end']),
            ],
            'rating' => ['average' => $c['rating']['average'], 'count' => (int) $c['rating']['count']],
            'completedSites' => (int) $c['completed_sites_count'],
            'completion' => ['percent' => (int) $c['completion']['percent'], 'missing' => array_column($c['completion']['missing'], 'label')],
            'account' => $c['account'] ? [
                'status' => $c['account']['status']['label'] ?? null,
                'lastLoginAt' => self::date($c['account']['last_login_at']),
                'createdAt' => self::date($c['account']['created_at']),
            ] : null,
            'suspendedReason' => $c['suspended_reason'],
            'reviews' => array_map(fn (array $r): array => [
                'number' => $r['submission_number'],
                'status' => ['label' => $r['status']['label'], 'tone' => self::REVIEW_TONES[$r['status']['value']] ?? 'n'],
                'submittedAt' => self::date($r['submitted_at']),
                'decidedAt' => self::date($r['decided_at']),
                'decidedBy' => $r['decided_by'] ? trim($r['decided_by']['first_name'].' '.$r['decided_by']['last_name']) : null,
                'reason' => $r['decision_reason'],
            ], $c['reviews']),
            'can' => [
                'approve' => (bool) $c['actions']['approve'],
                'corrections' => (bool) $c['actions']['request_corrections'],
                'reject' => (bool) $c['actions']['reject'],
                'reviewDocuments' => (bool) ($c['actions']['review_documents'] ?? false),
                'suspend' => (bool) $c['actions']['suspend'],
                'reactivate' => (bool) $c['actions']['reactivate'],
            ],
        ];
    }

    /**
     * Pièces du dossier : pièces déposées, puis pièces demandées pour ce profil et pas encore fournies.
     *
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     */
    public static function documents(array $c): array
    {
        $hints = collect($c['document_types'])->keyBy('code');
        $canDecide = (bool) ($c['actions']['review_documents'] ?? false);

        $provided = array_map(function (array $d) use ($hints, $canDecide): array {
            $type = $hints[$d['type']['code']] ?? null;
            $isIdentity = $type['is_identity'] ?? false;
            [$label, $tone] = self::DOCUMENT_STATES[$d['status']['value']] ?? [$d['status']['label'], 'n'];

            return [
                'id' => $d['id'],
                'label' => $d['type']['label'],
                'hint' => $isIdentity ? 'Obligatoire' : ($type['hint'] ?? null),
                'required' => $isIdentity,
                'reference' => $d['number_last4'] ? '•••• '.$d['number_last4'] : null,
                'addedAt' => $d['created_at'],
                'expiresAt' => $d['expires_at'],
                'status' => ['value' => $d['status']['value'], 'label' => $label, 'tone' => $tone],
                'rejectionReason' => $d['rejection_reason'] ?? null,
                'hasFile' => $d['files'] !== [],
                'canDecide' => $canDecide && $d['status']['value'] !== 'remplace',
                'files' => array_map(fn (array $f): array => ['name' => $f['name'], 'side' => $f['side'], 'mime' => $f['mime_type'], 'url' => $f['url']], $d['files']),
            ];
        }, $c['documents']);

        $missing = collect($c['document_types'])
            ->reject(fn (array $t) => $t['provided'])
            ->unique('group')
            ->reject(fn (array $t) => $t['is_identity'] && collect($c['document_types'])->contains(fn (array $o) => $o['is_identity'] && $o['provided']))
            ->map(fn (array $t): array => [
                'id' => $t['code'],
                'label' => $t['is_identity'] ? 'Pièce d’identité' : $t['label'],
                'hint' => $t['hint'],
                'required' => $t['is_identity'],
                'reference' => null,
                'addedAt' => null,
                'expiresAt' => null,
                'status' => ['value' => 'non_fourni', 'label' => 'Non fournie', 'tone' => 'n'],
                'rejectionReason' => null,
                'hasFile' => false,
                'canDecide' => false,
                'files' => [],
            ])->values()->all();

        return [...$provided, ...$missing];
    }

    /**
     * Indicateurs de la fiche.
     *
     * @param  array<string, mixed>  $view
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    public static function kpis(array $view, array $documents): array
    {
        $provided = array_filter($documents, fn (array $d) => $d['hasFile']);
        $confirmed = count(array_filter($provided, fn (array $d) => $d['status']['value'] === 'valide'));
        $rating = $view['rating']['average'];
        $lastLogin = $view['account']['lastLoginAt'] ?? null;

        return [
            ['label' => 'Pièces confirmées', 'value' => $confirmed.' / '.count($provided), 'icon' => 'folder_shared', 'tone' => $provided && $confirmed === count($provided) ? 'g' : 'a', 'hint' => $view['kind']['check'].' · dossier complet à '.$view['completion']['percent'].' %'],
            ['label' => 'Formule', 'value' => $view['plan']['name'], 'icon' => 'workspace_premium', 'tone' => 'b', 'hint' => $view['plan']['seats'] > 1 ? $view['plan']['seats'].' membres' : 'Titulaire seul'],
            ['label' => 'Note moyenne', 'value' => $rating !== null ? str_replace('.', ',', number_format((float) $rating, 1)) : '—', 'unit' => $rating !== null ? '/ 5' : null, 'icon' => 'star', 'tone' => 'a', 'hint' => $view['rating']['count'].' avis'],
            ['label' => 'Chantiers réalisés', 'value' => (string) $view['completedSites'], 'icon' => 'construction', 'tone' => 'g', 'hint' => $lastLogin ? 'Dernière connexion le '.$lastLogin : 'Aucune connexion'],
        ];
    }

    /**
     * Alertes de la fiche : contrôle à faire, corrections attendues, refus, suspension, dossier incomplet.
     *
     * @param  array<string, mixed>  $view
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    public static function alerts(array $view, array $documents, string $base): array
    {
        $alerts = [];
        $pieces = ['type' => 'link', 'href' => "{$base}/pieces"];
        $state = $view['state']['value'];

        if ($view['can']['reviewDocuments']) {
            $toCheck = array_filter($documents, fn (array $d) => $d['hasFile'] && $d['status']['value'] === 'en_attente');
            $rejected = array_filter($documents, fn (array $d) => $d['status']['value'] === 'rejete');
            $identity = collect($documents)->first(fn (array $d) => $d['required'] && $d['hasFile']);

            $alerts[] = match (true) {
                $rejected !== [] => ['tone' => 'orange', 'icon' => 'edit_document', 'title' => 'Pièces rejetées', 'text' => count($rejected).' pièce(s) rejetée(s) : demandez un complément à l’entrepreneur pour qu’il les remplace.', 'cta' => 'Voir les pièces', 'action' => $pieces],
                ($identity['status']['value'] ?? null) !== 'valide' => ['tone' => 'warning', 'icon' => 'pending_actions', 'title' => 'Dossier à vérifier', 'text' => 'Confirmez la pièce d’identité avant de valider le dossier'.($toCheck ? ' ('.count($toCheck).' pièce(s) à vérifier).' : '.'), 'cta' => 'Voir les pièces', 'action' => $pieces],
                default => ['tone' => 'success', 'icon' => 'verified', 'title' => 'Dossier prêt à valider', 'text' => $toCheck ? 'La pièce d’identité est confirmée ; les '.count($toCheck).' autre(s) pièce(s) en attente seront validées avec le dossier.' : 'Toutes les pièces sont confirmées : vous pouvez valider le dossier.', 'cta' => null, 'action' => null],
            };
        }

        if ($state === 'a_corriger') {
            $alerts[] = ['tone' => 'orange', 'icon' => 'edit_document', 'title' => 'Complément demandé à l’entrepreneur', 'text' => ($view['reason'] ? $view['reason'].' · ' : '').'Le dossier reviendra en vérification quand l’entrepreneur l’aura complété et renvoyé.', 'cta' => null, 'action' => null];
        }

        if ($state === 'rejete') {
            $alerts[] = ['tone' => 'danger', 'icon' => 'person_cancel', 'title' => 'Dossier rejeté', 'text' => ($view['reason'] ?: 'Motif non précisé').' · L’entrepreneur peut corriger et soumettre de nouveau son dossier.', 'cta' => null, 'action' => null];
        }

        if ($state === 'suspendu') {
            $alerts[] = ['tone' => 'danger', 'icon' => 'block', 'title' => 'Compte suspendu', 'text' => ($view['suspendedReason'] ?: 'Motif non précisé').' · L’entrepreneur garde l’accès en consultation, sans pouvoir répondre.', 'cta' => null, 'action' => null];
        }

        if ($state === 'brouillon' && $view['completion']['missing'] !== []) {
            $alerts[] = ['tone' => 'neutral', 'icon' => 'rule', 'title' => 'Inscription non terminée', 'text' => 'Encore à renseigner par l’entrepreneur : '.implode(', ', $view['completion']['missing']).'.', 'cta' => null, 'action' => null];
        }

        return $alerts;
    }

    /**
     * Ligne de l’onglet « Réponses aux opportunités ».
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function response(array $r): array
    {
        return [
            'id' => $r['id'],
            'kind' => $r['kind']['label'],
            'reference' => $r['opportunity']['reference'] ?? null,
            'title' => $r['title'],
            'promoter' => $r['promoter'],
            'amount' => $r['amount'],
            'amountLabel' => $r['amount_label'],
            'status' => ['label' => $r['status']['label'], 'tone' => self::RESPONSE_TONES[$r['status']['value']] ?? 'n'],
            'open' => in_array($r['status']['value'], ['proposee', 'choisie'], true),
            'at' => $r['at'],
        ];
    }

    /**
     * Événement de l’onglet « Historique ».
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    public static function event(array $e): array
    {
        $at = Carbon::parse($e['at'])->timezone(config('app.timezone'));
        $admin = $e['kind']['value'] === 'admin';

        return [
            'id' => $e['id'],
            'date' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'author' => $e['author'],
            'action' => $e['what'],
            'category' => $admin ? ['value' => 'admin', 'label' => 'Back-office', 'icon' => 'desktop_windows', 'tone' => 'info'] : ['value' => 'contractor', 'label' => 'Entrepreneur', 'icon' => 'smartphone', 'tone' => 'success'],
            'origin' => $admin ? 'Back-office' : 'Application mobile',
            'comment' => $e['detail'],
            'oldValue' => null,
            'newValue' => null,
        ];
    }

    public static function date(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y') : null;
    }
}
