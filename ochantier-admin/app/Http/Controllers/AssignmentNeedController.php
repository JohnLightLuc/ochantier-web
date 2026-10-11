<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedAssignmentStatus;
use App\Models\MatchingAudit;
use App\Models\Need;
use App\Models\NeedAssignment;
use App\Support\AssignmentInsights;
use App\Support\MatchingEngine;
use App\Support\NeedInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Suivi des ouvriers trouvés pour un besoin : contact, validation, invalidation et remplacement,
 * ajout de propositions du Matching ou d’un ouvrier manuel, acompte du promoteur.
 *
 * Quand tous les postes sont pourvus et validés, le promoteur est notifié automatiquement pour l’acompte.
 */
class AssignmentNeedController extends Controller
{
    /**
     * Enregistre le contact d’un ou plusieurs ouvriers « À contacter ».
     */
    public function contact(Request $request, Need $need): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'channel' => ['required', Rule::in(AssignmentInsights::CHANNELS)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['channel.*' => 'Choisissez le canal de contact.']);
        $list = $this->assignments($need, $data['ids'], NeedAssignmentStatus::ToContact);
        $today = Need::referenceDate();

        foreach ($list as $a) {
            $a->update(['status' => NeedAssignmentStatus::Contacted, 'contacted_on' => $today, 'channel' => $data['channel']]);
            $need->logActivity(NeedActivityCategory::Notification, 'a contacté un ouvrier', NeedAssignmentStatus::ToContact->label(), NeedAssignmentStatus::Contacted->label(), "{$a->worker_name} · {$data['channel']}".(filled($data['note'] ?? null) ? ' · '.trim($data['note']) : ''));
        }
        Inertia::flash('toast', $list->count() > 1 ? "{$list->count()} ouvriers contactés." : "{$list->first()->worker_name} contacté.");

        return back();
    }

    /**
     * Valide le matching d’un ou plusieurs ouvriers contactés ; notifie le promoteur quand le besoin est entièrement validé.
     */
    public function validateWorkers(Request $request, Need $need): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $list = $this->assignments($need, $ids, NeedAssignmentStatus::Contacted);

        foreach ($list as $a) {
            $a->update(['status' => NeedAssignmentStatus::Validated]);
            $need->logActivity(NeedActivityCategory::Assignment, 'a validé le matching d’un ouvrier', NeedAssignmentStatus::Contacted->label(), NeedAssignmentStatus::Validated->label(), "{$a->worker_name} · {$a->reference}");
        }
        $notified = $this->autoNotify($need);
        Inertia::flash('toast', ($list->count() > 1 ? "{$list->count()} ouvriers validés." : "{$list->first()->worker_name} validé.").($notified ? " {$need->site->promoter_name} notifié automatiquement pour l’acompte." : ''));

        return back();
    }

    /**
     * Annule la validation d’un ouvrier tant que l’acompte n’est pas payé.
     */
    public function cancelValidation(Need $need, NeedAssignment $assignment): RedirectResponse
    {
        $this->ensureOpen($need);
        abort_unless($assignment->status === NeedAssignmentStatus::Validated && ! $need->deposit_paid, 422, 'Seule une validation non suivie du paiement de l’acompte peut être annulée.');

        $assignment->update(['status' => NeedAssignmentStatus::Contacted]);
        $need->logActivity(NeedActivityCategory::Assignment, 'a annulé une validation', NeedAssignmentStatus::Validated->label(), NeedAssignmentStatus::Contacted->label(), $assignment->worker_name);
        Inertia::flash('toast', 'Validation annulée.');

        return back();
    }

    /**
     * Invalide un ouvrier (refus, indisponibilité…) : des remplaçants sont proposés par le Matching.
     */
    public function decline(Request $request, Need $need, NeedAssignment $assignment): RedirectResponse
    {
        $this->ensureOpen($need);
        abort_unless(in_array($assignment->status, [NeedAssignmentStatus::ToContact, NeedAssignmentStatus::Contacted], true), 422, 'Cet ouvrier ne peut plus être invalidé.');
        $reason = self::reasonFrom($request, AssignmentInsights::DECLINE_REASONS);

        $old = $assignment->status->label();
        $assignment->update(['status' => NeedAssignmentStatus::Refused, 'reason' => $reason]);
        $need->logActivity(NeedActivityCategory::Assignment, 'a invalidé un ouvrier', $old, NeedAssignmentStatus::Refused->label(), "{$assignment->worker_name} · {$reason}");
        Inertia::flash('toast', 'Ouvrier invalidé · remplaçants proposés automatiquement par le Matching.');

        return to_route('assignments.needs.show', ['need' => $need, 'remplacement' => $assignment->reference]);
    }

    /**
     * Demande de remplacement d’un ouvrier validé après le paiement de l’acompte.
     */
    public function requestReplacement(Request $request, Need $need, NeedAssignment $assignment): RedirectResponse
    {
        $this->ensureOpen($need);
        abort_unless($assignment->status === NeedAssignmentStatus::Validated && $need->deposit_paid, 422, 'Le remplacement concerne un ouvrier validé dont l’acompte est réglé.');
        $reason = 'Remplacement · '.self::reasonFrom($request, AssignmentInsights::REPLACEMENT_REASONS);

        $assignment->update(['status' => NeedAssignmentStatus::Refused, 'reason' => $reason]);
        $need->logActivity(NeedActivityCategory::Assignment, 'a enregistré une demande de remplacement', NeedAssignmentStatus::Validated->label(), NeedAssignmentStatus::Refused->label(), "{$assignment->worker_name} · {$reason}");
        Inertia::flash('toast', 'Demande de remplacement enregistrée · ouvriers alternatifs proposés.');

        return to_route('assignments.needs.show', ['need' => $need, 'remplacement' => $assignment->reference]);
    }

    /**
     * Propose un remplaçant (Matching) à la place d’un ouvrier refusé ; il passe « À contacter ».
     */
    public function propose(Request $request, MatchingEngine $engine, Need $need, NeedAssignment $assignment): RedirectResponse
    {
        $this->ensureOpen($need);
        abort_unless($assignment->status === NeedAssignmentStatus::Refused, 422, 'Seul un ouvrier refusé peut être remplacé.');
        $key = $request->validate(['key' => ['required', 'string']])['key'];
        $offer = $engine->offer($assignment->need_offer_id);
        $item = collect($engine->replacements($offer, 50))->first(fn (array $x): bool => $x['p']['key'] === $key);
        abort_unless($item, 422, 'Ce profil n’est plus disponible pour ce poste.');

        $assignment->update(['status' => NeedAssignmentStatus::Replaced, 'replaced_by' => $item['p']['name']]);
        $engine->assign($offer, $item, 'Matching', $assignment->worker_name);
        Inertia::flash('toast', "{$item['p']['name']} proposé en remplacement de {$assignment->worker_name}.");

        return to_route('assignments.needs.show', $need);
    }

    /**
     * Ajoute les propositions automatiques du Matching (toutes, ou celles choisies « offre:clé »).
     */
    public function addProposals(Request $request, MatchingEngine $engine, Need $need): RedirectResponse
    {
        $this->ensureOpen($need);
        $chosen = $request->validate(['picks' => ['nullable', 'array'], 'picks.*' => ['string']])['picks'] ?? [];
        $picks = $engine->autoPicks();
        $added = collect();

        foreach ($need->offers as $o) {
            $offer = $engine->offer($o->id);
            if (! $engine->isEligible($offer)) {
                continue;
            }
            foreach ($picks[$offer->id]['pick'] ?? [] as $x) {
                if ((! $chosen || in_array("{$offer->id}:{$x['p']['key']}", $chosen, true)) && $offer->stats()['rest'] > 0) {
                    $engine->assign($offer, $x);
                    $added->push("{$x['p']['name']} ({$x['sc']['score']})");
                }
            }
        }
        abort_if($added->isEmpty(), 422, 'Aucune proposition automatique à ajouter.');

        MatchingAudit::record('a accepté des propositions automatiques du Matching', $need->reference, $added->implode(', '));
        Inertia::flash('toast', "{$added->count()} ouvrier(s) proposé(s) par le Matching ajouté(s) · à contacter.");

        return back();
    }

    /**
     * Ajoute manuellement un ouvrier du métier sur un poste ouvert.
     */
    public function addManual(Request $request, MatchingEngine $engine, Need $need): RedirectResponse
    {
        $this->ensureOpen($need);
        $data = $request->validate(['offer' => ['required', 'integer'], 'key' => ['required', 'string']], ['key.*' => 'Choisissez un ouvrier.']);
        $offer = $need->offers->firstWhere('id', (int) $data['offer']);
        abort_unless($offer && ! $offer->manual_status, 404);
        $offer = $engine->offer($offer->id);
        abort_unless($offer->stats()['rest'] > 0, 422, 'Tous les postes de ce métier sont déjà pourvus.');
        $item = $engine->item($offer, $data['key']);
        abort_unless($item, 422, 'Profil introuvable.');
        if ($item['sc']['blocked']) {
            abort(422, 'Sélection impossible : '.mb_strtolower($item['sc']['blocking'][0]['criterion']).'.');
        }

        $engine->assign($offer, $item, 'Manuel');
        Inertia::flash('toast', "{$item['p']['name']} ajouté · à contacter dans Affectations & Offres.");

        return to_route('assignments.needs.show', $need);
    }

    /**
     * Enregistre le paiement de l’acompte : la demande est validée et le chantier peut démarrer.
     */
    public function payDeposit(Request $request, Need $need): RedirectResponse
    {
        $this->ensureOpen($need);
        abort_unless($need->status() === Need::DEPOSIT, 422, 'Aucun acompte en attente pour ce besoin.');
        $reference = self::payment($request);
        $schedule = AssignmentInsights::schedule($need);

        $need->update(['deposit_paid' => true, 'promoter_validated' => true, 'payment_reference' => $reference, 'notified_on' => $need->notified_on ?? Need::referenceDate()]);
        $need->logActivity(NeedActivityCategory::Validation, 'a validé le paiement de l’acompte', Need::DEPOSIT, Need::DONE, number_format($schedule['deposit'], 0, ',', ' ')." FCFA · {$reference}");
        Inertia::flash('toast', 'Acompte validé · demande du promoteur validée, chantier en cours.');

        return back();
    }

    /**
     * Relance du promoteur (validation des affectations, acompte).
     */
    public function remind(Request $request, Need $need): RedirectResponse
    {
        $data = self::notification($request);
        $need->logActivity(NeedActivityCategory::Notification, 'a relancé le promoteur', null, null, "{$data['subject']} ({$data['channel']})");
        Inertia::flash('toast', "Relance envoyée à {$need->site->promoter_name}.");

        return back();
    }

    /**
     * Notifie le promoteur dès que tous les postes sont pourvus et validés (une seule fois).
     */
    private function autoNotify(Need $need): bool
    {
        $need->load(NeedInsights::RELATIONS);
        if ($need->notified_on || $need->status() !== Need::DEPOSIT) {
            return false;
        }
        $schedule = AssignmentInsights::schedule($need);
        $need->update(['notified_on' => Need::referenceDate()]);
        $need->logActivity(NeedActivityCategory::Notification, 'a notifié automatiquement le promoteur', Need::COVERED, Need::DEPOSIT, "{$need->coverage()['validated']} ouvrier(s) validé(s) · acompte de ".number_format($schedule['deposit'], 0, ',', ' ').' FCFA à régler (push, SMS, e-mail)', 'Système', 'Système');

        return true;
    }

    /**
     * Ouvriers du besoin au statut attendu.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, NeedAssignment>
     */
    private function assignments(Need $need, array $ids, NeedAssignmentStatus $status): Collection
    {
        $this->ensureOpen($need);
        $list = $need->assignments()->whereIn('id', $ids)->where('status', $status)->get();
        abort_if($list->isEmpty(), 422, 'Aucun ouvrier « '.mb_strtolower($status->label()).' » à traiter.');

        return $list;
    }

    private function ensureOpen(Need $need): void
    {
        abort_if((bool) $need->manual_status, 422, 'Besoin '.mb_strtolower($need->manual_status?->label() ?? '').' : lecture seule.');
    }

    /**
     * Motif choisi et précisions, combinés « Motif — précisions ».
     *
     * @param  array<int, string>  $motifs
     */
    public static function reasonFrom(Request $request, array $motifs): string
    {
        $data = $request->validate([
            'motif' => ['required', Rule::in($motifs)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['motif.*' => 'Choisissez un motif.', 'reason.*' => 'Les précisions sont obligatoires.']);

        return $data['motif'].' — '.trim($data['reason']);
    }

    /**
     * Mode et référence du paiement de l’acompte (« Mobile Money · MM-58214 »).
     */
    public static function payment(Request $request): string
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(AssignmentInsights::PAYMENT_METHODS)],
            'reference' => ['required', 'string', 'max:60'],
        ], ['method.*' => 'Choisissez le mode de paiement.', 'reference.*' => 'La référence de la transaction est obligatoire.']);

        return $data['method'].' · '.trim($data['reference']);
    }

    /**
     * Canal et objet d’une notification au promoteur.
     *
     * @return array{channel: string, subject: string}
     */
    public static function notification(Request $request): array
    {
        return $request->validate([
            'channel' => ['required', Rule::in(AssignmentInsights::NOTIFICATION_CHANNELS)],
            'subject' => ['required', 'string', 'max:255'],
        ], ['channel.*' => 'Choisissez le canal.', 'subject.*' => 'L’objet est obligatoire.']);
    }
}
