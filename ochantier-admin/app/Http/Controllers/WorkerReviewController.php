<?php

namespace App\Http\Controllers;

use App\Enums\WorkerActivityCategory;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Modération des avis reçus par l’ouvrier (données de démonstration, en attendant le module Avis & litiges).
 */
class WorkerReviewController extends Controller
{
    public const DISPUTE_REASONS = ['Avis contesté', 'Paiement contesté', 'Pointage contesté', 'Comportement', 'Autre'];

    public const DISPUTE_PRIORITIES = ['Normale', 'Moyenne', 'Haute'];

    public function flag(Request $request, Worker $worker, string $review): RedirectResponse
    {
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (10 caractères minimum).'])['reason']);
        $item = $worker->findDemo('reviews', $review);
        abort_if($item['status'] === 'Signalé', 422);

        $worker->updateDemo('reviews', $review, ['status' => 'Signalé']);
        $worker->logActivity(WorkerActivityCategory::Review, 'a signalé un avis', $item['status'], 'Signalé', $reason);
        Inertia::flash('toast', 'Avis signalé à la modération.');

        return back();
    }

    public function hide(Worker $worker, string $review): RedirectResponse
    {
        $item = $worker->findDemo('reviews', $review);

        $worker->updateDemo('reviews', $review, ['status' => 'Masqué']);
        $worker->logActivity(WorkerActivityCategory::Review, 'a masqué un avis', $item['status'], 'Masqué', $item['site']);
        Inertia::flash('toast', 'Avis masqué.');

        return back();
    }

    public function restore(Worker $worker, string $review): RedirectResponse
    {
        $item = $worker->findDemo('reviews', $review);
        abort_unless($item['status'] === 'Masqué', 422);

        $worker->updateDemo('reviews', $review, ['status' => 'Publié']);
        $worker->logActivity(WorkerActivityCategory::Review, 'a restauré un avis', 'Masqué', 'Publié', $item['site']);
        Inertia::flash('toast', 'Avis restauré.');

        return back();
    }

    public function reply(Request $request, Worker $worker, string $review): RedirectResponse
    {
        $reply = trim($request->validate(['reply' => ['required', 'string', 'min:5', 'max:1000']], ['reply.*' => 'Ce champ est obligatoire (5 caractères minimum).'])['reply']);

        $worker->updateDemo('reviews', $review, ['reply' => $reply]);
        $worker->logActivity(WorkerActivityCategory::Review, 'a répondu à un avis', null, null, $reply);
        Inertia::flash('toast', 'Réponse publiée.');

        return back();
    }

    /**
     * Ouvre un litige à partir d’un avis ; il sera suivi dans le module Avis & litiges.
     */
    public function dispute(Request $request, Worker $worker, string $review): RedirectResponse
    {
        $worker->findDemo('reviews', $review);
        $data = $request->validate([
            'reason' => ['required', Rule::in(self::DISPUTE_REASONS)],
            'priority' => ['required', Rule::in(self::DISPUTE_PRIORITIES)],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['*.required' => 'Ce champ est obligatoire.', 'description.min' => '10 caractères minimum.']);

        $count = $worker->activities()->where('category', WorkerActivityCategory::Dispute)->count();
        $ref = sprintf('LIT-%04d', 111 + $count);
        $worker->logActivity(WorkerActivityCategory::Dispute, 'a ouvert un litige', null, $ref, "{$data['reason']} · priorité ".mb_strtolower($data['priority']));
        Inertia::flash('toast', 'Litige ouvert · suivi dans Avis & litiges.');

        return back();
    }
}
