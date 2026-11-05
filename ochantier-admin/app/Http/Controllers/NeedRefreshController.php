<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
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
 * Actualisation des offres expirées, sur les données locales encore utilisées par le module Affectations. À retirer
 * quand ce module lira l’API.
 */
class NeedRefreshController extends Controller
{
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
}
