<?php

namespace App\Support;

use App\Enums\ClaimStatus;
use App\Enums\Priority;
use App\Models\Assignment;
use App\Models\Claim;
use App\Models\CommercialProfile;
use App\Models\PromoterProfile;
use App\Models\Site;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dépôt d’une réclamation, depuis l’application Promoteur ou par le Back Office au nom d’un promoteur, d’un ouvrier ou
 * d’un commercial. La réclamation arrive « Ouverte », avec une première réponse attendue sous FIRST_ANSWER_DAYS jours.
 */
class ClaimFiling
{
    public const OPEN = [ClaimStatus::Ouverte, ClaimStatus::EnTraitement];

    public const FIRST_ANSWER_DAYS = 2;

    /**
     * @param  PromoterProfile|Worker|CommercialProfile  $claimant
     * @param  array{category: string, subject: string, description: string, priority?: string|null, site_id?: string|null, assignment_id?: string|null}  $data
     */
    public function file(Model $claimant, array $data): Claim
    {
        $assignment = isset($data['assignment_id']) ? Assignment::query()->with('mission')->find($data['assignment_id']) : null;
        $site = isset($data['site_id']) ? Site::query()->find($data['site_id']) : null;

        if ($assignment && $site && $assignment->mission->site_id !== $site->getKey()) {
            throw ValidationException::withMessages(['assignment_id' => 'Cet ouvrier n’est pas sur ce chantier.']);
        }

        $site ??= $assignment?->mission->site;

        if ($claimant instanceof PromoterProfile && $site && $site->promoter_profile_id !== $claimant->getKey()) {
            throw ValidationException::withMessages(['site_id' => 'Ce chantier n’appartient pas à ce promoteur.']);
        }

        $subject = trim($data['subject']);
        $duplicate = Claim::query()
            ->whereMorphedTo('claimant', $claimant)
            ->whereIn('status', self::OPEN)
            ->where('category', $data['category'])
            ->where('subject', $subject)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['subject' => 'Une réclamation identique est déjà en cours.']);
        }

        $other = $this->otherParty($claimant, $assignment, $site);

        return DB::transaction(fn (): Claim => Claim::query()->create([
            'reference' => sprintf('REC-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('claim_reference_seq')")),
            'claimant_type' => $claimant->getMorphClass(),
            'claimant_id' => $claimant->getKey(),
            'other_party_type' => $other?->getMorphClass(),
            'other_party_id' => $other?->getKey(),
            'site_id' => $site?->getKey(),
            'mission_id' => $assignment?->mission_id,
            'assignment_id' => $assignment?->getKey(),
            'category' => $data['category'],
            'subject' => $subject,
            'description' => trim($data['description']),
            'priority' => $data['priority'] ?? Priority::Normale,
            'due_at' => now()->addDays(self::FIRST_ANSWER_DAYS),
            'status' => ClaimStatus::Ouverte,
        ]));
    }

    private function otherParty(Model $claimant, ?Assignment $assignment, ?Site $site): ?Model
    {
        return match (true) {
            $claimant instanceof PromoterProfile => $assignment?->worker,
            $claimant instanceof Worker => $site?->promoterProfile,
            default => null,
        };
    }
}
