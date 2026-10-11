<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentStatus;
use App\Http\Requests\SalesAgentRequest;
use App\Models\SalesAgent;
use App\Support\SalesPerformance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Compte du commercial : invitation et lien d’activation, cycle de vie, rôle, autorisations et sécurité.
 */
class SalesAgentAccountController extends Controller
{
    public const SUSPENSION_REASONS = ['Profils frauduleux ou dupliqués', 'Inactivité prolongée', 'Comportement inapproprié', 'Demande du commercial', 'Autre'];

    public const DEACTIVATION_REASONS = ['Fin de contrat', 'Démission', 'Faute grave', 'Autre'];

    public const DELETION_REASONS = ['Compte créé par erreur', 'Doublon', 'Fin de collaboration', 'Demande du commercial (RGPD)', 'Autre'];

    /**
     * Envoie ou renvoie le lien d’activation (valable 72 heures).
     */
    public function invite(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->isPreActivation(), 422, 'Ce compte est déjà activé. Utilisez « Réinitialiser l’accès ».');

        $old = $salesAgent->invitation_status->label();
        $first = $salesAgent->status === SalesAgentStatus::Draft;
        $this->sendLink($salesAgent);
        $salesAgent->logActivity(SalesAgentActivityCategory::Invitation, $first ? 'a envoyé l’invitation' : 'a renvoyé l’invitation', $old, InvitationStatus::Sent->label(), $salesAgent->email);

        Inertia::flash('toast', "Invitation envoyée à {$salesAgent->email}.");

        return back();
    }

    /**
     * Journalise la copie du lien ; le lien lui-même est construit côté navigateur.
     */
    public function copyLink(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->access['expires_at'] && ! in_array($salesAgent->invitation_status, [InvitationStatus::Revoked, InvitationStatus::Expired], true), 422, 'Aucun lien valide : générez un nouveau lien.');

        $salesAgent->logActivity(SalesAgentActivityCategory::Invitation, 'a copié le lien d’activation', null, null, 'Lien à usage unique');
        Inertia::flash('toast', 'Lien d’activation copié (valable jusqu’au '.Carbon::parse($salesAgent->access['expires_at'])->translatedFormat('d M Y').').');

        return back();
    }

    public function revokeLink(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->isPreActivation(), 422);

        $old = $salesAgent->invitation_status->label();
        $salesAgent->update(['invitation_status' => InvitationStatus::Revoked, 'access' => [...$salesAgent->access, 'expires_at' => null]]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Invitation, 'a révoqué le lien d’activation', $old, InvitationStatus::Revoked->label());
        Inertia::flash('toast', 'Lien révoqué.');

        return back();
    }

    public function newLink(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->isPreActivation(), 422);

        $old = $salesAgent->invitation_status->label();
        $this->sendLink($salesAgent);
        $salesAgent->logActivity(SalesAgentActivityCategory::Invitation, 'a généré un nouveau lien', $old, InvitationStatus::Sent->label(), $salesAgent->email);
        Inertia::flash('toast', 'Nouveau lien envoyé.');

        return back();
    }

    /**
     * L’adresse e-mail n’est modifiable qu’avant l’activation ; un nouveau lien est envoyé.
     */
    public function updateEmail(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->isPreActivation(), 422, 'L’adresse ne peut plus être modifiée après l’activation.');

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $email = $request->validate([
            'email' => ['required', 'email', Rule::unique('sales_agents', 'email')->ignore($salesAgent->id)],
        ], ['email.required' => 'Ce champ est obligatoire.', 'email.email' => 'Adresse e-mail invalide.', 'email.unique' => 'Adresse déjà utilisée par un autre commercial.'])['email'];

        $old = $salesAgent->email;
        $salesAgent->update(['email' => $email]);
        $this->sendLink($salesAgent);
        $salesAgent->logActivity(SalesAgentActivityCategory::Update, 'a modifié l’adresse e-mail', $old, $email);
        Inertia::flash('toast', 'Adresse modifiée · invitation renvoyée.');

        return back();
    }

    private function sendLink(SalesAgent $agent): void
    {
        $agent->update([
            'status' => $agent->status === SalesAgentStatus::Draft ? SalesAgentStatus::Invited : $agent->status,
            'invitation_status' => InvitationStatus::Sent,
            'access' => [...$agent->access, 'sent_at' => today()->format('Y-m-d'), 'expires_at' => today()->addDays(3)->format('Y-m-d')],
        ]);
        Log::info("Lien d’activation envoyé à {$agent->email} ({$agent->code}).");
    }

    /**
     * Activation manuelle : le commercial a créé son mot de passe mais l’activation n’a pas été confirmée.
     */
    public function activate(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status->isPreActivation(), 422, 'Ce compte est déjà activé.');

        $old = $salesAgent->status->label();
        $salesAgent->update([
            'status' => SalesAgentStatus::Active,
            'invitation_status' => InvitationStatus::Activated,
            'last_activity_on' => today(),
            'access' => [...$salesAgent->access, 'activated_at' => today()->format('Y-m-d'), 'expires_at' => null],
        ]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Activation, 'a activé le compte', $old, SalesAgentStatus::Active->label());
        Inertia::flash('toast', 'Compte activé.');

        return back();
    }

    /**
     * Envoie un lien de réinitialisation du mot de passe et ferme toutes les sessions.
     */
    public function resetAccess(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status === SalesAgentStatus::Active, 422, 'Le compte doit être actif pour réinitialiser l’accès.');

        $salesAgent->updateAccess(['sessions' => []]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Security, 'a lancé une réinitialisation du mot de passe', null, 'Lien envoyé', $salesAgent->email);
        Inertia::flash('toast', "Lien de réinitialisation envoyé à {$salesAgent->email}.");

        return back();
    }

    public function suspend(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless($salesAgent->status === SalesAgentStatus::Active, 422, 'Seul un compte actif peut être suspendu.');

        $data = $request->validate([
            'category' => ['required', Rule::in(self::SUSPENSION_REASONS)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['category.required' => 'Ce champ est obligatoire.', 'reason.*' => 'Ce champ est obligatoire (5 caractères minimum).']);

        $reason = "{$data['category']} — ".trim($data['reason']);
        $salesAgent->update([
            'status' => SalesAgentStatus::Suspended,
            'previous_availability' => $salesAgent->availability,
            'availability' => SalesAgentAvailability::Unavailable,
            'suspension_reason' => $reason,
            'ranking_until' => SalesPerformance::previousMonth(SalesPerformance::currentMonth()),
            'access' => [...$salesAgent->access, 'sessions' => []],
        ]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Suspension, 'a suspendu le compte', SalesAgentStatus::Active->label(), SalesAgentStatus::Suspended->label(), $reason);
        Inertia::flash('toast', "{$salesAgent->name} suspendu.");

        return back();
    }

    public function reactivate(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless(in_array($salesAgent->status, [SalesAgentStatus::Suspended, SalesAgentStatus::Deactivated], true), 422, 'Ce compte n’est ni suspendu ni désactivé.');

        $old = $salesAgent->status->label();
        $previous = $salesAgent->previous_availability;
        $salesAgent->update([
            'status' => SalesAgentStatus::Active,
            'availability' => $previous && $previous !== SalesAgentAvailability::Unavailable ? $previous : SalesAgentAvailability::Available,
            'previous_availability' => null,
            'suspension_reason' => null,
            'ranking_until' => null,
        ]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Reactivation, 'a réactivé le compte', $old, SalesAgentStatus::Active->label());
        Inertia::flash('toast', "{$salesAgent->name} réactivé.");

        return back();
    }

    public function deactivate(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if(in_array($salesAgent->status, [SalesAgentStatus::Deactivated, SalesAgentStatus::Archived], true), 422, 'Ce compte est déjà fermé.');

        $data = $request->validate([
            'category' => ['required', Rule::in(self::DEACTIVATION_REASONS)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['category.required' => 'Ce champ est obligatoire.', 'reason.*' => 'Ce champ est obligatoire (5 caractères minimum).']);

        $old = $salesAgent->status->label();
        $salesAgent->update([
            'status' => SalesAgentStatus::Deactivated,
            'availability' => SalesAgentAvailability::Unavailable,
            'ranking_until' => $salesAgent->ranking_until ?? SalesPerformance::previousMonth(SalesPerformance::currentMonth()),
            'access' => [...$salesAgent->access, 'sessions' => []],
        ]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Status, 'a désactivé le compte', $old, SalesAgentStatus::Deactivated->label(), "{$data['category']} — ".trim($data['reason']));
        Inertia::flash('toast', 'Compte désactivé.');

        return back();
    }

    public function archive(SalesAgent $salesAgent): RedirectResponse
    {
        abort_unless(in_array($salesAgent->status, [SalesAgentStatus::Suspended, SalesAgentStatus::Deactivated], true), 422, 'Seul un compte suspendu ou désactivé peut être archivé.');

        $old = $salesAgent->status->label();
        $salesAgent->update([
            'status' => SalesAgentStatus::Archived,
            'availability' => SalesAgentAvailability::Unavailable,
            'ranking_until' => $salesAgent->ranking_until ?? SalesPerformance::previousMonth(SalesPerformance::currentMonth()),
        ]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Status, 'a archivé le commercial', $old, SalesAgentStatus::Archived->label());
        Inertia::flash('toast', 'Commercial archivé.');

        return back();
    }

    /**
     * Suppression définitive, confirmée par la saisie de l’identifiant. Les ouvriers enregistrés sont conservés.
     */
    public function destroy(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(self::DELETION_REASONS)],
            'comment' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($salesAgent): void {
                if (mb_strtoupper(trim((string) $value)) !== $salesAgent->code) {
                    $fail('L’identifiant ne correspond pas.');
                }
            }],
        ], ['category.required' => 'Ce champ est obligatoire.', 'comment.*' => 'Ce champ est obligatoire (10 caractères minimum).', 'confirm.required' => 'Ce champ est obligatoire.']);

        Log::notice("Compte commercial supprimé : {$salesAgent->name} ({$salesAgent->code}) · {$data['category']} · {$data['comment']} · par ".($request->user()?->name ?? 'Système'));
        $name = $salesAgent->name;
        $salesAgent->delete();

        Inertia::flash('toast', "Compte de {$name} supprimé.");

        return to_route('sales-agents.index');
    }

    /**
     * Accorde ou retire une autorisation de l’Application Commercial.
     */
    public function togglePermission(SalesAgent $salesAgent, string $permission): RedirectResponse
    {
        abort_unless(array_key_exists($permission, SalesAgentRequest::PERMISSIONS), 404);
        abort_if($salesAgent->isArchived(), 403);

        $permissions = $salesAgent->access['permissions'];
        $on = (bool) ($permissions[$permission] ?? false);
        $label = SalesAgentRequest::PERMISSIONS[$permission];
        $salesAgent->updateAccess(['permissions' => [...$permissions, $permission => ! $on]]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Permission, 'a modifié une permission', $label.($on ? ' : oui' : ' : non'), $label.($on ? ' : non' : ' : oui'));
        Inertia::flash('toast', 'Permission mise à jour.');

        return back();
    }

    public function updateRole(Request $request, SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $data = $request->validate([
            'role' => ['required', Rule::in(SalesAgentRequest::ROLES)],
            'level' => ['required', Rule::in(SalesAgentRequest::LEVELS)],
            'duration' => ['required', Rule::in(SalesAgentRequest::DURATIONS)],
        ], ['*.required' => 'Ce champ est obligatoire.']);

        $old = "{$salesAgent->access['role']} · {$salesAgent->access['level']}";
        $salesAgent->updateAccess($data);
        $salesAgent->logActivity(SalesAgentActivityCategory::Permission, 'a modifié le rôle', $old, "{$data['role']} · {$data['level']}");
        Inertia::flash('toast', 'Rôle mis à jour.');

        return back();
    }

    public function toggleTwoFactor(SalesAgent $salesAgent): RedirectResponse
    {
        abort_if($salesAgent->isArchived(), 403);

        $on = (bool) $salesAgent->access['two_factor'];
        $salesAgent->updateAccess(['two_factor' => ! $on]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Security, ($on ? 'a désactivé' : 'a activé').' la double authentification', $on ? 'Activée' : 'Désactivée', $on ? 'Désactivée' : 'Activée');
        Inertia::flash('toast', 'Double authentification '.($on ? 'désactivée.' : 'activée.'));

        return back();
    }

    /**
     * Révoque une session, ou toutes si aucune n’est précisée.
     */
    public function revokeSessions(SalesAgent $salesAgent, ?string $session = null): RedirectResponse
    {
        $sessions = collect($salesAgent->access['sessions']);
        $target = $session ? $sessions->firstWhere('id', $session) : null;
        abort_if($session && ! $target, 404);

        $salesAgent->updateAccess(['sessions' => $session ? $sessions->reject(fn (array $s): bool => $s['id'] === $session)->values()->all() : []]);
        $salesAgent->logActivity(SalesAgentActivityCategory::Security, $session ? 'a révoqué une session' : 'a déconnecté tous les appareils', null, null, $target ? "{$target['device']} · {$target['ip']}" : null);
        Inertia::flash('toast', $session ? 'Session révoquée.' : 'Tous les appareils ont été déconnectés.');

        return back();
    }
}
