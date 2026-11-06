<?php

namespace App\Support\Admin;

use App\Enums\ClaimStatus;
use App\Enums\Priority;
use App\Models\Admin;
use App\Models\Claim;
use App\Models\CommercialProfile;
use App\Models\PromoterProfile;
use App\Support\ClaimFiling;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Traitement d’une réclamation : prise en charge, priorité, résolution, rejet et clôture. Le demandeur est notifié
 * dans son application ; un ouvrier n’en a pas et doit être prévenu par l’équipe.
 */
class ClaimOperations
{
    public function __construct(private PromoterNotifier $promoters, private CommercialNotifier $commercials) {}

    /**
     * @return array{take: bool, priority: bool, resolve: bool, reject: bool, close: bool}
     */
    public function actions(Claim $claim): array
    {
        $open = in_array($claim->status, ClaimFiling::OPEN, true);

        return [
            'take' => $open,
            'priority' => $open,
            'resolve' => $open,
            'reject' => $open,
            'close' => in_array($claim->status, [ClaimStatus::Resolue, ClaimStatus::Rejetee], true),
        ];
    }

    /**
     * Le responsable devient l’administrateur qui prend le dossier ; un dossier déjà en traitement peut être repris.
     */
    public function take(Claim $claim, Admin $admin, Request $request): void
    {
        $this->change($claim, 'take', 'claims.taken', $admin, $request, null, ['status' => ClaimStatus::EnTraitement, 'owner_admin_id' => $admin->getKey()]);

        if ($claim->status === ClaimStatus::Ouverte) {
            $this->notifyClaimant($claim, 'Réclamation en cours de traitement', 'Votre réclamation '.$claim->reference.' est prise en charge.');
        }
    }

    public function prioritize(Claim $claim, Priority $priority, Admin $admin, Request $request): void
    {
        $this->change($claim, 'priority', 'claims.prioritized', $admin, $request, $priority->label(), ['priority' => $priority]);
    }

    public function resolve(Claim $claim, string $resolution, Admin $admin, Request $request): void
    {
        $this->change($claim, 'resolve', 'claims.resolved', $admin, $request, $resolution, ['status' => ClaimStatus::Resolue, 'resolution' => $resolution]);
        $this->notifyClaimant($claim, 'Réclamation résolue', 'Votre réclamation '.$claim->reference.' est résolue : '.$resolution);
    }

    public function reject(Claim $claim, string $reason, Admin $admin, Request $request): void
    {
        $this->change($claim, 'reject', 'claims.rejected', $admin, $request, $reason, ['status' => ClaimStatus::Rejetee, 'resolution' => $reason]);
        $this->notifyClaimant($claim, 'Réclamation rejetée', 'Votre réclamation '.$claim->reference.' est rejetée. Motif : '.$reason);
    }

    public function close(Claim $claim, Admin $admin, Request $request): void
    {
        $this->change($claim, 'close', 'claims.closed', $admin, $request, null, ['status' => ClaimStatus::Close]);
    }

    public function notifyClaimant(Claim $claim, string $title, string $body): void
    {
        $claimant = $claim->claimant;

        match (true) {
            $claimant instanceof PromoterProfile => $this->promoters->claimUpdated($claimant, $claim, $title, $body),
            $claimant instanceof CommercialProfile => $this->commercials->accountEvent($claimant, 'claim.updated', $title, $body),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function change(Claim $claim, string $action, string $event, Admin $admin, Request $request, ?string $reason, array $values): void
    {
        DB::transaction(function () use ($claim, $action, $event, $admin, $request, $reason, $values): void {
            $locked = Claim::query()->lockForUpdate()->findOrFail($claim->getKey());

            if (! $this->actions($locked)[$action]) {
                throw ValidationException::withMessages(['claim' => 'Cette réclamation n’est plus à traiter.']);
            }

            $before = ['status' => $locked->status->value];
            $locked->update($values);
            AdminAudit::record($admin, 'disputes', $event, $request, $locked, $before, ['status' => $locked->status->value, 'reason' => $reason]);
        });
    }
}
