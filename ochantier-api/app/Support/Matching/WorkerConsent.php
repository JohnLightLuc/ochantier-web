<?php

namespace App\Support\Matching;

use App\Enums\ContactChannel;
use App\Enums\ContactStatus;
use App\Enums\NeedStatus;
use App\Enums\ResponseRecorder;
use App\Enums\SuggestionStatus;
use App\Enums\WorkerResponseDecision;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Suggestion;
use App\Models\WorkerOfferContact;
use App\Support\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accord des ouvriers avant toute proposition : l’opérateur note le contact, puis la réponse recueillie (date, auteur,
 * tarif net annoncé). Seuls les ouvriers d’accord partent au promoteur.
 */
class WorkerConsent
{
    private const OPEN = [SuggestionStatus::Suggere, SuggestionStatus::Contacte, SuggestionStatus::Accepte, SuggestionStatus::Refuse, SuggestionStatus::Indisponible];

    public function __construct(private ProposalPublisher $publisher, private MatchingRunner $runner, private WorkerOverlap $overlap) {}

    public function contact(Suggestion $suggestion, ContactChannel $channel, Admin $admin, Request $request): void
    {
        $this->ensureOpen($suggestion);

        DB::transaction(function () use ($suggestion, $channel, $admin): void {
            $this->openContact($suggestion, $channel, $admin);
            $suggestion->forceFill(['status' => SuggestionStatus::Contacte])->save();
            $this->moveNeed($suggestion->need, $admin);
        });

        AdminAudit::record($admin, 'matching', 'matching.worker_contacted', $request, $suggestion, null, ['channel' => $channel->value]);
    }

    public function respond(Suggestion $suggestion, ContactChannel $channel, WorkerResponseDecision $decision, ?string $conditions, Admin $admin, Request $request): void
    {
        $this->ensureOpen($suggestion);

        if ($decision === WorkerResponseDecision::Accepte && ($other = $this->overlap->retainedElsewhere($suggestion))) {
            throw ValidationException::withMessages(['offer' => trim($suggestion->worker->first_name.' '.$suggestion->worker->last_name).' est déjà retenu sur le poste '.$this->overlap->postLabel($other->need).' de ce chantier, aux mêmes dates. Un ouvrier ne peut tenir qu’un poste à la fois.']);
        }

        DB::transaction(function () use ($suggestion, $channel, $decision, $conditions, $admin): void {
            $contact = $suggestion->workerOfferContacts()->where('status', ContactStatus::EnAttente)->latest('contacted_at')->first() ?? $this->openContact($suggestion, $channel, $admin);
            $contact->update(['status' => ContactStatus::Repondu]);

            $suggestion->workerResponses()->create([
                'contact_id' => $contact->getKey(),
                'worker_id' => $suggestion->worker_id,
                'decision' => $decision,
                'conditions' => $conditions,
                'net_daily_rate_shown' => $suggestion->net_daily_rate,
                'recorded_by_type' => ResponseRecorder::Admin,
                'recorded_by_id' => $admin->getKey(),
            ]);

            $suggestion->forceFill(['status' => match ($decision) {
                WorkerResponseDecision::Accepte => SuggestionStatus::Accepte,
                WorkerResponseDecision::Refuse => SuggestionStatus::Refuse,
                WorkerResponseDecision::Indisponible => SuggestionStatus::Indisponible,
            }])->save();
            $this->moveNeed($suggestion->need, $admin);
        });

        AdminAudit::record($admin, 'matching', 'matching.worker_answered', $request, $suggestion, null, ['decision' => $decision->value, 'net_daily_rate' => $suggestion->net_daily_rate]);
    }

    /**
     * Ouvriers d’accord retenus, poste par poste (par référence du besoin), par rang : les suivants restent en réserve.
     * Un ouvrier d’accord sur plusieurs postes aux dates qui se chevauchent n’est retenu que sur celui où il a donné
     * son accord en premier ; l’ouvrier d’accord suivant prend l’autre poste.
     *
     * @return Collection<int, Suggestion>
     */
    public function retained(Offer $offer): Collection
    {
        $needs = $offer->needs()->whereIn('status', MatchingRunner::OPEN_NEEDS)->orderBy('reference')->get();
        $accepted = Suggestion::query()
            ->with(['need', 'workerResponses'])
            ->whereIn('need_id', $needs->modelKeys())
            ->where('status', SuggestionStatus::Accepte)
            ->whereHas('worker', fn ($query) => $query->where('verification_status', WorkerVerificationStatus::Valide))
            ->get();
        $agreedAt = fn (Suggestion $suggestion) => $suggestion->workerResponses->where('decision', WorkerResponseDecision::Accepte)->min('responded_at') ?? $suggestion->updated_at;
        $secondPost = fn (Suggestion $suggestion) => $accepted->contains(fn (Suggestion $other) => $other->worker_id === $suggestion->worker_id
            && $other->need_id !== $suggestion->need_id
            && $this->overlap->overlaps($other->need, $suggestion->need)
            && [$agreedAt($other), $other->id] < [$agreedAt($suggestion), $suggestion->id]);

        /** @var Collection<int, Suggestion> $retained */
        $retained = collect();

        foreach ($needs as $need) {
            $retained = $retained->concat($accepted->where('need_id', $need->getKey())
                ->reject($secondPost)
                ->sortBy([['rank', 'asc'], ['score', 'desc']])
                ->take($need->headcount));
        }

        return $retained->values();
    }

    public function send(Offer $offer, Admin $admin, Request $request): void
    {
        $this->publisher->expireOverdue($offer);

        if ($refusal = $this->runner->refusal($offer->fresh())) {
            throw ValidationException::withMessages(['offer' => $refusal]);
        }

        $suggestions = $this->retained($offer);

        if ($suggestions->isEmpty()) {
            throw ValidationException::withMessages(['offer' => 'Aucun ouvrier n’a encore donné son accord.']);
        }

        $proposal = $this->publisher->publish($offer, $suggestions, $admin, 'Proposition envoyée par le Back Office');
        AdminAudit::record($admin, 'matching', 'matching.proposal_sent', $request, $offer, null, ['proposal' => $proposal->reference, 'proposed' => $proposal->proposed_count, 'requested' => $proposal->requested_count]);
    }

    private function openContact(Suggestion $suggestion, ContactChannel $channel, Admin $admin): WorkerOfferContact
    {
        return $suggestion->workerOfferContacts()->create([
            'worker_id' => $suggestion->worker_id,
            'commercial_id' => $suggestion->worker->current_commercial_id,
            'channel' => $channel,
            'contacted_by_type' => $admin->getMorphClass(),
            'contacted_by_id' => $admin->getKey(),
            'status' => ContactStatus::EnAttente,
        ]);
    }

    private function moveNeed(Need $need, Admin $admin): void
    {
        if ($need->status === NeedStatus::Matching) {
            $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::ContactOuvriers->value, 'reason' => 'Contact des ouvriers', 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $need->forceFill(['status' => NeedStatus::ContactOuvriers])->save();
        }
    }

    private function ensureOpen(Suggestion $suggestion): void
    {
        if (! in_array($suggestion->status, self::OPEN, true)) {
            throw ValidationException::withMessages(['offer' => 'Cet ouvrier a déjà été proposé ou n’est plus en lice.']);
        }
    }
}
