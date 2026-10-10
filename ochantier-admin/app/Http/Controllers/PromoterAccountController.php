<?php

namespace App\Http\Controllers;

use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Http\Requests\NotifyPromoterRequest;
use App\Http\Requests\SuspendPromoterRequest;
use App\Models\Promoter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cycle de vie du compte : validation, suspension, réactivation, notification.
 */
class PromoterAccountController extends Controller
{
    public function validateAccount(Promoter $promoter): RedirectResponse
    {
        $this->activate($promoter);

        Inertia::flash('toast', "Compte {$promoter->name} validé.");

        return back();
    }

    /**
     * Validation groupée depuis la liste : seuls les comptes « À valider » sont concernés.
     */
    public function validateMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $promoters = Promoter::whereIn('id', $ids)->where('status', PromoterStatus::Pending)->get();

        $promoters->each(fn (Promoter $p) => $this->activate($p));

        $n = $promoters->count();
        Inertia::flash('toast', $n
            ? $n.($n > 1 ? ' comptes validés.' : ' compte validé.')
            : 'Aucun compte à valider dans la sélection.');

        return back();
    }

    private function activate(Promoter $promoter): void
    {
        $promoter->update([
            'status' => PromoterStatus::Active,
            'payment_status' => $promoter->payment_status === PromoterPaymentStatus::Pending ? PromoterPaymentStatus::UpToDate : $promoter->payment_status,
        ]);
        $promoter->logActivity('a validé le compte', null, 'verified', 'success');
    }

    public function suspend(SuspendPromoterRequest $request, Promoter $promoter): RedirectResponse
    {
        $promoter->update([
            'status' => PromoterStatus::Suspended,
            'suspension_reason' => $request->validated('category').' — '.trim($request->validated('reason')),
            'suspended_at' => today(),
        ]);
        $promoter->logActivity('a suspendu le compte', $request->validated('category'), 'block', 'danger');

        Inertia::flash('toast', "Compte {$promoter->name} suspendu.");

        return back();
    }

    public function reactivate(Promoter $promoter): RedirectResponse
    {
        $promoter->update(['status' => PromoterStatus::Active, 'suspension_reason' => null, 'suspended_at' => null]);
        $promoter->logActivity('a réactivé le compte', null, 'settings_backup_restore', 'success');

        Inertia::flash('toast', "Compte {$promoter->name} réactivé.");

        return back();
    }

    /**
     * Enregistre l’envoi d’une notification (l’envoi réel par push, SMS ou e-mail reste à brancher).
     */
    public function notify(NotifyPromoterRequest $request, Promoter $promoter): RedirectResponse
    {
        $promoter->logActivity('a envoyé une notification', $request->validated('subject'), 'send', 'info');

        Inertia::flash('toast', "Notification envoyée à {$promoter->representative} (".implode(', ', $request->validated('channels')).').');

        return back();
    }
}
