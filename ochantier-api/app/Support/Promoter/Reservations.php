<?php

namespace App\Support\Promoter;

use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SelectionStatus;
use App\Enums\SiteStatus;
use App\Enums\SuggestionStatus;
use App\Enums\WorkerReservationStatus;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\ReasonCode;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Réservations du promoteur (« Réservations », « Proposition d’équipe ») : une réservation est une offre publiée sur
 * un de ses chantiers, avec les propositions d’équipe reçues.
 *
 * Accepter une proposition sélectionne les ouvriers et les bloque sur la période le temps du paiement de l’acompte ;
 * il manque des ouvriers (proposition partielle), la recherche continue pour eux dans un nouveau besoin. Refuser une
 * proposition écarte ses ouvriers et relance la recherche.
 */
class Reservations
{
    public const REFUSAL_CATEGORY = 'refus_proposition';

    /**
     * Onglets de « Réservations ».
     */
    public const FILTERS = ['recherche', 'a_valider', 'confirmees', 'refusees'];

    /**
     * @var array<string, string>
     */
    public const STATES = [
        'recherche' => 'En recherche',
        'a_valider' => 'À valider',
        'acompte' => 'Acompte à payer',
        'confirmee' => 'Confirmée',
        'annulee' => 'Annulée',
    ];

    public const WITH = ['site.mainImageMedia', 'site.commune', 'needs.trade', 'needs.requestedWorkers.worker', 'proposals.refusalReason'];

    public function __construct(private SitePublisher $publisher) {}

    /**
     * Réservations des chantiers du promoteur.
     *
     * @return Builder<Offer>
     */
    public function query(User $user): Builder
    {
        return Offer::query()->whereHas('site.promoterProfile', fn (Builder $query) => $query->where('user_id', $user->getKey()));
    }

    /**
     * Filtre d’onglet appliqué à la requête.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public function filter(Builder $query, string $filter): Builder
    {
        $pending = fn (Builder $query) => $query->whereIn('status', [ProposalStatus::AValider, ProposalStatus::Acceptee]);

        return match ($filter) {
            'a_valider' => $query->whereHas('proposals', $pending)->whereDoesntHave('needs.missions', fn (Builder $query) => $query->where('status', '!=', MissionStatus::EnAttenteAcompte)),
            'confirmees' => $query->whereHas('needs.missions', fn (Builder $query) => $query->whereIn('status', [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Terminee])),
            'refusees' => $query->whereHas('proposals', fn (Builder $query) => $query->where('status', ProposalStatus::Refusee)),
            default => $query->whereIn('status', [OfferStatus::Soumise, OfferStatus::EnControle, OfferStatus::Publiee])->whereDoesntHave('proposals', $pending),
        };
    }

    public function latestProposal(Offer $offer): ?Proposal
    {
        return $offer->proposals->sortByDesc('sequence')->first();
    }

    /**
     * État de la réservation : en recherche, proposition à valider, acompte à payer, confirmée, annulée.
     *
     * @return array{value: string, label: string}
     */
    public function state(Offer $offer): array
    {
        $proposal = $this->latestProposal($offer);
        $confirmed = $offer->needs->contains(fn (Need $need) => $need->missions()->whereIn('status', [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Terminee])->exists());

        $value = match (true) {
            $offer->status === OfferStatus::Annulee => 'annulee',
            $confirmed => 'confirmee',
            $proposal?->status === ProposalStatus::Acceptee => 'acompte',
            (bool) $proposal?->isPending() => 'a_valider',
            default => 'recherche',
        };

        return ['value' => $value, 'label' => self::STATES[$value]];
    }

    /**
     * Accepte la proposition (« Accepter et payer l’acompte ») : ouvriers sélectionnés et bloqués sur la période
     * jusqu’au paiement de l’acompte.
     */
    public function accept(Proposal $proposal, User $user): Proposal
    {
        $this->ensurePending($proposal);

        return DB::transaction(function () use ($proposal, $user): Proposal {
            $hold = now()->addHours((int) (Setting::query()->whereKey('reservations.hold_hours')->value('value') ?? 48));
            $suggestions = $proposal->suggestions()->with(['need', 'worker'])->get();

            foreach ($suggestions->groupBy('need_id') as $items) {
                /** @var Need $need */
                $need = $items->first()->need;

                foreach ($items as $suggestion) {
                    $this->select($suggestion, $need, $user, $hold);
                }

                if ($items->count() < $need->headcount) {
                    $this->continueSearch($need, $need->headcount - $items->count(), $user);
                    $need->forceFill(['headcount' => $items->count()]);
                }

                $this->moveNeed($need, NeedStatus::PaiementEnAttente, $user);
            }

            $proposal->forceFill(['status' => ProposalStatus::Acceptee, 'decided_at' => now(), 'decided_by_user_id' => $user->getKey()])->save();

            return $proposal->refresh();
        });
    }

    /**
     * Refuse la proposition avec un motif : ses ouvriers sont écartés et la recherche reprend.
     */
    public function refuse(Proposal $proposal, User $user, ReasonCode $reason, ?string $comment): Proposal
    {
        $this->ensurePending($proposal);

        return DB::transaction(function () use ($proposal, $user, $reason, $comment): Proposal {
            $suggestions = $proposal->suggestions()->with('need')->get();
            $proposal->suggestions()->update(['status' => SuggestionStatus::Ecarte]);

            foreach ($suggestions->pluck('need')->unique('id') as $need) {
                $this->moveNeed($need, NeedStatus::Matching, $user, 'Proposition refusée : '.$reason->label);
            }

            $proposal->forceFill([
                'status' => ProposalStatus::Refusee,
                'decided_at' => now(),
                'decided_by_user_id' => $user->getKey(),
                'refusal_reason_id' => $reason->getKey(),
                'refusal_comment' => $comment,
            ])->save();

            $site = $proposal->site;

            if ($site->status === SiteStatus::Paiement && ! $site->proposals()->whereKeyNot($proposal->getKey())->whereIn('status', [ProposalStatus::AValider, ProposalStatus::Acceptee])->exists()) {
                $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::Recherche, 'reason' => 'Proposition refusée', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
                $site->forceFill(['status' => SiteStatus::Recherche])->save();
            }

            return $proposal->refresh();
        });
    }

    /**
     * Nouvelle réservation sur un chantier publié (« + Réserver ») : une nouvelle offre est créée, sur les dates du
     * chantier s’il n’a pas commencé, sinon sur les dates de mission choisies.
     *
     * @param  array<string, mixed>  $data  Champs validés (ReservationRequest).
     */
    public function createAdditional(Site $site, User $user, array $data): Offer
    {
        if (in_array($site->status, [SiteStatus::Brouillon, SiteStatus::Termine, SiteStatus::Annule], true)) {
            throw ValidationException::withMessages(['site' => $site->status === SiteStatus::Brouillon
                ? 'Ce chantier est un brouillon : ajoutez les besoins dans le chantier puis publiez-le.'
                : 'Ce chantier est clôturé : il n’accepte plus de réservation.']);
        }

        return $this->publisher->publishAdditional($site, $user, $data);
    }

    private function ensurePending(Proposal $proposal): void
    {
        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => match ($proposal->status) {
                ProposalStatus::Acceptee => 'Vous avez déjà accepté cette proposition.',
                ProposalStatus::Refusee => 'Vous avez refusé cette proposition.',
                default => 'Cette proposition n’est plus valable : une nouvelle équipe vous sera proposée.',
            }]);
        }
    }

    private function select(Suggestion $suggestion, Need $need, User $user, \DateTimeInterface $hold): void
    {
        $selection = $need->selections()->create([
            'suggestion_id' => $suggestion->getKey(),
            'worker_id' => $suggestion->worker_id,
            'selected_by_user_id' => $user->getKey(),
            'status' => SelectionStatus::Reserve,
        ]);
        $suggestion->forceFill(['status' => SuggestionStatus::Selectionne])->save();

        try {
            DB::transaction(fn () => $need->workerReservations()->create([
                'worker_id' => $suggestion->worker_id,
                'selection_id' => $selection->getKey(),
                'period' => sprintf('[%s,%s]', $need->start_date->toDateString(), ($need->end_date ?? $need->start_date)->toDateString()),
                'expires_at' => $hold,
                'status' => WorkerReservationStatus::Active,
            ]));
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'worker_reservations_overlap_excl')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['proposal' => trim($suggestion->worker->first_name.' '.$suggestion->worker->last_name).' vient d’être réservé sur un autre chantier : une nouvelle proposition vous sera faite.']);
        }
    }

    /**
     * Proposition partielle : un besoin reprend la recherche pour les ouvriers manquants.
     */
    private function continueSearch(Need $need, int $missing, User $user): void
    {
        $copy = $need->replicate(['reference', 'client_uuid', 'submitted_at', 'published_at', 'assigned_admin_id']);
        $copy->forceFill([
            'reference' => sprintf('BS-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('need_reference_seq')")),
            'headcount' => $missing,
            'title' => $need->trade->label.($missing > 1 ? ' × '.$missing : ''),
            'status' => NeedStatus::Matching,
            'duplicated_from_need_id' => $need->getKey(),
            'submitted_at' => now(),
            'lock_version' => 1,
        ])->save();
        $copy->statusHistories()->create(['from_status' => null, 'to_status' => NeedStatus::Matching->value, 'reason' => 'Ouvriers manquants après une proposition partielle', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
    }

    private function moveNeed(Need $need, NeedStatus $status, User $user, ?string $reason = null): void
    {
        $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => $status->value, 'reason' => $reason, 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()]);
        $need->forceFill(['status' => $status])->save();
    }
}
