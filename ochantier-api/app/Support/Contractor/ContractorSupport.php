<?php

namespace App\Support\Contractor;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Contractor;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * « Assistance » de l’entrepreneur : demandes « Nous écrire » transmises au support O’CHANTIER (tickets), suivies
 * dans la liste des demandes.
 */
class ContractorSupport
{
    /**
     * Sujets proposés => catégorie du ticket.
     *
     * @var array<string, string>
     */
    public const SUBJECTS = [
        'Vérification du profil' => 'verification',
        'Paiement' => 'paiement',
        'Devis' => 'devis',
        'Chantier et équipe' => 'chantier',
        'Abonnement' => 'abonnement',
        'Autre' => 'autre',
    ];

    /**
     * Délai de première réponse du support (heures).
     */
    private const SLA_HOURS = 48;

    public function create(Contractor $contractor, User $user, string $subject, string $message, ?string $clientUuid = null): Ticket
    {
        if ($clientUuid && ($existing = Ticket::query()->where('client_uuid', $clientUuid)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($contractor, $user, $subject, $message, $clientUuid): Ticket {
            $ticket = Ticket::query()->create([
                'reference' => sprintf('TK-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('ticket_reference_seq')")),
                'requester_type' => $user->getMorphClass(),
                'requester_id' => $user->getKey(),
                'category' => self::SUBJECTS[$subject],
                'subject' => $subject.' · '.$contractor->name,
                'description' => $message,
                'priority' => in_array($subject, ['Paiement', 'Vérification du profil'], true) ? Priority::Haute : Priority::Normale,
                'status' => TicketStatus::Ouvert,
                'sla_due_at' => now()->addHours(self::SLA_HOURS),
                'related_type' => $contractor->getMorphClass(),
                'related_id' => $contractor->getKey(),
                'client_uuid' => $clientUuid,
            ]);
            $ticket->messages()->create(['author_type' => $user->getMorphClass(), 'author_id' => $user->getKey(), 'body' => $message]);

            return $ticket;
        });
    }

    /**
     * Demandes de l’utilisateur, les plus récentes d’abord.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(User $user): Collection
    {
        return Ticket::query()->where('requester_type', $user->getMorphClass())->where('requester_id', $user->getKey())->latest()->limit(50)->get()
            ->map(fn (Ticket $ticket) => $this->ticket($ticket));
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'description' => $ticket->description,
            'status' => ['value' => $ticket->status->value, 'label' => $ticket->status->label()],
            'created_at' => $ticket->created_at?->toIso8601String(),
            'answer_expected_before' => $ticket->sla_due_at?->toIso8601String(),
        ];
    }
}
