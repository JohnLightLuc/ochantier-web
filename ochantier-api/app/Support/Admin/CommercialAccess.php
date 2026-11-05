<?php

namespace App\Support\Admin;

use App\Enums\CommercialProfileStatus;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\CommercialProfile;
use App\Support\Auth\AuthSessions;
use App\Support\Commercial\CommercialNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur le compte d’un commercial : suspension et fin de contrat ferment ses sessions ;
 * ses ouvriers, ses prospects et ses gains sont conservés. La réactivation rouvre le compte dans les deux cas.
 */
class CommercialAccess
{
    public function __construct(private AuthSessions $sessions, private CommercialNotifier $notifier, private CommercialActivation $activation) {}

    public function suspend(CommercialProfile $commercial, string $status, Admin $admin, string $reason, Request $request): void
    {
        if ($status !== 'actif') {
            throw ValidationException::withMessages(['commercial' => 'Seul un compte actif peut être suspendu.']);
        }

        $user = $commercial->user;

        DB::transaction(function () use ($commercial, $user, $admin, $reason): void {
            $commercial->update(['status' => CommercialProfileStatus::Suspendu]);
            $user->forceFill(['status' => UserStatus::Suspendu, 'suspended_at' => now(), 'suspended_by_admin_id' => $admin->getKey(), 'suspended_reason' => $reason])->save();
            $this->sessions->revokeAll($user, 'suspended_by_admin');
            $this->activation->expire($user);
        });

        AdminAudit::record($admin, 'commercials', 'commercial.suspended', $request, $commercial, ['status' => $status], ['status' => 'suspendu', 'reason' => $reason]);
        $this->notifier->accountEvent($commercial, 'account.suspended', 'Votre compte est suspendu', $reason.' Contactez votre responsable O’CHANTIER.');
    }

    public function reactivate(CommercialProfile $commercial, string $status, Admin $admin, Request $request): void
    {
        if (! in_array($status, ['suspendu', 'termine'], true)) {
            throw ValidationException::withMessages(['commercial' => 'Ce compte n’est ni suspendu ni terminé.']);
        }

        DB::transaction(function () use ($commercial): void {
            $commercial->update(['status' => CommercialProfileStatus::Actif, 'ended_at' => null]);
            $user = $commercial->user;
            // Sans mot de passe, le compte attend toujours son activation.
            $user->forceFill(['status' => $user->password_hash ? UserStatus::Actif : UserStatus::EnAttenteActivation, 'suspended_at' => null, 'suspended_by_admin_id' => null, 'suspended_reason' => null])->save();
        });

        AdminAudit::record($admin, 'commercials', 'commercial.reactivated', $request, $commercial, ['status' => $status], ['status' => 'actif']);
        $this->notifier->accountEvent($commercial, 'account.reactivated', 'Votre compte est réactivé', 'Vous pouvez de nouveau utiliser l’application O’CHANTIER Commercial.');
    }

    /**
     * Fin de contrat : le compte est fermé, les ouvriers restent rattachés jusqu’à leur transfert.
     */
    public function terminate(CommercialProfile $commercial, string $status, Admin $admin, string $reason, Request $request): void
    {
        if ($status === 'termine') {
            throw ValidationException::withMessages(['commercial' => 'Ce contrat est déjà terminé.']);
        }

        $user = $commercial->user;

        DB::transaction(function () use ($commercial, $user): void {
            $commercial->update(['status' => CommercialProfileStatus::Termine, 'ended_at' => today()]);
            $user->forceFill(['status' => UserStatus::Desactive, 'suspended_at' => null, 'suspended_by_admin_id' => null, 'suspended_reason' => null])->save();
            $this->sessions->revokeAll($user, 'contract_ended');
            $this->activation->expire($user);
        });

        AdminAudit::record($admin, 'commercials', 'commercial.terminated', $request, $commercial, ['status' => $status], ['status' => 'termine', 'reason' => $reason]);
        $this->notifier->accountEvent($commercial, 'account.terminated', 'Votre contrat est terminé', 'Votre accès à l’application O’CHANTIER Commercial est fermé. Vos gains restent dus.');
    }
}
