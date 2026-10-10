<?php

namespace App\Http\Controllers;

use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Cycle de vie du profil : validation, rejet, suspension, réactivation, archivage.
 */
class WorkerStatusController extends Controller
{
    public const REJECTION_REASONS = ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Doublon potentiel', 'Expérience non justifiée', 'Autre'];

    public const REJECTION_REQUESTS = ['Fournir une nouvelle pièce d’identité lisible', 'Mettre à jour les documents expirés', 'Justifier les expériences déclarées', 'Contacter le commercial référent', 'Compléter le profil'];

    public const SUSPENSION_REASONS = ['Absences répétées', 'Comportement inapproprié', 'Documents non conformes', 'Litige en cours', 'Demande de l’ouvrier', 'Autre'];

    /**
     * Statuts depuis lesquels un profil peut être validé.
     */
    private const VALIDATABLE = [WorkerStatus::ToReview, WorkerStatus::InReview, WorkerStatus::Rejected, WorkerStatus::Incomplete];

    public function validateProfile(Worker $worker): RedirectResponse
    {
        abort_unless(in_array($worker->status, self::VALIDATABLE, true), 422, 'Ce profil ne peut pas être validé.');

        $this->markValidated($worker);
        Inertia::flash('toast', "Profil de {$worker->name} validé.");

        return back();
    }

    /**
     * Validation groupée depuis la liste : seuls les profils à vérifier, incomplets ou rejetés sont concernés.
     */
    public function validateMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $workers = Worker::whereIn('id', $ids)->whereIn('status', self::VALIDATABLE)->get();

        $workers->each(fn (Worker $w) => $this->markValidated($w, 'Validation groupée'));

        $n = $workers->count();
        Inertia::flash('toast', $n ? $n.($n > 1 ? ' profils validés.' : ' profil validé.') : 'Aucun profil à valider dans la sélection.');

        return back();
    }

    private function markValidated(Worker $worker, ?string $comment = null): void
    {
        $old = $worker->status->label();
        $worker->update([
            'status' => WorkerStatus::Validated,
            'rejection' => null,
            'availability' => $worker->availability === WorkerAvailability::Unavailable ? WorkerAvailability::Available : $worker->availability,
        ]);
        $worker->logActivity(WorkerActivityCategory::Validation, 'a validé le profil', $old, WorkerStatus::Validated->label(), $comment);
    }

    public function reject(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless($worker->status->isUnderReview(), 422, 'Seul un profil en vérification peut être rejeté.');

        $data = $request->validate([
            'reason' => ['required', Rule::in(self::REJECTION_REASONS)],
            'request' => ['required', Rule::in(self::REJECTION_REQUESTS)],
            'comment' => ['required', 'string', 'min:10', 'max:500'],
        ], ['comment.*' => 'Ce champ est obligatoire (10 caractères minimum).']);

        $old = $worker->status->label();
        $worker->update(['status' => WorkerStatus::Rejected, 'rejection' => ['reason' => $data['reason'], 'comment' => trim($data['comment']), 'request' => $data['request']]]);
        $worker->logActivity(WorkerActivityCategory::Rejection, 'a rejeté le profil', $old, WorkerStatus::Rejected->label(), "{$data['reason']} — ".trim($data['comment']));

        Inertia::flash('toast', "Profil de {$worker->name} rejeté · notification envoyée.");

        return back();
    }

    public function suspend(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless($worker->status === WorkerStatus::Validated, 422, 'Seul un profil validé peut être suspendu.');

        $data = $request->validate([
            'category' => ['required', Rule::in(self::SUSPENSION_REASONS)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.*' => 'Ce champ est obligatoire (10 caractères minimum).']);

        $reason = "{$data['category']} — ".trim($data['reason']);
        $worker->update([
            'previous_availability' => $worker->availability,
            'status' => WorkerStatus::Suspended,
            'availability' => WorkerAvailability::Unavailable,
            'suspension_reason' => $reason,
        ]);
        $worker->logActivity(WorkerActivityCategory::Suspension, 'a suspendu le profil', WorkerStatus::Validated->label(), WorkerStatus::Suspended->label(), $reason);

        Inertia::flash('toast', "{$worker->name} suspendu.");

        return back();
    }

    public function reactivate(Worker $worker): RedirectResponse
    {
        abort_unless(in_array($worker->status, [WorkerStatus::Suspended, WorkerStatus::Archived], true), 422);

        $old = $worker->status->label();
        $previous = $worker->previous_availability;
        $worker->update([
            'status' => WorkerStatus::Validated,
            'availability' => $previous && $previous !== WorkerAvailability::Unavailable ? $previous : WorkerAvailability::Available,
            'previous_availability' => null,
            'suspension_reason' => null,
        ]);
        $worker->logActivity(WorkerActivityCategory::Reactivation, 'a réactivé le profil', $old, WorkerStatus::Validated->label());

        Inertia::flash('toast', "{$worker->name} réactivé.");

        return back();
    }

    public function archive(Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 422);

        $old = $worker->status->label();
        $worker->update(['previous_availability' => $worker->availability, 'status' => WorkerStatus::Archived, 'availability' => WorkerAvailability::Unavailable]);
        $worker->logActivity(WorkerActivityCategory::Status, 'a archivé le profil', $old, WorkerStatus::Archived->label());

        Inertia::flash('toast', "{$worker->name} archivé.");

        return back();
    }
}
