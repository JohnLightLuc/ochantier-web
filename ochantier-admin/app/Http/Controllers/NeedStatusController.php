<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedManualStatus;
use App\Models\Need;
use App\Models\NeedOffer;
use App\Support\NeedInsights;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cycle de vie d’un besoin (suspension, réactivation, annulation, clôture, archivage)
 * et de ses offres (actualisation, prolongation, clôture des candidatures).
 */
class NeedStatusController extends Controller
{
    public function suspend(Request $request, Need $need): RedirectResponse
    {
        $this->load($need);
        abort_if($need->manual_status || $need->status() === Need::DONE, 422, 'Ce besoin ne peut pas être suspendu.');

        $reason = $this->reason($request);
        $old = $need->status();
        $need->offers->reject(fn (NeedOffer $o): bool => $o->manual_status || $o->stats()['selected'] >= $o->line->quantity)
            ->each(fn (NeedOffer $o) => $o->update(['manual_status' => NeedManualStatus::Suspended, 'applications_open' => false]));
        $need->update(['manual_status' => NeedManualStatus::Suspended, 'reason' => $reason]);
        $need->logActivity(NeedActivityCategory::Status, 'a suspendu le besoin', $old, 'Suspendu', $reason);
        Inertia::flash('toast', 'Besoin suspendu.');

        return back();
    }

    /**
     * Les offres suspendues sont rouvertes ; celles dont la date limite est dépassée passent en « Expirée ».
     */
    public function reactivate(Need $need): RedirectResponse
    {
        abort_unless($need->manual_status === NeedManualStatus::Suspended, 422, 'Ce besoin n’est pas suspendu.');

        $need->offers()->where('manual_status', NeedManualStatus::Suspended)->update(['manual_status' => null, 'applications_open' => true]);
        $need->update(['manual_status' => null, 'reason' => null]);
        $need->logActivity(NeedActivityCategory::Status, 'a réactivé le besoin', 'Suspendu', 'Publié');
        Inertia::flash('toast', 'Besoin réactivé.');

        return back();
    }

    public function cancel(Request $request, Need $need): RedirectResponse
    {
        $this->load($need);
        abort_if($need->manual_status || $need->status() === Need::DONE, 422, 'Ce besoin ne peut pas être annulé.');

        $reason = $this->reason($request);
        $old = $need->status();
        $this->closeOffers($need, NeedManualStatus::Cancelled);
        $need->update(['manual_status' => NeedManualStatus::Cancelled, 'reason' => $reason]);
        $need->logActivity(NeedActivityCategory::Status, 'a annulé le besoin', $old, 'Annulé', $reason);
        Inertia::flash('toast', 'Besoin annulé.');

        return back();
    }

    /**
     * Clôture d’un besoin comblé : les offres liées sont clôturées.
     */
    public function close(Need $need): RedirectResponse
    {
        $this->load($need);
        abort_unless($need->status() === Need::DONE, 422, 'Seul un besoin comblé peut être clôturé.');

        $old = $need->status();
        $this->closeOffers($need, NeedManualStatus::Closed);
        $need->update(['manual_status' => NeedManualStatus::Closed]);
        $need->logActivity(NeedActivityCategory::Status, 'a clôturé le besoin', $old, 'Clôturé');
        Inertia::flash('toast', 'Besoin clôturé.');

        return back();
    }

    public function archive(Need $need): RedirectResponse
    {
        abort_unless(in_array($need->manual_status, [NeedManualStatus::Closed, NeedManualStatus::Cancelled], true), 422, 'Seul un besoin clôturé ou annulé peut être archivé.');

        $old = $need->manual_status->label();
        $need->offers()->update(['manual_status' => NeedManualStatus::Archived, 'applications_open' => false]);
        $need->update(['manual_status' => NeedManualStatus::Archived]);
        $need->logActivity(NeedActivityCategory::Status, 'a archivé le besoin', $old, 'Archivé');
        Inertia::flash('toast', 'Besoin archivé.');

        return back();
    }

    /**
     * Republie une offre expirée pour les postes manquants ; si le démarrage est dépassé, une nouvelle date de démarrage est exigée.
     */
    public function refreshOffer(Request $request, Need $need, NeedOffer $offer): RedirectResponse
    {
        $this->load($need);
        $offer = $need->offers->firstWhere('id', $offer->id);
        abort_unless($offer->status() === NeedOffer::EXPIRED, 422, 'Cette offre n’est pas expirée.');

        $startPassed = $offer->starts_on->lessThanOrEqualTo(Need::referenceDate()->addDay());
        $data = $request->validate([
            'deadline' => ['required', 'date', 'after_or_equal:'.Need::referenceDate()->format('Y-m-d')],
            'starts_on' => [$startPassed ? 'required' : 'nullable', 'date', 'after_or_equal:deadline'],
        ], [
            'deadline.required' => 'La nouvelle date limite est obligatoire.',
            'deadline.after_or_equal' => 'La date limite ne peut pas être dans le passé.',
            'starts_on.required' => 'Le démarrage initial est dépassé : indiquez une nouvelle date de démarrage.',
            'starts_on.after_or_equal' => 'Le démarrage doit suivre la date limite.',
        ]);
        $start = isset($data['starts_on']) ? CarbonImmutable::parse($data['starts_on']) : CarbonImmutable::parse($offer->starts_on);
        if (CarbonImmutable::parse($data['deadline'])->greaterThan($start)) {
            throw ValidationException::withMessages(['deadline' => 'Incohérence : la date limite doit précéder le démarrage ('.$start->format('d/m/Y').').']);
        }

        $this->refresh($need, $offer, CarbonImmutable::parse($data['deadline']), isset($data['starts_on']) ? $start : null);
        Inertia::flash('toast', 'Offre actualisée et republiée jusqu’au '.CarbonImmutable::parse($data['deadline'])->format('d/m/Y').'.');

        return back();
    }

    /**
     * Actualise toutes les offres expirées d’un besoin avec la même date limite.
     */
    public function refreshAll(Request $request, Need $need): RedirectResponse
    {
        $this->load($need);
        $deadline = $this->deadline($request);
        $expired = $this->expired($need);
        abort_if($expired->isEmpty(), 422, 'Aucune offre expirée sur ce besoin.');

        $late = $expired->filter(fn (NeedOffer $o): bool => $deadline->greaterThan($o->starts_on));
        if ($late->isNotEmpty()) {
            throw ValidationException::withMessages(['deadline' => 'Démarrage dépassé pour '.$late->pluck('reference')->implode(', ').' : actualisez ces offres une par une.']);
        }

        $expired->each(fn (NeedOffer $o) => $this->refresh($need, $o, $deadline));
        Inertia::flash('toast', $expired->count().' offres actualisées.');

        return back();
    }

    /**
     * Actualisation groupée depuis l’onglet « Expirés » : le démarrage dépassé est décalé au surlendemain de la date limite.
     */
    public function refreshMany(Request $request): RedirectResponse
    {
        $deadline = $this->deadline($request);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $needs = Need::with(NeedInsights::RELATIONS)->whereIn('id', $ids)->get();
        $offers = $needs->flatMap(fn (Need $n): Collection => $this->expired($n)->map(fn (NeedOffer $o): array => [$n, $o]));
        abort_if($offers->isEmpty(), 422, 'Aucun besoin expiré dans la sélection.');

        foreach ($offers as [$need, $offer]) {
            $this->refresh($need, $offer, $deadline, $offer->starts_on->lessThan($deadline) ? $deadline->addDays(2) : null);
        }
        Inertia::flash('toast', $needs->count().' besoin(s) actualisé(s) et republié(s).');

        return back();
    }

    /**
     * Prolonge une offre ouverte, ou corrige sa date limite (`mode=modifier`).
     */
    public function extend(Request $request, Need $need, NeedOffer $offer): RedirectResponse
    {
        $this->load($need);
        $offer = $need->offers->firstWhere('id', $offer->id);
        abort_unless($offer->isOpen(), 422, 'Cette offre n’est pas ouverte.');

        $edit = $request->input('mode') === 'modifier';
        $data = $request->validate([
            'deadline' => ['required', 'date', 'after_or_equal:'.Need::referenceDate()->format('Y-m-d'), 'before_or_equal:'.$offer->starts_on->format('Y-m-d'), ...($edit ? [] : ['after:'.$offer->deadline->format('Y-m-d')])],
            'reason' => [$edit ? 'nullable' : 'required', 'string', 'max:500'],
        ], [
            'deadline.required' => 'La nouvelle date limite est obligatoire.',
            'deadline.after_or_equal' => 'La date limite ne peut pas être dans le passé.',
            'deadline.before_or_equal' => 'Incohérence : la date limite doit précéder le démarrage ('.$offer->starts_on->format('d/m/Y').').',
            'deadline.after' => 'La prolongation doit être postérieure à la date limite actuelle.',
            'reason.required' => 'Le motif est obligatoire.',
        ]);

        $old = $offer->deadline->format('d/m/Y');
        $offer->update(['deadline' => $data['deadline'], 'applications_open' => true, 'extensions' => $offer->extensions + ($edit ? 0 : 1)]);
        $new = CarbonImmutable::parse($data['deadline'])->format('d/m/Y');
        $need->logActivity(NeedActivityCategory::Dates, $edit ? 'a modifié la date limite' : 'a prolongé l’offre', $old, $new, trim("{$offer->reference} · ".($data['reason'] ?? ''), ' ·'));
        Inertia::flash('toast', $edit ? 'Date limite modifiée.' : "Offre prolongée jusqu’au {$new}.");

        return back();
    }

    public function closeApplications(Need $need, NeedOffer $offer): RedirectResponse
    {
        abort_unless($offer->applications_open, 422, 'Les candidatures sont déjà fermées.');

        $offer->update(['applications_open' => false]);
        $need->logActivity(NeedActivityCategory::Dates, 'a clôturé les candidatures', 'Ouvertes', 'Fermées', $offer->reference);
        Inertia::flash('toast', 'Candidatures clôturées.');

        return back();
    }

    private function refresh(Need $need, NeedOffer $offer, CarbonImmutable $deadline, ?CarbonImmutable $start = null): void
    {
        $old = $offer->deadline->format('d/m/Y');
        $offer->update([
            'deadline' => $deadline,
            'applications_open' => true,
            'refreshes' => $offer->refreshes + 1,
            'published_on' => Need::referenceDate(),
            ...($start ? ['starts_on' => $start, 'ends_on' => $offer->ends_on->greaterThan($start) ? $offer->ends_on : $start->addDays((int) $offer->starts_on->diffInDays($offer->ends_on))] : []),
        ]);
        $need->logActivity(NeedActivityCategory::Publication, 'a actualisé une offre expirée', $old, $deadline->format('d/m/Y'), "{$offer->reference} · republiée, ouvriers ciblés notifiés");
    }

    /**
     * @return Collection<int, NeedOffer>
     */
    private function expired(Need $need): Collection
    {
        return $need->offers->filter(fn (NeedOffer $o): bool => $o->status() === NeedOffer::EXPIRED)->values();
    }

    private function closeOffers(Need $need, NeedManualStatus $status): void
    {
        $need->offers->reject(fn (NeedOffer $o): bool => (bool) $o->manual_status)
            ->each(fn (NeedOffer $o) => $o->update(['manual_status' => $status, 'applications_open' => false]));
    }

    private function load(Need $need): void
    {
        $need->load(NeedInsights::RELATIONS);
    }

    private function deadline(Request $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->validate(
            ['deadline' => ['required', 'date', 'after_or_equal:'.Need::referenceDate()->format('Y-m-d')]],
            ['deadline.required' => 'La nouvelle date limite est obligatoire.', 'deadline.after_or_equal' => 'La date limite ne peut pas être dans le passé.'],
        )['deadline']);
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Le motif est obligatoire (5 caractères minimum).'])['reason']);
    }
}
