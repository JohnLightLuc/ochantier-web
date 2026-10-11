<?php

namespace App\Http\Controllers;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedUrgency;
use App\Enums\PromoterOfferProfileStatus;
use App\Http\Requests\NeedRequest;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\NeedOffer;
use App\Models\PromoterOffer;
use App\Models\PromoterOfferActivity;
use App\Models\PromoterOfferProfile;
use App\Support\AssignmentInsights;
use App\Support\MatchingEngine;
use App\Support\MatchingPresenter;
use App\Support\NeedInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Affectations & Offres : besoins dont des ouvriers ont été trouvés (à contacter puis valider)
 * et offres des promoteurs qui ont déjà choisi leurs ouvriers.
 */
class AssignmentController extends Controller
{
    /**
     * Ordre de traitement des ouvriers d’un besoin.
     *
     * @var array<int, NeedAssignmentStatus>
     */
    private const ASSIGNMENT_ORDER = [NeedAssignmentStatus::ToContact, NeedAssignmentStatus::Contacted, NeedAssignmentStatus::Validated, NeedAssignmentStatus::Refused, NeedAssignmentStatus::Replaced];

    public function index(Request $request): Response
    {
        $needs = AssignmentInsights::trackedNeeds();
        $offers = AssignmentInsights::promoterOffers();
        [$needFilter, $offerFilter] = $this->filters($request);

        $assignments = NeedAssignment::query()->get();
        $profiles = PromoterOfferProfile::query()->whereHas('offer', fn ($q) => $q->where('cancelled', false))->get();
        $countAssignments = fn (NeedAssignmentStatus ...$s): int => $assignments->whereIn('status', $s)->count();
        $countProfiles = fn (PromoterOfferProfileStatus ...$s): int => $profiles->whereIn('status', $s)->count();
        $toValidate = $countAssignments(NeedAssignmentStatus::ToContact, NeedAssignmentStatus::Contacted) + $countProfiles(PromoterOfferProfileStatus::Pending, PromoterOfferProfileStatus::Contacted);
        $replacements = $countAssignments(NeedAssignmentStatus::Refused) + $countProfiles(PromoterOfferProfileStatus::Refused, PromoterOfferProfileStatus::RefusedByWorker);

        return Inertia::render('Assignments/Index', [
            'kpis' => [
                ['label' => 'Total affectations', 'value' => (string) ($assignments->count() + $profiles->count()), 'icon' => 'event_note', 'tone' => 'g', 'hint' => 'Besoins et offres des promoteurs'],
                ['label' => 'À valider', 'value' => (string) $toValidate, 'icon' => 'schedule', 'tone' => 'a', 'hint' => 'Ouvriers à contacter ou à valider', 'highlight' => $toValidate > 0],
                ['label' => 'Validées', 'value' => (string) ($countAssignments(NeedAssignmentStatus::Validated) + $countProfiles(PromoterOfferProfileStatus::Accepted)), 'icon' => 'engineering', 'tone' => 'b', 'hint' => 'Ouvriers ayant confirmé'],
                ['label' => 'Remplacements', 'value' => (string) $replacements, 'icon' => 'swap_horiz', 'tone' => 'r', 'hint' => 'Refus ou désistements à remplacer'],
            ],
            'needs' => $needs->map(fn (Need $n): array => AssignmentInsights::needRow($n))->all(),
            'offers' => $offers->map(fn (PromoterOffer $o): array => AssignmentInsights::offerRow($o))->all(),
            'needFilters' => AssignmentInsights::NEED_FILTERS,
            'offerFilters' => AssignmentInsights::OFFER_FILTERS,
            'filters' => ['besoins' => $needFilter, 'q' => (string) $request->query('q', ''), 'offres' => $offerFilter, 'qo' => (string) $request->query('qo', '')],
            'options' => $this->options(),
            'openPosts' => $this->openPosts(),
            'refreshedAt' => now()->format('H:i'),
        ]);
    }

    /**
     * Filtres initiaux des deux listes (y compris les anciens libellés des liens du tableau de bord).
     *
     * @return array{0: string, 1: string}
     */
    private function filters(Request $request): array
    {
        $needFilter = (string) $request->query('filtre', 'Tous');
        $offerFilter = (string) $request->query('offres', 'Toutes');
        if (isset(AssignmentInsights::FILTER_ALIASES[$needFilter])) {
            [$list, $value] = AssignmentInsights::FILTER_ALIASES[$needFilter];
            [$needFilter, $offerFilter] = $list === 'besoins' ? [$value, $offerFilter] : ['Tous', $value];
        }

        return [
            in_array($needFilter, AssignmentInsights::NEED_FILTERS, true) ? $needFilter : 'Tous',
            in_array($offerFilter, AssignmentInsights::OFFER_FILTERS, true) ? $offerFilter : 'Toutes',
        ];
    }

    /**
     * Postes encore ouverts, proposés pour créer une affectation manuelle.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function openPosts(): array
    {
        return Need::with(NeedInsights::RELATIONS)->whereNull('manual_status')->orderBy('id')->get()
            ->flatMap(fn (Need $n) => $n->offers->filter(fn (NeedOffer $o): bool => ! $o->manual_status && $o->stats()['rest'] > 0)
                ->map(fn (NeedOffer $o): array => ['value' => "{$n->slug}:{$o->id}", 'label' => "{$n->reference} · {$n->site->name} · {$o->line->trade} · {$o->stats()['rest']} manquant(s)"]))
            ->values()->all();
    }

    /**
     * Export CSV des deux listes.
     */
    public function export(): StreamedResponse
    {
        $needs = AssignmentInsights::trackedNeeds()->map(fn (Need $n): array => AssignmentInsights::needRow($n));
        $offers = AssignmentInsights::promoterOffers()->map(fn (PromoterOffer $o): array => AssignmentInsights::offerRow($o));
        $date = fn (string $d): string => date('d/m/Y', strtotime($d));

        return response()->streamDownload(function () use ($needs, $offers, $date): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Type', 'Référence', 'Chantier', 'Promoteur', 'Métiers', 'Ouvriers', 'Suivi', 'Début', 'Étape', 'Paiement'], ';');
            foreach ($needs as $r) {
                fputcsv($out, ['Besoin', $r['reference'], $r['site'], $r['promoter'], $r['trades'], "{$r['selected']} / {$r['need']}", $r['steps'], $date($r['startsOn']), $r['status']['label'], $r['payment']], ';');
            }
            foreach ($offers as $r) {
                fputcsv($out, ['Offre du promoteur', $r['reference'], $r['site'], $r['promoter'], $r['trade'], "{$r['accepted']} / {$r['total']} validé(s)", $r['progress'], $date($r['sentOn']), $r['status']['label'], $r['canPay'] ? 'Acompte en attente' : '—'], ';');
            }
            fclose($out);
        }, 'affectations-'.Need::referenceDate()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Affectation d’un besoin : ouvriers trouvés, propositions du Matching, remplaçants, postes manquants et acompte.
     */
    public function need(Request $request, MatchingEngine $engine, Need $need): Response
    {
        $need->load(NeedInsights::RELATIONS);
        $need->loadMissing('site.promoter', 'assignments.worker');
        $insights = new NeedInsights;
        $c = $need->coverage();
        $status = $need->status();
        $open = ! $need->manual_status;
        $schedule = AssignmentInsights::schedule($need);
        $allValidated = $c['selected'] >= $c['need'] && $c['validated'] >= $c['need'];
        $children = AssignmentInsights::children($need->reference);

        return Inertia::render('Assignments/Need', [
            'need' => [
                'slug' => $need->slug,
                'reference' => $need->reference,
                'site' => ['name' => $need->site->name, 'slug' => $need->site->slug, 'type' => $need->site->type, 'commune' => $need->site->commune, 'city' => $need->site->city],
                'promoter' => $need->site->promoter_name,
                'promoterSlug' => $need->site->promoter?->slug,
                'urgency' => ['label' => 'Urgence '.mb_strtolower($need->urgency->label()), 'tone' => $need->urgency->tone()],
                'status' => NeedInsights::badge($status),
                'statusLabel' => $status,
                'createdOn' => $need->created_on->format('Y-m-d'),
                'startsOn' => $need->starts_on->format('Y-m-d'),
                'endsOn' => $need->ends_on->format('Y-m-d'),
                'contact' => $need->contact,
                'open' => $open,
                'depositPaid' => $need->deposit_paid,
                'paymentReference' => $need->payment_reference,
                'notifiedOn' => $need->notified_on?->format('Y-m-d'),
            ],
            'actionRef' => $insights->actionRef($need),
            'coverage' => $c,
            'assignments' => $this->assignmentRows($need),
            'proposals' => $open && $c['rest'] ? $this->proposals($engine, $need) : null,
            'replacement' => $open ? $this->replacement($request, $engine, $need) : null,
            'schedule' => $schedule,
            'progress' => $this->progress($need, $c, $schedule, $allValidated),
            'missing' => AssignmentInsights::missingRows($need, $children),
            'childrenCount' => $children->count(),
            'manualPosts' => $open ? $this->manualPosts($engine, $need) : [],
            'autoAdd' => $request->query('ajout'),
            'options' => $this->options(),
            'header' => $this->header('Affectation du besoin', '', [['Affectations & Offres', '/affectations'], [$need->reference]]),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignmentRows(Need $need): array
    {
        $offers = $need->offers->keyBy('id');

        return $need->assignments
            ->sortBy(fn (NeedAssignment $a): int => array_search($a->status, self::ASSIGNMENT_ORDER, true))
            ->map(function (NeedAssignment $a) use ($offers, $need): array {
                $line = $offers->get($a->need_offer_id)?->line;

                return [
                    'id' => $a->id,
                    'reference' => $a->reference,
                    'worker' => $a->worker_name,
                    'workerSlug' => $a->worker?->slug,
                    'post' => $a->trade.($line?->specialty ? " · {$line->specialty}" : ''),
                    'phone' => $a->phone,
                    'commune' => $a->commune,
                    'cost' => $a->daily_cost ?: $line?->daily_cost,
                    'source' => $a->source === 'Manuel' ? 'Manuel' : 'Matching',
                    'selectedOn' => $a->selected_on->format('Y-m-d'),
                    'contact' => match (true) {
                        (bool) $a->contacted_on => $a->contacted_on->format('d/m/Y').' · '.$a->channel,
                        $a->status === NeedAssignmentStatus::Refused => $a->reason ?: '—',
                        $a->status === NeedAssignmentStatus::Replaced => 'Remplacé par '.$a->replaced_by,
                        default => '—',
                    },
                    'contacted' => (bool) $a->contacted_on,
                    'replaces' => $a->replaces,
                    'status' => ['label' => $a->status->label(), 'tone' => $a->status->tone()],
                    'state' => $a->status->value,
                    'depositPaid' => $need->deposit_paid,
                ];
            })->values()->all();
    }

    /**
     * Propositions automatiques du Matching pour les postes manquants (même métier, un ouvrier par poste).
     *
     * @return array{rows: array<int, array<string, mixed>>, rest: int}
     */
    private function proposals(MatchingEngine $engine, Need $need): array
    {
        $presenter = new MatchingPresenter($engine);
        $picks = $engine->autoPicks();
        $rows = $need->offers
            ->map(fn (NeedOffer $o): ?NeedOffer => $engine->offer($o->id))
            ->filter(fn (?NeedOffer $o): bool => $o && $engine->isEligible($o))
            ->flatMap(fn (NeedOffer $o) => collect($picks[$o->id]['pick'] ?? [])->map(fn (array $x): array => [...$this->candidate($presenter, $o, $x), 'offerId' => $o->id]))
            ->values()->all();

        return ['rows' => $rows, 'rest' => $need->coverage()['rest']];
    }

    /**
     * Remplaçants proposés pour un ouvrier refusé : celui demandé (?remplacement=AFF-…) ou, à défaut, le premier refus
     * dont le poste n’a plus de proposition automatique (?remplacement=0 masque les suggestions).
     *
     * @return array{assignment: array{id: int, reference: string, name: string, trade: string}, rows: array<int, array<string, mixed>>}|null
     */
    private function replacement(Request $request, MatchingEngine $engine, Need $need): ?array
    {
        $asked = (string) $request->query('remplacement', '');
        if ($asked === '0') {
            return null;
        }
        $refused = $need->assignments->where('status', NeedAssignmentStatus::Refused);
        $picks = $engine->autoPicks();
        $target = $asked !== ''
            ? $refused->firstWhere('reference', $asked)
            : $refused->first(fn (NeedAssignment $a): bool => ($o = $engine->offer($a->need_offer_id)) && $o->stats()['rest'] > 0 && empty($picks[$o->id]['pick']));
        if (! $target) {
            return null;
        }

        $offer = $engine->offer($target->need_offer_id);
        $presenter = new MatchingPresenter($engine);

        return [
            'assignment' => ['id' => $target->id, 'reference' => $target->reference, 'name' => $target->worker_name, 'trade' => $offer->line->trade],
            'rows' => array_map(fn (array $x): array => $this->candidate($presenter, $offer, $x), $engine->replacements($offer)),
        ];
    }

    /**
     * Profil proposé par le Matching (ligne de tableau).
     *
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>
     */
    private function candidate(MatchingPresenter $presenter, NeedOffer $offer, array $x): array
    {
        return [
            'key' => $x['p']['key'],
            'matchId' => $x['id'],
            'name' => $x['p']['name'],
            'code' => $x['p']['id'],
            'workerSlug' => $x['p']['slug'],
            'trade' => $offer->line->trade,
            'commune' => $x['p']['commune'],
            'km' => $x['p']['km'],
            'experience' => $x['p']['experience'],
            'rating' => $x['p']['rating'],
            'cost' => $x['p']['cost'],
            'availability' => $x['p']['availability'],
            'score' => $x['sc']['score'],
            'level' => $presenter->level($x['sc']['level']),
        ];
    }

    /**
     * Postes ouverts et profils du métier pour un ajout manuel (du meilleur score au moins bon).
     *
     * @return array<int, array{offerId: int, label: string, rest: int, options: array<int, array{value: string, label: string}>}>
     */
    private function manualPosts(MatchingEngine $engine, Need $need): array
    {
        $money = fn (int $v): string => number_format($v, 0, ',', ' ');

        return $need->offers
            ->filter(fn (NeedOffer $o): bool => ! $o->manual_status && $o->stats()['rest'] > 0)
            ->map(function (NeedOffer $o) use ($engine, $money): array {
                $offer = $engine->offer($o->id);
                $taken = $offer->assignments->filter(fn (NeedAssignment $a): bool => $a->status->isSelected())->pluck('worker_name')->flip();
                $items = collect($engine->results($offer)['all'])->reject(fn (array $x): bool => $taken->has($x['p']['name']))->sortBy([fn (array $a, array $b): int => $a['sc']['blocked'] <=> $b['sc']['blocked'], fn (array $a, array $b): int => $b['sc']['raw'] <=> $a['sc']['raw']]);
                $stats = $offer->stats();

                return [
                    'offerId' => $o->id,
                    'label' => "{$o->line->trade} · {$stats['selected']} / {$o->line->quantity} sélectionné(s)",
                    'trade' => $o->line->trade,
                    'selected' => $stats['selected'],
                    'quantity' => $o->line->quantity,
                    'rest' => $stats['rest'],
                    'options' => $items->map(fn (array $x): array => [
                        'value' => $x['p']['key'],
                        'label' => "{$x['p']['name']} · {$x['p']['commune']} · {$x['p']['experience']} ans · {$money($x['p']['cost'])} FCFA/j · score {$x['sc']['score']}".($x['sc']['blocked'] ? ' · '.$x['sc']['blocking'][0]['criterion'] : ''),
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * Progression du besoin : sélection, contact, validation, notification, acompte et démarrage.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $schedule
     * @return array<int, array{label: string, sub: string, state: string}>
     */
    private function progress(Need $need, array $c, array $schedule, bool $allValidated): array
    {
        $started = $need->starts_on->lessThanOrEqualTo(Need::referenceDate());
        $paid = $need->deposit_paid && $allValidated;
        $step = fn (string $label, bool $done, bool $current, string $sub): array => ['label' => $label, 'sub' => $sub, 'state' => $done ? 'done' : ($current ? 'current' : 'todo')];

        return [
            $step('Ouvriers sélectionnés', $c['selected'] >= $c['need'], $c['selected'] > 0 && $c['selected'] < $c['need'], "{$c['selected']} / {$c['need']}"),
            $step('Ouvriers contactés', $c['toContact'] === 0 && $c['selected'] > 0, $c['toContact'] > 0, ($c['contacted'] + $c['validated'])." / {$c['selected']}"),
            $step('Matching validé par les ouvriers', $allValidated, $c['validated'] > 0 && ! $allValidated, "{$c['validated']} / {$c['need']}"),
            $step('Promoteur notifié automatiquement', $need->notified_on && $allValidated, false, $need->notified_on && $allValidated ? $need->notified_on->format('d/m/Y') : $need->site->promoter_name),
            $step('Acompte payé · demande validée', $paid, $allValidated && ! $need->deposit_paid, number_format($schedule['deposit'], 0, ',', ' ').' FCFA'),
            $step('Chantier en cours', $paid && $started, $paid && ! $started, 'Début '.$need->starts_on->format('d/m/Y')),
        ];
    }

    /**
     * Listes des fenêtres de saisie.
     *
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        return [
            'channels' => AssignmentInsights::CHANNELS,
            'declineReasons' => AssignmentInsights::DECLINE_REASONS,
            'refuseReasons' => AssignmentInsights::REFUSE_REASONS,
            'replacementReasons' => AssignmentInsights::REPLACEMENT_REASONS,
            'paymentMethods' => AssignmentInsights::PAYMENT_METHODS,
            'notificationChannels' => AssignmentInsights::NOTIFICATION_CHANNELS,
            'trades' => array_keys(NeedRequest::TRADE_DETAILS),
            'experiences' => NeedRequest::EXPERIENCES,
            'urgencies' => array_map(fn (NeedUrgency $u): string => $u->label(), NeedUrgency::cases()),
        ];
    }

    /**
     * Offre d’un promoteur : profils choisis, remplaçants proposés, acompte et historique.
     */
    public function offer(Request $request, PromoterOffer $promoterOffer): Response
    {
        $offer = $promoterOffer->load('site.promoter', 'profiles.worker', 'activities');
        $status = $offer->status();
        $k = $offer->counts();
        $open = $offer->isOpen();
        $deposit = $offer->deposit();
        $replacing = $open ? $this->offerReplacement($request, $offer) : null;
        $children = AssignmentInsights::children($offer->reference);

        return Inertia::render('Assignments/Offer', [
            'offer' => [
                'slug' => $offer->slug,
                'reference' => $offer->reference,
                'promoter' => $offer->site->promoter_name,
                'promoterSlug' => $offer->site->promoter?->slug,
                'site' => ['name' => $offer->site->name, 'slug' => $offer->site->slug, 'type' => $offer->site->type, 'commune' => $offer->site->commune, 'city' => $offer->site->city],
                'trade' => $offer->trade,
                'specialty' => $offer->specialty,
                'urgency' => ['label' => 'Urgence '.mb_strtolower($offer->urgency->label()), 'tone' => $offer->urgency->tone()],
                'status' => ['label' => $status, 'tone' => AssignmentInsights::OFFER_TONES[$status]],
                'statusLabel' => $status,
                'sentOn' => $offer->sent_on->format('Y-m-d'),
                'startsOn' => $offer->starts_on->format('Y-m-d'),
                'endsOn' => $offer->ends_on->format('Y-m-d'),
                'duration' => $offer->duration(),
                'dailyCost' => $offer->daily_cost,
                'message' => $offer->message,
                'open' => $open,
                'notifiedOn' => $offer->notified_on?->format('Y-m-d'),
                'paymentReference' => $offer->payment_reference,
            ],
            'counts' => $k,
            'deposit' => $deposit,
            'profiles' => $offer->profiles->map(fn (PromoterOfferProfile $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'workerSlug' => $p->worker?->slug,
                'trade' => $p->trade,
                'source' => $p->source,
                'commune' => $p->commune,
                'experience' => $p->experience,
                'rating' => $p->rating,
                'cost' => $p->daily_cost,
                'availability' => $p->availability,
                'contact' => $p->contacted_on ? $p->contacted_on->format('d/m/Y').' · '.$p->channel : '—',
                'contacted' => (bool) $p->contacted_on,
                'status' => ['label' => $p->status->displayLabel(), 'tone' => $p->status->tone()],
                'state' => $p->status->value,
                'detail' => match ($p->status) {
                    PromoterOfferProfileStatus::Accepted => $p->assignment_reference,
                    PromoterOfferProfileStatus::Replaced => 'Remplacé par '.$p->replaced_by,
                    default => $p->reason ?: '—',
                },
                'replaces' => $p->replaces,
            ])->all(),
            'replacement' => $replacing,
            'missing' => AssignmentInsights::missingRows(null, $children),
            'activities' => $offer->activities->take(6)->map(fn (PromoterOfferActivity $a): array => [
                'id' => $a->id,
                'date' => $a->occurred_at->format('Y-m-d'),
                'time' => $a->occurred_at->format('H:i'),
                'author' => $a->author,
                'action' => $a->action,
                'comment' => $a->comment,
                'badge' => ['label' => $a->new_value ?: $a->category->label(), 'tone' => AssignmentInsights::OFFER_TONES[$a->new_value] ?? 'n'],
            ])->values()->all(),
            'options' => $this->options(),
            'today' => Need::referenceDate()->format('Y-m-d'),
            'header' => $this->header('Offre du promoteur', '', [['Affectations & Offres', '/affectations'], [$offer->reference]]),
        ]);
    }

    /**
     * Remplaçants proposés pour un profil refusé : celui demandé (?remplacement=id) ou le premier refus non remplacé.
     *
     * @return array{profile: array{id: int, name: string, experience: int}, rows: array<int, array<string, mixed>>}|null
     */
    private function offerReplacement(Request $request, PromoterOffer $offer): ?array
    {
        $asked = (string) $request->query('remplacement', '');
        if ($asked === '0') {
            return null;
        }
        $refused = $offer->profiles->filter(fn (PromoterOfferProfile $p): bool => $p->status->isRefused() && ! $p->replaced_by);
        $target = $asked !== '' ? $refused->firstWhere('id', (int) $asked) : $refused->first();
        if (! $target) {
            return null;
        }

        return [
            'profile' => ['id' => $target->id, 'name' => $target->name, 'experience' => $target->experience],
            'rows' => array_map(fn (array $x): array => [
                'key' => $x['p']['key'],
                'name' => $x['p']['name'],
                'workerSlug' => $x['p']['slug'],
                'trade' => $x['p']['trade'],
                'commune' => $x['p']['commune'],
                'experience' => $x['p']['experience'],
                'rating' => $x['p']['rating'],
                'cost' => $x['p']['cost'],
                'availability' => $x['p']['availability'],
                'score' => $x['score'],
                'level' => ['label' => $x['level'], 'tone' => MatchingEngine::LEVEL_TONES[$x['level']] ?? 'n'],
            ], AssignmentInsights::promoterReplacements($offer, $target)),
        ];
    }
}
