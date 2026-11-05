<?php

namespace App\Support\Matching;

use App\Enums\ExclusionDecider;
use App\Enums\MatchingExclusionNature;
use App\Enums\SuggestionStatus;
use App\Models\Admin;
use App\Models\MatchingDerogation;
use App\Models\MatchingExclusion;
use App\Models\Suggestion;
use App\Support\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions d’un opérateur sur le classement : écarter un ouvrier d’un besoin, et lever une exclusion par dérogation.
 * Une dérogation demande l’accord d’une autre personne et ne vaut jamais pour une exclusion réglementaire. Elle prend
 * effet à la relance suivante.
 */
class MatchingDecisions
{
    public function exclude(Suggestion $suggestion, string $reason, Admin $admin, Request $request): void
    {
        if (! in_array($suggestion->status, [SuggestionStatus::Suggere, SuggestionStatus::Contacte, SuggestionStatus::Accepte], true)) {
            throw ValidationException::withMessages(['offer' => 'Cet ouvrier ne peut plus être écarté.']);
        }

        DB::transaction(function () use ($suggestion, $reason, $admin): void {
            $suggestion->forceFill(['status' => SuggestionStatus::Ecarte])->save();
            MatchingExclusion::query()->create([
                'need_id' => $suggestion->need_id,
                'worker_id' => $suggestion->worker_id,
                'matching_run_id' => $suggestion->matching_run_id,
                'nature' => MatchingExclusionNature::Manuelle,
                'criterion' => 'manuel',
                'comment' => $reason,
                'decided_by_type' => ExclusionDecider::Admin,
                'decided_by_admin_id' => $admin->getKey(),
            ]);
        });

        AdminAudit::record($admin, 'matching', 'matching.worker_excluded', $request, $suggestion, null, ['reason' => $reason]);
    }

    /**
     * Annule un écart manuel : l’ouvrier revient dans le classement à la relance.
     */
    public function lift(MatchingExclusion $exclusion, Admin $admin, Request $request): void
    {
        if ($exclusion->nature !== MatchingExclusionNature::Manuelle) {
            throw ValidationException::withMessages(['offer' => 'Seul un écart manuel peut être annulé.']);
        }

        DB::transaction(function () use ($exclusion): void {
            Suggestion::query()->where('need_id', $exclusion->need_id)->where('worker_id', $exclusion->worker_id)->where('status', SuggestionStatus::Ecarte)->update(['status' => SuggestionStatus::Expire]);
            MatchingExclusion::query()->where('need_id', $exclusion->need_id)->where('worker_id', $exclusion->worker_id)->where('nature', MatchingExclusionNature::Manuelle)->delete();
        });

        AdminAudit::record($admin, 'matching', 'matching.exclusion_lifted', $request, $exclusion->need, null, ['worker_id' => $exclusion->worker_id]);
    }

    public function request(MatchingExclusion $exclusion, string $justification, Admin $admin, Request $request): void
    {
        if ($exclusion->nature !== MatchingExclusionNature::Operationnelle) {
            throw ValidationException::withMessages(['offer' => 'Cette exclusion n’accepte pas de dérogation.']);
        }

        $blocked = MatchingExclusion::query()->where('matching_run_id', $exclusion->matching_run_id)->where('worker_id', $exclusion->worker_id)->where('nature', MatchingExclusionNature::Reglementaire)->exists();

        if ($blocked) {
            throw ValidationException::withMessages(['offer' => 'Une exclusion réglementaire empêche de retenir cet ouvrier.']);
        }

        $derogation = MatchingDerogation::query()->firstOrNew(['need_id' => $exclusion->need_id, 'worker_id' => $exclusion->worker_id, 'criterion' => $exclusion->criterion]);

        if ($derogation->exists && $derogation->revoked_at === null) {
            throw ValidationException::withMessages(['offer' => 'Une dérogation existe déjà pour ce critère.']);
        }

        $derogation->forceFill(['justification' => $justification, 'requested_by_admin_id' => $admin->getKey(), 'approved_by_admin_id' => null, 'approved_at' => null, 'revoked_at' => null])->save();
        AdminAudit::record($admin, 'matching', 'matching.derogation_requested', $request, $derogation, null, ['criterion' => $exclusion->criterion, 'reason' => $justification]);
    }

    public function approve(MatchingDerogation $derogation, Admin $admin, Request $request): void
    {
        $this->ensurePending($derogation);

        if ($derogation->requested_by_admin_id === $admin->getKey()) {
            throw ValidationException::withMessages(['offer' => 'Une autre personne doit approuver cette dérogation.']);
        }

        $derogation->forceFill(['approved_by_admin_id' => $admin->getKey(), 'approved_at' => now()])->save();
        AdminAudit::record($admin, 'matching', 'matching.derogation_approved', $request, $derogation, null, ['criterion' => $derogation->criterion]);
    }

    /**
     * Refuse une demande ou retire une dérogation accordée : l’exclusion s’applique de nouveau à la relance.
     */
    public function revoke(MatchingDerogation $derogation, Admin $admin, Request $request): void
    {
        if ($derogation->revoked_at !== null) {
            throw ValidationException::withMessages(['offer' => 'Cette dérogation est déjà retirée.']);
        }

        $derogation->forceFill(['revoked_at' => now()])->save();
        AdminAudit::record($admin, 'matching', 'matching.derogation_revoked', $request, $derogation, null, ['criterion' => $derogation->criterion]);
    }

    private function ensurePending(MatchingDerogation $derogation): void
    {
        if ($derogation->revoked_at !== null || $derogation->approved_at !== null) {
            throw ValidationException::withMessages(['offer' => 'Cette dérogation a déjà été traitée.']);
        }
    }
}
