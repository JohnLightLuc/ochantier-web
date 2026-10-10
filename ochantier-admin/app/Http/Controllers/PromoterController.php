<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Http\Requests\PromoterRequest;
use App\Http\Resources\PromoterActivityResource;
use App\Http\Resources\PromoterDocumentResource;
use App\Http\Resources\PromoterListResource;
use App\Http\Resources\PromoterResource;
use App\Models\Promoter;
use App\Models\PromoterDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromoterController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * Colonnes triables de la liste : clé d’URL → colonne.
     */
    private const SORTABLE = ['promoteur' => 'representative', 'entreprise' => 'name', 'contact' => 'phone', 'localisation' => 'location', 'paiements' => 'payment_status', 'statut' => 'status'];

    /**
     * Onglets de la fiche : segment d’URL → libellé.
     */
    private const TABS = ['' => 'Vue générale', 'kyc' => 'Entreprise & KYC', 'chantiers' => 'Chantiers', 'offres' => 'Besoins & offres', 'paiements' => 'Paiements', 'avis' => 'Avis & litiges', 'activite' => 'Activité'];

    /**
     * Liste filtrable, triable et paginée.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'statut', 'localisation', 'type', 'kyc', 'inscription', 'rapide', 'tri', 'sens', 'page']);

        $paginator = $this->filteredQuery($filters)
            ->with('documents')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $all = Promoter::with('documents')->get();
        $flags = $all->map(fn (Promoter $p): array => $this->flags($p));

        return Inertia::render('Promoters/Index', [
            'promoters' => [
                'data' => PromoterListResource::collection($paginator->items())->resolve(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map(fn (PromoterStatus $s): array => ['value' => $s->value, 'label' => $s->label()], PromoterStatus::cases()),
                'localisation' => $all->pluck('location')->unique()->sort()->values()->map(fn (string $l): array => ['value' => $l, 'label' => $l])->all(),
                'type' => array_map(fn (AccountType $t): array => ['value' => $t->value, 'label' => $t->label()], AccountType::cases()),
                'kyc' => array_map(fn (DocumentStatus $s): array => ['value' => $s->value, 'label' => $s->label()], DocumentStatus::cases()),
                'inscription' => [['value' => '30j', 'label' => '30 derniers jours'], ['value' => '2026', 'label' => '2026'], ['value' => '2025', 'label' => '2025'], ['value' => '2024', 'label' => '2024']],
            ],
            'kpis' => [
                'total' => $all->count(),
                'active' => $all->where('status', PromoterStatus::Active)->count(),
                'pending' => $all->where('status', PromoterStatus::Pending)->count(),
                'suspended' => $all->where('status', PromoterStatus::Suspended)->count(),
            ],
            'todo' => [
                'incomplete' => $flags->where('incomplete', true)->count(),
                'expiredDocuments' => $all->sum(fn (Promoter $p): int => $p->documents->where('status', DocumentStatus::Expired)->count()),
                'expiredPromoters' => $flags->where('expired', true)->count(),
                'reported' => $flags->where('reported', true)->count(),
                'total' => $flags->filter(fn (array $f): bool => in_array(true, $f, true))->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Promoter>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = Promoter::query()->search($filters['q'] ?? null);

        if ($status = PromoterStatus::tryFrom($filters['statut'] ?? '')) {
            $query->where('status', $status);
        }
        if (! empty($filters['localisation'])) {
            $query->where('location', $filters['localisation']);
        }
        if ($type = AccountType::tryFrom($filters['type'] ?? '')) {
            $query->where('account_type', $type);
        }
        if ($kyc = DocumentStatus::tryFrom($filters['kyc'] ?? '')) {
            $query->withKycStatus($kyc);
        }
        match ($filters['inscription'] ?? null) {
            '30j' => $query->where('registered_at', '>=', now()->subDays(30)->startOfDay()),
            '2026', '2025', '2024' => $query->whereYear('registered_at', (int) $filters['inscription']),
            default => null,
        };

        match ($filters['rapide'] ?? null) {
            'incomplets' => $query->withIncompleteFile(),
            'expirees' => $query->withExpiredDocuments(),
            'signales' => $query->whereIn('id', $this->reportedIds()),
            'tous' => $query->where(fn (Builder $q) => $q->withIncompleteFile()->orWhere(fn (Builder $q) => $q->withExpiredDocuments())->orWhereIn('id', $this->reportedIds())),
            default => null,
        };

        $column = self::SORTABLE[$filters['tri'] ?? ''] ?? null;

        return $column
            ? $query->orderBy($column, ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('id')
            : $query->orderBy('id');
    }

    /**
     * Promoteurs ayant un litige non résolu (données de démonstration en JSON).
     *
     * @return array<int, int>
     */
    private function reportedIds(): array
    {
        return Promoter::all(['id', 'demo_relations'])
            ->filter(fn (Promoter $p): bool => $p->hasOpenDisputes())
            ->pluck('id')
            ->all();
    }

    /**
     * @return array{incomplete: bool, expired: bool, reported: bool}
     */
    private function flags(Promoter $promoter): array
    {
        return [
            'incomplete' => $promoter->documents->contains(fn (PromoterDocument $d): bool => $d->type->isRequired() && $d->status === DocumentStatus::Missing),
            'expired' => $promoter->documents->contains('status', DocumentStatus::Expired),
            'reported' => $promoter->hasOpenDisputes(),
        ];
    }

    /**
     * Export CSV (tous les promoteurs, ou la sélection passée dans `ids`).
     */
    public function export(Request $request): StreamedResponse
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));
        $promoters = Promoter::with('documents')->when($ids, fn (Builder $q) => $q->whereIn('id', $ids))->orderBy('id')->get();

        return response()->streamDownload(function () use ($promoters): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Promoteur', 'Entreprise', 'Téléphone', 'E-mail', 'Localisation', 'Chantiers', 'Paiements', 'KYC', 'Statut'], ';');
            foreach ($promoters as $p) {
                fputcsv($out, [$p->representative, $p->name, $p->phone, $p->email, $p->location, count($p->demo('sites')), $p->payment_status->label(), $p->kycStatus()->label(), $p->status->label()], ';');
            }
            fclose($out);
        }, 'promoteurs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): Response
    {
        return Inertia::render('Promoters/Form', [
            'mode' => 'create',
            'promoter' => null,
            'values' => array_fill_keys(['name', 'legal_form', 'rccm', 'ncc', 'sector', 'head_office', 'representative', 'representative_role', 'phone', 'email', 'account_type', 'location', 'note'], ''),
            'options' => $this->formOptions(),
            'header' => $this->header('Ajouter un promoteur', 'Créez un compte promoteur. Les champs marqués * sont obligatoires.', [['Promoteurs', '/promoteurs'], ['Nouveau promoteur']]),
        ]);
    }

    public function store(PromoterRequest $request): RedirectResponse
    {
        $data = $request->promoterData();

        $promoter = Promoter::create([
            ...$data,
            'slug' => Promoter::uniqueSlug($data['name']),
            'status' => PromoterStatus::Pending,
            'payment_status' => PromoterPaymentStatus::Pending,
            'registered_at' => today(),
            'demo_relations' => ['sites' => [], 'offers' => [], 'payments' => [], 'reviews' => [], 'disputes' => []],
        ]);
        $promoter->createMissingDocuments();
        $promoter->logActivity('a créé le compte promoteur', $promoter->name, 'person_add', 'success');

        Inertia::flash('toast', "Promoteur « {$promoter->name} » créé.");

        return to_route('promoters.show', $promoter);
    }

    public function edit(Request $request, Promoter $promoter): Response
    {
        $company = $request->routeIs('promoters.edit-company');

        return Inertia::render('Promoters/Form', [
            'mode' => $company ? 'company' : 'edit',
            'promoter' => ['slug' => $promoter->slug, 'name' => $promoter->name],
            'values' => [
                'name' => $promoter->name, 'legal_form' => $promoter->legal_form, 'rccm' => $promoter->rccm, 'ncc' => $promoter->ncc ?? '',
                'sector' => $promoter->sector ?? '', 'head_office' => $promoter->head_office, 'representative' => $promoter->representative,
                'representative_role' => $promoter->representative_role ?? '', 'phone' => $promoter->phone, 'email' => $promoter->email,
                'account_type' => $promoter->account_type->value, 'location' => $promoter->location, 'note' => $promoter->note ?? '',
            ],
            'options' => $this->formOptions(),
            'header' => $this->header(
                $company ? 'Modifier les informations de l’entreprise' : 'Modifier un promoteur',
                $promoter->name.' · les champs marqués * sont obligatoires.',
                [['Promoteurs', '/promoteurs'], [$promoter->name, "/promoteurs/{$promoter->slug}".($company ? '/kyc' : '')], ['Modifier']],
            ),
        ]);
    }

    public function update(PromoterRequest $request, Promoter $promoter): RedirectResponse
    {
        $company = $request->isCompanyOnly();

        $promoter->update($request->promoterData());
        $promoter->logActivity($company ? 'a modifié les informations de l’entreprise' : 'a modifié le profil', $promoter->name, 'edit', 'purple');

        Inertia::flash('toast', 'Modifications enregistrées.');

        return $company ? to_route('promoters.show', [$promoter, 'kyc']) : to_route('promoters.show', $promoter);
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function formOptions(): array
    {
        $plain = fn (array $values): array => array_map(fn (string $v): array => ['value' => $v, 'label' => $v], $values);

        return [
            'legal_form' => $plain(PromoterRequest::LEGAL_FORMS),
            'sector' => $plain(PromoterRequest::SECTORS),
            'account_type' => array_map(fn (AccountType $t): array => ['value' => $t->value, 'label' => $t->label()], AccountType::cases()),
            'location' => $plain(PromoterRequest::LOCATIONS),
        ];
    }

    /**
     * Fiche détaillée : vue générale et onglets. `item` ouvre le panneau d’un document KYC ou d’une offre.
     */
    public function show(Promoter $promoter, ?string $tab = null, ?string $item = null): Response
    {
        $tab ??= '';
        $tabKey = $tab === 'litiges' ? 'avis' : $tab;
        abort_unless(array_key_exists($tabKey, self::TABS), 404);

        $promoter->load('documents', 'activities');
        $base = "/promoteurs/{$promoter->slug}";
        $documents = $promoter->documents->sortBy(fn (PromoterDocument $d): int => array_search($d->type, DocumentType::cases(), true))->values();
        $relations = $this->relations($promoter);

        $crumbs = [['Promoteurs', '/promoteurs'], [$promoter->name, $tabKey === '' ? null : $base]];
        $crumbs[] = [self::TABS[$tabKey]];

        return Inertia::render('Promoters/Show', [
            'promoter' => PromoterResource::make($promoter)->resolve(),
            'tab' => $tab,
            'kpis' => $this->kpis($promoter),
            'tabCounts' => [
                'kyc' => $documents->where('status', '!=', DocumentStatus::Verified)->count(),
                'chantiers' => count($relations['sites']),
                'offres' => count($relations['offers']),
                'paiements' => count($relations['payments']),
                'avis' => count($relations['reviews']) + count($relations['disputes']),
            ],
            'documents' => PromoterDocumentResource::collection($documents)->resolve(),
            'activities' => PromoterActivityResource::collection($promoter->activities->take(200))->resolve(),
            'relations' => $relations,
            'alerts' => $tabKey === '' ? $this->alerts($promoter, $documents->all(), $relations, $base) : [],
            'drawer' => $item ? $this->drawer($promoter, $tab, $item, $documents->all(), $relations) : null,
            'header' => $this->header('Détail du promoteur', '', $crumbs),
        ]);
    }

    /**
     * Données de démonstration avec la couleur de chaque statut.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function relations(Promoter $promoter): array
    {
        $withBadge = fn (string $relation): array => array_map(
            fn (array $item): array => [...$item, 'statusTone' => $this->badge($item['status'])['tone']],
            $promoter->demo($relation),
        );

        return [
            'sites' => $withBadge('sites'),
            'offers' => array_map(fn (array $o): array => [...$o, 'typeTone' => $this->badge($o['type'])['tone']], $withBadge('offers')),
            'payments' => $withBadge('payments'),
            'reviews' => $withBadge('reviews'),
            'disputes' => array_map(fn (array $d): array => [...$d, 'priorityTone' => $this->badge($d['priority'])['tone']], $withBadge('disputes')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kpis(Promoter $promoter): array
    {
        $sites = collect($promoter->demo('sites'));
        $offers = collect($promoter->demo('offers'));
        $payments = collect($promoter->demo('payments'));
        $disputes = collect($promoter->demo('disputes'));
        $paid = $payments->where('status', 'Réussi')->where('type', '!=', 'Commission')->sum('amount');
        $open = $disputes->where('status', '!=', 'Résolu')->count();

        return [
            ['label' => 'Nombre de chantiers', 'value' => (string) $sites->count(), 'icon' => 'construction', 'tone' => 'g', 'hint' => $sites->where('status', 'En cours')->count().' en cours'],
            ['label' => 'Besoins publiés', 'value' => (string) $offers->where('status', '!=', 'Brouillon')->count(), 'icon' => 'assignment', 'tone' => 'b', 'hint' => $offers->whereIn('status', ['Ouverte', 'Candidatures reçues'])->count().' ouverts aux candidatures'],
            ['label' => 'Montant total payé', 'value' => number_format($paid, 0, ',', ' '), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'a', 'hint' => $payments->where('status', 'Réussi')->count().' transactions réussies'],
            ['label' => 'Litiges ouverts', 'value' => (string) $open, 'icon' => 'gavel', 'tone' => $open ? 'r' : 'g', 'hint' => $disputes->count().' au total', 'highlight' => $open > 0],
        ];
    }

    /**
     * Alertes de la vue générale : pièces expirées ou manquantes, litiges ouverts, paiements à autoriser.
     *
     * @param  array<int, PromoterDocument>  $documents
     * @param  array<string, array<int, array<string, mixed>>>  $relations
     * @return array<int, array<string, mixed>>
     */
    private function alerts(Promoter $promoter, array $documents, array $relations, string $base): array
    {
        $alerts = [];

        foreach ($documents as $d) {
            if ($d->status === DocumentStatus::Expired) {
                $alerts[] = ['tone' => 'danger', 'icon' => 'event_busy', 'title' => $d->type->label().' expiré', 'text' => 'depuis le '.$d->expires_at?->translatedFormat('d M Y'), 'cta' => 'Voir le document', 'href' => "{$base}/kyc/{$d->id}"];
            }
        }
        foreach ($documents as $d) {
            if ($d->type->isRequired() && $d->status === DocumentStatus::Missing) {
                $alerts[] = ['tone' => 'warning', 'icon' => 'upload_file', 'title' => 'Document KYC manquant', 'text' => $d->type->label(), 'cta' => 'Ajouter', 'addDocument' => $d->type->value];
            }
        }

        $open = array_values(array_filter($relations['disputes'], fn (array $l): bool => $l['status'] !== 'Résolu'));
        if ($open) {
            $n = count($open);
            $alerts[] = ['tone' => 'warning', 'icon' => 'gavel', 'title' => $n.' litige'.($n > 1 ? 's ouverts' : ' ouvert'), 'text' => implode(', ', array_column($open, 'ref')), 'cta' => 'Voir les litiges', 'href' => "{$base}/litiges"];
        }

        $toAuthorize = array_values(array_filter($relations['payments'], fn (array $x): bool => $x['status'] === 'À autoriser'));
        if ($toAuthorize) {
            $n = count($toAuthorize);
            $alerts[] = ['tone' => 'info', 'icon' => 'payments', 'title' => $n.' paiement'.($n > 1 ? 's' : '').' à autoriser', 'text' => implode(', ', array_map(fn (array $x): string => $x['ref'].' · '.number_format($x['amount'], 0, ',', ' ').' FCFA', $toAuthorize)), 'cta' => 'Traiter', 'href' => "{$base}/paiements/{$toAuthorize[0]['ref']}"];
        }

        return $alerts;
    }

    /**
     * Panneau latéral : aperçu d’un document KYC ou fiche d’une offre.
     *
     * @param  array<int, PromoterDocument>  $documents
     * @param  array<string, array<int, array<string, mixed>>>  $relations
     * @return array<string, mixed>|null
     */
    private function drawer(Promoter $promoter, string $tab, string $item, array $documents, array $relations): ?array
    {
        if ($tab === 'kyc') {
            $document = collect($documents)->firstWhere('id', (int) $item);

            return $document ? ['kind' => 'document', 'document' => PromoterDocumentResource::make($document)->resolve()] : null;
        }

        if ($tab === 'offres') {
            $offer = collect($relations['offers'])->firstWhere('id', $item);
            if (! $offer) {
                return null;
            }
            $candidates = [['Koffi Adjoumani', 'Maçon · 8 ans', '96 %'], ['Mamadou Koné', 'Plombier · 6 ans', '92 %'], ['Issa Traoré', 'Électricien · 5 ans', '89 %']];

            return [
                'kind' => 'offer',
                'offer' => $offer,
                'candidates' => array_map(fn (array $c): array => ['name' => $c[0], 'detail' => $c[1], 'score' => $c[2]], array_slice($candidates, 0, min(3, $offer['applications']))),
            ];
        }

        return null;
    }

    /**
     * Fiche promoteur exportée en HTML.
     */
    public function sheet(Promoter $promoter): HttpResponse
    {
        $promoter->load('documents');
        $rows = [
            'Statut' => $promoter->status->label(), 'KYC' => $promoter->kycStatus()->label(), 'Type de compte' => $promoter->account_type->label(),
            'Forme juridique' => $promoter->legal_form, 'RCCM' => $promoter->rccm, 'NCC' => $promoter->ncc, 'Siège' => $promoter->head_office,
            'Téléphone' => $promoter->phone, 'E-mail' => $promoter->email, 'Chantiers' => count($promoter->demo('sites')),
            'Inscrit le' => $promoter->registered_at->translatedFormat('d M Y'),
        ];
        $promoter->logActivity('a exporté la fiche', null, 'download', 'neutral');

        $html = view('exports.promoter-sheet', ['promoter' => $promoter, 'rows' => $rows])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fiche-'.Str::slug($promoter->slug).'.html"',
        ]);
    }
}
