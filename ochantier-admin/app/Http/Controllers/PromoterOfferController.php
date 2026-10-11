<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\PromoterOfferProfileStatus;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Models\PromoterOffer;
use App\Models\PromoterOfferProfile;
use App\Support\AssignmentInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Traitement d’une offre de promoteur : chaque profil choisi est contacté puis validé (une affectation est créée)
 * ou refusé et remplacé. Le promoteur est notifié automatiquement à la fin du traitement, puis l’acompte comble l’offre.
 */
class PromoterOfferController extends Controller
{
    /**
     * Motifs de refus qui viennent de l’ouvrier lui-même.
     *
     * @var array<int, string>
     */
    private const WORKER_REFUSALS = ['L’ouvrier refuse l’offre', 'Injoignable'];

    public function contact(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'channel' => ['required', Rule::in(AssignmentInsights::CHANNELS)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['channel.*' => 'Choisissez le canal de contact.']);
        $list = $this->profiles($promoterOffer, $data['ids'], PromoterOfferProfileStatus::Pending);

        foreach ($list as $p) {
            $p->update(['status' => PromoterOfferProfileStatus::Contacted, 'contacted_on' => Need::referenceDate(), 'channel' => $data['channel']]);
            $promoterOffer->logActivity(NeedActivityCategory::Notification, 'a contacté un ouvrier', PromoterOfferProfileStatus::Pending->label(), PromoterOfferProfileStatus::Contacted->label(), "{$p->name} · {$data['channel']}".(filled($data['note'] ?? null) ? ' · '.trim($data['note']) : ''));
        }
        Inertia::flash('toast', $list->count() > 1 ? "{$list->count()} ouvriers contactés." : "{$list->first()->name} contacté.");

        return back();
    }

    /**
     * Valide un ou plusieurs ouvriers contactés : une affectation est créée pour chacun.
     */
    public function accept(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $list = $this->profiles($promoterOffer, $ids, PromoterOfferProfileStatus::Contacted);

        foreach ($list as $p) {
            $p->update(['status' => PromoterOfferProfileStatus::Accepted, 'assignment_reference' => NeedAssignment::nextReference()]);
            $promoterOffer->logActivity(NeedActivityCategory::Assignment, 'a validé un ouvrier', PromoterOfferProfileStatus::Contacted->label(), PromoterOfferProfileStatus::Accepted->label(), "{$p->name} · {$p->assignment_reference}");
        }
        $notified = $this->autoNotify($promoterOffer);
        $message = $list->count() > 1 ? "{$list->count()} ouvrier(s) validé(s)." : "{$list->first()->name} validé · affectation {$list->first()->assignment_reference} créée.";
        Inertia::flash('toast', $message.($notified ? " Traitement terminé · {$promoterOffer->site->promoter_name} notifié automatiquement pour le paiement de l’acompte." : ''));

        return back();
    }

    /**
     * Invalide un profil : refus de l’ouvrier ou décision de l’administration ; des remplaçants sont proposés.
     */
    public function refuse(Request $request, PromoterOffer $promoterOffer, PromoterOfferProfile $profile): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        abort_unless(in_array($profile->status, [PromoterOfferProfileStatus::Pending, PromoterOfferProfileStatus::Contacted], true), 422, 'Ce profil ne peut plus être invalidé.');
        $reason = AssignmentNeedController::reasonFrom($request, AssignmentInsights::REFUSE_REASONS);
        $status = in_array($request->input('motif'), self::WORKER_REFUSALS, true) ? PromoterOfferProfileStatus::RefusedByWorker : PromoterOfferProfileStatus::Refused;

        $old = $profile->status->label();
        $profile->update(['status' => $status, 'reason' => $reason]);
        $promoterOffer->logActivity(NeedActivityCategory::Assignment, 'a refusé un profil', $old, $status->label(), "{$profile->name} · {$reason}");
        Inertia::flash('toast', 'Ouvrier invalidé · remplaçants proposés automatiquement par le Matching.');

        return to_route('assignments.offers.show', ['promoterOffer' => $promoterOffer, 'remplacement' => $profile->id]);
    }

    /**
     * Demande de remplacement d’un ouvrier validé, une fois l’acompte payé.
     */
    public function requestReplacement(Request $request, PromoterOffer $promoterOffer, PromoterOfferProfile $profile): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        abort_unless($profile->status === PromoterOfferProfileStatus::Accepted && $promoterOffer->payment_reference, 422, 'Le remplacement concerne un ouvrier validé dont l’acompte est réglé.');
        $reason = 'Remplacement · '.AssignmentNeedController::reasonFrom($request, AssignmentInsights::REPLACEMENT_REASONS);

        $profile->update(['status' => PromoterOfferProfileStatus::Refused, 'reason' => $reason]);
        $promoterOffer->logActivity(NeedActivityCategory::Assignment, 'a enregistré une demande de remplacement', PromoterOfferProfileStatus::Accepted->label(), PromoterOfferProfileStatus::Refused->label(), "{$profile->name} · {$reason}");
        Inertia::flash('toast', 'Demande de remplacement enregistrée · ouvriers alternatifs proposés.');

        return to_route('assignments.offers.show', ['promoterOffer' => $promoterOffer, 'remplacement' => $profile->id]);
    }

    /**
     * Propose un remplaçant du même métier : le profil refusé est remplacé, le nouveau passe « En attente ».
     */
    public function propose(Request $request, PromoterOffer $promoterOffer, PromoterOfferProfile $profile): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        abort_unless($profile->status->isRefused() && ! $profile->replaced_by, 422, 'Seul un profil refusé peut être remplacé.');
        $key = $request->validate(['key' => ['required', 'string']])['key'];
        $promoterOffer->load('profiles');
        $pick = collect(AssignmentInsights::promoterReplacements($promoterOffer, $profile, 50))->first(fn (array $x): bool => $x['p']['key'] === $key);
        abort_unless($pick, 422, 'Ce profil n’est plus disponible pour cette offre.');

        $p = $pick['p'];
        $old = $profile->status->label();
        $profile->update(['status' => PromoterOfferProfileStatus::Replaced, 'replaced_by' => $p['name']]);
        $promoterOffer->profiles()->create([
            'worker_id' => $p['workerId'], 'name' => $p['name'], 'trade' => $p['trade'], 'commune' => $p['commune'], 'experience' => $p['experience'],
            'rating' => $p['rating'], 'daily_cost' => $p['cost'], 'availability' => $p['availability'], 'status' => PromoterOfferProfileStatus::Pending,
            'source' => 'Matching', 'replaces' => $profile->name,
        ]);
        $promoterOffer->logActivity(NeedActivityCategory::Assignment, 'a proposé un profil de remplacement', $profile->name, $p['name'], "Matching · même métier · {$old}");
        Inertia::flash('toast', "{$p['name']} proposé en remplacement de {$profile->name}.");

        return to_route('assignments.offers.show', $promoterOffer);
    }

    /**
     * Notification manuelle du promoteur à la fin du traitement.
     */
    public function notify(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        abort_unless($promoterOffer->status() === PromoterOffer::TO_NOTIFY, 422, 'Le promoteur ne peut être notifié qu’une fois tous les ouvriers validés.');
        $data = AssignmentNeedController::notification($request);

        $promoterOffer->update(['notified_on' => Need::referenceDate()]);
        $promoterOffer->logActivity(NeedActivityCategory::Notification, 'a notifié le promoteur', PromoterOffer::TO_NOTIFY, PromoterOffer::DEPOSIT, "{$data['subject']} ({$data['channel']})");
        Inertia::flash('toast', "{$promoterOffer->site->promoter_name} notifié · acompte en attente.");

        return back();
    }

    public function payDeposit(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        abort_unless($promoterOffer->status() === PromoterOffer::DEPOSIT, 422, 'Aucun acompte en attente pour cette offre.');
        $reference = AssignmentNeedController::payment($request);
        $deposit = $promoterOffer->deposit();

        $promoterOffer->update(['payment_reference' => $reference]);
        $promoterOffer->logActivity(NeedActivityCategory::Validation, 'a validé le paiement de l’acompte', PromoterOffer::DEPOSIT, PromoterOffer::DONE, number_format($deposit['deposit'], 0, ',', ' ')." FCFA · {$reference}");
        Inertia::flash('toast', 'Acompte validé · offre comblée, chantier en cours.');

        return back();
    }

    /**
     * Annule l’offre : les affectations déjà créées restent actives.
     */
    public function cancel(Request $request, PromoterOffer $promoterOffer): RedirectResponse
    {
        $this->ensureOpen($promoterOffer);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Le motif est obligatoire (5 caractères minimum).'])['reason']);

        $old = $promoterOffer->status();
        $promoterOffer->update(['cancelled' => true]);
        $promoterOffer->logActivity(NeedActivityCategory::Status, 'a annulé l’offre', $old, PromoterOffer::CANCELLED, $reason);
        Inertia::flash('toast', 'Offre annulée.');

        return back();
    }

    /**
     * Notifie le promoteur dès que le traitement est terminé (tous les profils validés), une seule fois.
     */
    private function autoNotify(PromoterOffer $offer): bool
    {
        $offer->load('profiles');
        if ($offer->notified_on || $offer->status() !== PromoterOffer::TO_NOTIFY) {
            return false;
        }
        $deposit = $offer->deposit();
        $offer->update(['notified_on' => Need::referenceDate()]);
        $offer->logActivity(NeedActivityCategory::Notification, 'a notifié automatiquement le promoteur', 'Traitement terminé', PromoterOffer::DEPOSIT, "{$deposit['accepted']} ouvrier(s) validé(s) · acompte de ".number_format($deposit['deposit'], 0, ',', ' ').' FCFA à régler (push, SMS, e-mail)', 'Système', 'Système');

        return true;
    }

    /**
     * Profils de l’offre au statut attendu.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, PromoterOfferProfile>
     */
    private function profiles(PromoterOffer $offer, array $ids, PromoterOfferProfileStatus $status): Collection
    {
        $this->ensureOpen($offer);
        $list = $offer->profiles()->whereIn('id', $ids)->where('status', $status)->get();
        abort_if($list->isEmpty(), 422, $status === PromoterOfferProfileStatus::Contacted ? 'Aucun ouvrier contacté à valider.' : 'Aucun ouvrier à contacter.');

        return $list;
    }

    private function ensureOpen(PromoterOffer $offer): void
    {
        abort_unless($offer->isOpen(), 422, 'Offre annulée : lecture seule.');
    }
}
