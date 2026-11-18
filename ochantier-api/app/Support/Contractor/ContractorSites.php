<?php

namespace App\Support\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\ContractorBidStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\SiteStatus;
use App\Enums\SiteStepStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Assignment;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Document;
use App\Models\Mission;
use App\Models\PaymentSchedule;
use App\Models\Site;
use App\Models\SiteStatusHistory;
use App\Models\SiteStep;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\SiteSteps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * « Mes chantiers » de l’entrepreneur : chantiers attribués (offre retenue, acompte payé), à venir, en cours ou
 * terminés. Aperçu, paiements du forfait (part nette de l’entrepreneur, hors commission O’CHANTIER), documents,
 * chronologie (étapes partagées avec le promoteur) et journal du chantier.
 */
class ContractorSites
{
    /**
     * Onglets de la liste.
     *
     * @var array<string, string>
     */
    public const TABS = ['tous' => 'Tous', 'a_venir' => 'À venir', 'en_cours' => 'En cours', 'termines' => 'Terminés'];

    /**
     * Contrat (offre retenue et mission du forfait) déjà lu, par entrepreneur et chantier.
     *
     * @var array<string, array{bid: ContractorBid, mission: Mission|null}>
     */
    private array $contracts = [];

    private const WITH = ['commune.city', 'neighborhood', 'mainImageMedia', 'promoterProfile.user', 'responsibleUser'];

    public function __construct(private SiteSteps $steps, private DocumentStore $documents, private MediaStore $media) {}

    /**
     * Chantiers attribués à l’entrepreneur.
     *
     * @return Builder<Site>
     */
    public function query(Contractor $contractor): Builder
    {
        return Site::query()->whereHas('offers.contractorBid', fn (Builder $query) => $query
            ->where('contractor_id', $contractor->getKey())
            ->where('status', ContractorBidStatus::Retenue->value));
    }

    public function find(Contractor $contractor, string $id): Site
    {
        $site = str($id)->isUuid() ? $this->query($contractor)->with(self::WITH)->find($id) : null;

        abort_unless($site, 404, 'Chantier non accessible : il ne vous est pas attribué.');

        return $site;
    }

    /**
     * Liste « Mes chantiers » : en cours, puis à venir, puis terminés ; meta : compteurs par onglet.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(Contractor $contractor, string $tab): array
    {
        $order = ['en_cours' => 0, 'a_venir' => 1, 'termine' => 2];
        $cards = $this->query($contractor)->with(self::WITH)->get()
            ->map(fn (Site $site) => $this->card($contractor, $site))
            ->sort(fn (array $a, array $b) => [$order[$a['stage']['value']], $a['start_date']] <=> [$order[$b['stage']['value']], $b['start_date']])
            ->values();
        $matches = fn (string $key) => fn (array $card) => $key === 'tous' || ($key === 'termines' ? 'termine' : $key) === $card['stage']['value'];

        return [
            'data' => $cards->filter($matches($tab))->values()->all(),
            'meta' => [
                'tab' => $tab,
                'tabs' => collect(self::TABS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'count' => $cards->filter($matches($key))->count()])->values()->all(),
            ],
        ];
    }

    /**
     * Carte de « Mes chantiers » : étape (à venir, en cours, terminé), compte à rebours ou avancement, montant et
     * équipe.
     *
     * @return array<string, mixed>
     */
    public function card(Contractor $contractor, Site $site): array
    {
        $contract = $this->contract($contractor, $site);
        $team = $this->team($site, $contract['mission']);

        return [
            'id' => $site->id,
            'reference' => $site->reference,
            'title' => $site->label,
            'place' => collect([$site->neighborhood?->name ?? $site->commune?->name, $site->commune?->city?->name])->filter()->unique()->implode(', '),
            'image_url' => $site->mainImageMedia ? $this->media->temporaryUrl($site->mainImageMedia) : null,
            'status' => ['value' => $site->status->value, 'label' => $site->status->label()],
            ...$this->progress($site, $contract['mission']),
            'amount' => $contract['bid']->amount,
            'team' => ['count' => $team->count(), 'label' => $team->isEmpty() ? 'Équipe à constituer' : $team->count().' ouvrier'.($team->count() > 1 ? 's' : '')],
        ];
    }

    /**
     * Onglet « Aperçu » : informations, promoteur, prochain paiement, suivi des paiements, équipe, chronologie et
     * derniers documents.
     *
     * @return array<string, mixed>
     */
    public function overview(Contractor $contractor, Site $site): array
    {
        $contract = $this->contract($contractor, $site);
        $payments = $this->payments($contractor, $site);
        $documents = $this->documentList($site);
        $team = $this->team($site, $contract['mission']);
        $user = $site->responsibleUser ?? $site->promoterProfile?->user;

        return [
            ...$this->card($contractor, $site),
            'offer_response_id' => $contract['bid']->id,
            'info' => [
                'description' => $site->description,
                'address' => $site->address,
                'location' => $site->location,
                'access_instructions' => $site->access_instructions,
                'contract_amount' => $contract['bid']->amount,
                'team_size' => $contract['bid']->team_size,
            ],
            'promoter' => [
                'role' => 'Promoteur · maître d’ouvrage',
                'name' => $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null,
                'phone' => $user ? PhoneNumber::of($user)?->formatted() : null,
            ],
            'next_payment' => $payments['next'],
            'payments_summary' => collect($payments)->only(['contract_amount', 'paid_amount', 'remaining_amount', 'paid_percent'])->all(),
            'team' => [
                'count' => $team->count(),
                'members' => $team->map(fn (Assignment $assignment) => [
                    'id' => $assignment->id,
                    'name' => trim($assignment->worker->first_name.' '.$assignment->worker->last_name),
                    'role' => $assignment->role_label,
                    'status' => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
                ])->values()->all(),
            ],
            'chronology' => collect($this->steps->summary($site))->except('steps')->all(),
            'documents' => ['count' => $documents->count(), 'latest' => $documents->take(3)->values()->all()],
        ];
    }

    /**
     * Onglet « Paiements » : échéances du forfait, avec la part nette de l’entrepreneur (au prorata du montant hors
     * commission), le prochain paiement et le cumul perçu.
     *
     * @return array<string, mixed>
     */
    public function payments(Contractor $contractor, Site $site): array
    {
        $contract = $this->contract($contractor, $site);
        $net = $contract['bid']->amount;
        $schedules = $contract['mission']?->paymentSchedules()->with('pricingSnapshot')->whereNotIn('status', [PaymentScheduleStatus::Annulee->value])->orderBy('due_date')->orderBy('sequence')->get() ?? collect();
        $shares = $this->shares($schedules, $net);
        $today = CarbonImmutable::today();
        $count = $schedules->count();

        $items = $schedules->values()->map(function (PaymentSchedule $schedule, int $index) use ($shares, $today, $net, $count): array {
            $paid = $schedule->status === PaymentScheduleStatus::Payee;
            $late = ! $paid && $schedule->due_date && $schedule->due_date->lt($today);
            $soon = ! $paid && ! $late && $schedule->due_date && $schedule->due_date->lte($today->addDays(7));

            return [
                'id' => $schedule->id,
                'sequence' => $index + 1,
                'count' => $count,
                'label' => $this->label($schedule),
                'due_date' => $schedule->due_date?->toDateString(),
                'amount' => $shares[$schedule->id],
                'percent' => $net > 0 ? round($shares[$schedule->id] * 100 / $net, 1) : 0,
                'state' => match (true) {
                    $paid => ['value' => 'paye', 'label' => 'Payé'],
                    $late => ['value' => 'en_retard', 'label' => 'En retard'],
                    $soon => ['value' => 'bientot', 'label' => 'Bientôt'],
                    default => ['value' => 'a_venir', 'label' => 'À venir'],
                },
            ];
        });

        $paid = (int) $items->where('state.value', 'paye')->sum('amount');
        $next = $items->first(fn (array $item) => $item['state']['value'] !== 'paye');

        return [
            'contract_amount' => $net,
            'paid_amount' => $paid,
            'remaining_amount' => $net - $paid,
            'paid_percent' => $net > 0 ? (int) round($paid * 100 / $net) : 0,
            'next' => $next ? [
                ...$next,
                'remaining_seconds' => $next['due_date'] ? max(0, (int) now()->diffInSeconds(CarbonImmutable::parse($next['due_date'])->endOfDay(), false)) : null,
                'late_text' => $next['state']['value'] === 'en_retard' ? 'Échéance dépassée depuis le '.CarbonImmutable::parse($next['due_date'])->format('d/m/Y').' — paiement en attente du promoteur' : null,
            ] : null,
            'settled' => $items->isNotEmpty() && $next === null,
            'items' => $items->all(),
            'note' => 'Montants nets, hors commission O’CHANTIER. Les échéances sont payées par le promoteur via O’CHANTIER.',
        ];
    }

    /**
     * Onglet « Documents » : pièces du chantier partagées avec l’entrepreneur, les plus récentes d’abord.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documentList(Site $site): Collection
    {
        return $this->documents->active($site)->map(fn (Document $document) => DocumentResource::make($document)->resolve())->values();
    }

    /**
     * Onglet « Chronologie » : étapes (modifiables par l’entrepreneur comme par le promoteur) et journal du chantier.
     *
     * @return array<string, mixed>
     */
    public function timeline(Site $site): array
    {
        $events = collect()
            ->concat($site->statusHistories()->get()->map(fn (SiteStatusHistory $history) => [
                'at' => $history->created_at,
                'title' => $this->statusEvent($history),
                'actor' => $this->actor($history->actor_type),
            ]))
            ->concat($site->steps()->get()->flatMap(fn (SiteStep $step) => array_filter([
                ['at' => $step->created_at, 'title' => 'Étape ajoutée : '.$step->title, 'actor' => null],
                $step->started_at ? ['at' => $step->started_at, 'title' => 'Étape démarrée : '.$step->title, 'actor' => null] : null,
                $step->status === SiteStepStatus::Validee ? ['at' => $step->completed_at, 'title' => 'Étape validée : '.$step->title, 'actor' => null] : null,
            ])))
            ->concat(PaymentSchedule::query()->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey()))->where('status', PaymentScheduleStatus::Payee->value)->get()
                ->map(fn (PaymentSchedule $schedule) => ['at' => $schedule->updated_at, 'title' => 'Paiement confirmé : '.$this->label($schedule), 'actor' => 'O’CHANTIER']))
            ->filter(fn (array $event) => $event['at'] !== null && $event['title'] !== null)
            ->sortByDesc('at')
            ->values()
            ->map(fn (array $event) => [...$event, 'at' => $event['at']->toIso8601String()]);

        return ['steps' => $this->steps->summary($site), 'journal' => $events->all()];
    }

    /**
     * Offre retenue et mission du forfait de l’entrepreneur sur ce chantier.
     *
     * @return array{bid: ContractorBid, mission: Mission|null}
     */
    private function contract(Contractor $contractor, Site $site): array
    {
        return $this->contracts[$contractor->getKey().':'.$site->getKey()] ??= (function () use ($contractor, $site): array {
            $bid = ContractorBid::query()->with('offer')
                ->where('contractor_id', $contractor->getKey())
                ->where('status', ContractorBidStatus::Retenue->value)
                ->whereHas('offer', fn (Builder $query) => $query->where('site_id', $site->getKey()))
                ->firstOrFail();

            $mission = Mission::query()->where('site_id', $site->getKey())
                ->whereHas('need', fn (Builder $query) => $query->where('offer_id', $bid->offer_id)->whereHas('trade', fn (Builder $query) => $query->where('code', ContractorOffers::TRADE)))
                ->first();

            return ['bid' => $bid, 'mission' => $mission];
        })();
    }

    /**
     * Étape du chantier, dates, compte à rebours avant le démarrage ou avancement « Jour 12 sur 30 ».
     *
     * @return array<string, mixed>
     */
    private function progress(Site $site, ?Mission $mission): array
    {
        $start = CarbonImmutable::parse($mission?->planned_start_date ?? $site->planned_start_date ?? today());
        $end = CarbonImmutable::parse($mission?->planned_end_date ?? $site->planned_end_date ?? $start);
        $today = CarbonImmutable::today();
        $total = max(1, (int) $start->diffInDays($end) + 1);
        $stage = match (true) {
            in_array($site->status, [SiteStatus::Termine, SiteStatus::Annule], true) => ['value' => 'termine', 'label' => 'Terminé'],
            $site->status === SiteStatus::Confirme && $start->gt($today) => ['value' => 'a_venir', 'label' => 'À venir'],
            default => ['value' => 'en_cours', 'label' => 'En cours'],
        };
        $day = min($total, max(1, (int) $start->diffInDays($today) + 1));

        return [
            'stage' => $stage,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'duration_days' => $total,
            'countdown_seconds' => $stage['value'] === 'a_venir' ? (int) now()->diffInSeconds($start, false) : null,
            'day' => $stage['value'] === 'en_cours' ? $day : null,
            'progress_percent' => match ($stage['value']) {
                'termine' => 100,
                'a_venir' => 0,
                default => (int) round($day * 100 / $total),
            },
            'progress_label' => match ($stage['value']) {
                'termine' => 'Terminé le '.$end->format('d/m/Y'),
                'a_venir' => 'Démarrage le '.$start->format('d/m/Y').' · '.$total.' jours prévus',
                default => 'Jour '.$day.' sur '.$total.' · fin le '.$end->format('d/m/Y'),
            },
        ];
    }

    /**
     * Ouvriers affectés par l’entrepreneur sur le chantier (hors forfait), en cours ou à venir.
     *
     * @return Collection<int, Assignment>
     */
    private function team(Site $site, ?Mission $forfait): Collection
    {
        return Assignment::query()->with('worker')
            ->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey()))
            ->when($forfait, fn (Builder $query) => $query->where('mission_id', '!=', $forfait->getKey()))
            ->whereIn('status', [AssignmentStatus::EnAttente->value, AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value])
            ->get();
    }

    /**
     * Part nette de chaque échéance, au prorata du montant hors commission ; la dernière absorbe les arrondis pour
     * que le total corresponde au contrat.
     *
     * @param  Collection<int, PaymentSchedule>  $schedules
     * @return array<string, int>
     */
    private function shares(Collection $schedules, int $net): array
    {
        $total = (int) $schedules->sum('amount_due');
        $shares = [];
        $sum = 0;

        foreach ($schedules->values() as $index => $schedule) {
            $share = $index === $schedules->count() - 1 ? $net - $sum : ($total > 0 ? (int) round($schedule->amount_due * $net / $total) : 0);
            $shares[$schedule->id] = $share;
            $sum += $share;
        }

        return $shares;
    }

    private function label(PaymentSchedule $schedule): string
    {
        return match ($schedule->schedule_type) {
            PaymentScheduleType::Acompte => 'Acompte à l’attribution',
            PaymentScheduleType::Hebdomadaire => 'Semaine '.$schedule->sequence,
            PaymentScheduleType::Jalon => 'Étape '.$schedule->sequence,
            PaymentScheduleType::Solde => 'Solde à la réception',
            default => $schedule->schedule_type->label().' '.$schedule->sequence,
        };
    }

    private function statusEvent(SiteStatusHistory $history): ?string
    {
        return match ($history->to_status) {
            SiteStatus::Confirme => 'Acompte confirmé — chantier attribué',
            SiteStatus::EnCours => 'Chantier démarré'.($history->reason ? ' ('.mb_strtolower($history->reason).')' : ''),
            SiteStatus::Suspendu => 'Chantier suspendu'.($history->reason ? ' : '.$history->reason : ''),
            SiteStatus::Termine => 'Chantier terminé',
            SiteStatus::Annule => 'Chantier annulé'.($history->reason ? ' : '.$history->reason : ''),
            default => null,
        };
    }

    private function actor(?string $type): ?string
    {
        return match ($type) {
            'user' => 'Promoteur',
            'admin' => 'O’CHANTIER',
            'contractor' => 'Entrepreneur',
            default => null,
        };
    }
}
