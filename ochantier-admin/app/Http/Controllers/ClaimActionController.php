<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Dispute;
use App\Models\QualityNotification;
use App\Models\Review;
use App\Support\QualityInsights as Q;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions sur les réclamations : enregistrement, qualification, résolution sans litige, clôture et transformation
 * en litige.
 */
class ClaimActionController extends Controller
{
    private function authorizeRight(string $right): void
    {
        abort_unless(Q::can($right), 403, Q::denied());
    }

    /**
     * Enregistre une réclamation sur une prestation (accusé de réception envoyé au demandeur).
     *
     * @param  array<string, mixed>  $p
     */
    public static function create(array $p, string $category, string $subject, string $description, string $priority, string $claimant, ?string $review = null): Claim
    {
        $promoter = ['name' => "{$p['author']} · {$p['promoter']}", 'role' => 'Promoteur', 'slug' => $p['promoterSlug']];
        $worker = ['name' => $p['worker'], 'role' => 'Ouvrier', 'slug' => $p['workerSlug']];
        $isPromoter = str_starts_with($claimant, 'Promoteur');
        $t = Q::today();
        $claim = Claim::create([
            'reference' => 'REC-'.$t->year.'-'.sprintf('%04d', 300 + (int) Claim::query()->max('id') + 1), 'claimant' => $isPromoter ? $promoter : $worker, 'other_party' => $isPromoter ? $worker : $promoter,
            'site_id' => $p['siteId'], 'assignment_reference' => $p['assignment'], 'operation_reference' => $p['opRef'], 'category' => $category, 'subject' => $subject, 'description' => $description,
            'date' => $t, 'priority' => $priority, 'owner' => auth()->user()?->name ?? 'N. Yao', 'due_date' => $t->addDays(config('admin.quality.sla.qualif')), 'status' => 'Reçue', 'review_reference' => $review,
        ]);
        $claim->log('creation', 'a enregistré la réclamation', null, 'Reçue', $category.($review ? " · depuis {$review}" : ''));
        QualityNotification::send($claim->claimant['name'], 'SMS', "Réclamation {$claim->reference} reçue", 'Aucune', $claim->reference);

        return $claim;
    }

    /**
     * Réclamation ouverte depuis un avis contesté.
     */
    public static function open(Review $review, string $category, string $subject, string $description, string $priority, string $claimant): Claim
    {
        $p = Q::prestations()->firstWhere('opRef', $review->operation_reference) ?? Q::prestations()->first();

        return self::create($p, $category, $subject, $description, $priority, $claimant, $review->reference);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Q::can('lit') && (Q::can('create') || Q::can('mod')), 403, Q::denied());
        $data = $request->validate([
            'claimant' => ['required', Rule::in(['Promoteur de la prestation', 'Ouvrier de la prestation'])], 'category' => ['required', Rule::in(config('admin.quality.categories'))],
            'prestation' => ['required', 'string'], 'subject' => ['required', 'string', 'min:8', 'max:150'], 'description' => ['required', 'string', 'min:15'],
            'priority' => ['required', Rule::in(Dispute::PRIORITIES)], 'review' => ['nullable', Rule::exists('reviews', 'reference')],
        ]);
        $p = Q::prestations()->firstWhere('opRef', $data['prestation']) ?? throw ValidationException::withMessages(['prestation' => 'Prestation inconnue.']);
        $claim = self::create($p, $data['category'], trim($data['subject']), trim($data['description']), $data['priority'], $data['claimant'], $data['review'] ?? null);
        Inertia::flash('toast', 'Réclamation enregistrée · accusé de réception envoyé.');

        return redirect("/avis-litiges/reclamations/{$claim->reference}");
    }

    public function qualify(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorizeRight('qualif');
        abort_unless($claim->isOpen(), 422);
        $data = $request->validate(['outcome' => ['required', Rule::in(['Traitement simple', 'Information demandée', 'Transformer en litige', 'Rejeter'])], 'reason' => ['required', 'string', 'min:10']]);
        if ($data['outcome'] === 'Transformer en litige') {
            return $this->toDispute($claim, trim($data['reason']));
        }
        $this->move($claim, $data['outcome'] === 'Rejeter' ? 'Rejetée' : $data['outcome'], trim($data['reason']), 'controle');

        return back();
    }

    public function resolve(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($claim->isOpen(), 422);
        $this->move($claim, 'Résolue', trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']), 'validation');

        return back();
    }

    public function close(Claim $claim): RedirectResponse
    {
        abort_unless($claim->status === 'Résolue', 422);
        $this->move($claim, 'Clôturée', 'Clôture après résolution');

        return back();
    }

    public function transform(Claim $claim): RedirectResponse
    {
        $this->authorizeRight('qualif');
        abort_unless($claim->isOpen(), 422);

        return $this->toDispute($claim, 'Qualification : désaccord persistant entre les parties');
    }

    private function move(Claim $claim, string $status, string $reason, string $category = 'statut'): void
    {
        $old = $claim->status;
        $claim->update(['status' => $status, 'resolution' => in_array($status, ['Clôturée'], true) ? $claim->resolution : $reason]);
        $claim->log($category, 'a changé le statut', $old, $status, $reason);
        Inertia::flash('toast', 'Réclamation : '.mb_strtolower($status).'.');
    }

    /**
     * Crée le litige (recevabilité en cours) et rattache la réclamation.
     */
    private function toDispute(Claim $claim, string $reason): RedirectResponse
    {
        $p = Q::prestations()->firstWhere('opRef', $claim->operation_reference) ?? Q::prestations()->first();
        $dispute = DisputeActionController::build($p, $claim->claimant['role'] === 'Promoteur', [
            'subject' => $claim->subject, 'category' => $claim->category, 'description' => mb_strlen($claim->description) >= 20 ? $claim->description : "{$claim->description} (détails à compléter)",
            'source' => 'Réclamation', 'source_reference' => $claim->reference, 'status' => 'Recevabilité en cours', 'facts_at' => $claim->date->format('Y-m-d'),
        ]);
        $old = $claim->status;
        $claim->update(['status' => 'Transformée en litige', 'dispute_id' => $dispute->id]);
        $claim->log('statut', 'a transformé la réclamation en litige', $old, 'Transformée en litige', "{$dispute->reference} · {$reason}");
        Inertia::flash('toast', 'Litige créé depuis la réclamation · recevabilité en cours.');

        return redirect("/avis-litiges/litiges/{$dispute->reference}");
    }
}
