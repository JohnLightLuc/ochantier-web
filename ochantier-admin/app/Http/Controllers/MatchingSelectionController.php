<?php

namespace App\Http\Controllers;

use App\Models\MatchingAudit;
use App\Models\MatchingCycle;
use App\Models\MatchingNotification;
use App\Models\MatchingRejection;
use App\Models\NeedOffer;
use App\Support\MatchingEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Sélection des profils d’une offre : choix, remplaçants, soumission, correction, rejet,
 * sursélection et transmission aux Affectations (statut « À contacter »).
 */
class MatchingSelectionController extends Controller
{
    public const ROLES = ['Remplaçant principal', 'Remplaçant secondaire', 'Réserve'];

    /**
     * Sélectionne ou désélectionne un profil, dans la limite des postes restants.
     */
    public function toggle(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        [$offer, $item] = $this->item($request, $engine, $offer);
        $selection = $engine->selection($offer);
        $key = $item['p']['key'];
        $name = $item['p']['name'];

        if (in_array($key, $selection['main'], true)) {
            $engine->saveSelection($offer, ['main' => array_diff($selection['main'], [$key])]);
            Inertia::flash('toast', "{$name} désélectionné.");

            return back();
        }

        $rest = $offer->stats()['rest'];
        if ($item['sc']['blocked']) {
            abort(422, 'Profil non éligible : '.mb_strtolower($item['sc']['blocking'][0]['criterion']).'.');
        }
        abort_if(count($selection['main']) >= $rest, 422, 'Nombre d’ouvriers recherchés atteint ('.count($selection['main'])." / {$rest}) : désélectionnez d’abord un ouvrier.");

        $replacements = $selection['replacements'];
        unset($replacements[$key]);
        $engine->saveSelection($offer, [
            'main' => [...$selection['main'], $key],
            'replacements' => $replacements,
            'status' => $selection['status'] === 'En attente de validation' ? $selection['status'] : 'Sélection en cours',
        ]);
        MatchingRejection::query()->where('need_offer_id', $offer->id)->where('candidate', $key)->delete();
        Inertia::flash('toast', "{$name} sélectionné · {$offer->line->trade} ".(count($selection['main']) + 1)." / {$rest}.");

        return back();
    }

    /**
     * Désigne (ou retire) un remplaçant ; il n’est pas compté comme affecté.
     */
    public function replacement(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        [$offer, $item] = $this->item($request, $engine, $offer);
        $role = $request->validate(['role' => ['nullable', Rule::in(self::ROLES)]])['role'] ?? null;
        abort_if($item['sc']['blocked'], 422, 'Profil non éligible.');

        $selection = $engine->selection($offer);
        $key = $item['p']['key'];
        $replacements = $selection['replacements'];
        if ($role) {
            $replacements[$key] = $role;
        } else {
            unset($replacements[$key]);
        }
        $engine->saveSelection($offer, ['main' => array_diff($selection['main'], [$key]), 'replacements' => $replacements]);
        MatchingAudit::record($role ? 'a désigné un '.mb_strtolower($role) : 'a retiré un remplaçant', $item['p']['name'], $offer->reference);
        Inertia::flash('toast', $role ? "{$item['p']['name']} désigné ".mb_strtolower($role).' (non compté comme affecté).' : 'Remplaçant retiré.');

        return back();
    }

    /**
     * Enregistre la sélection pour plus tard, ou la soumet à validation.
     */
    public function submit(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $status = $request->validate(['status' => ['required', Rule::in(['Brouillon', 'En attente de validation'])]])['status'];
        $selection = $engine->selection($offer);
        abort_unless($selection['main'], 422, 'Aucun profil sélectionné.');

        $engine->saveSelection($offer, ['status' => $status]);
        $names = $this->names($engine, $offer, $selection['main']);
        if ($status === 'Brouillon') {
            MatchingAudit::record('a enregistré une sélection', $offer->reference, $names);
        } else {
            MatchingAudit::record('a soumis une sélection à validation', $offer->reference, $names);
            MatchingNotification::send('Sélection soumise', 'Responsable Matching', 'E-mail', count($selection['main'])." profil(s) · {$offer->reference}", 'Valider');
        }
        Inertia::flash('toast', $status === 'Brouillon' ? 'Sélection enregistrée pour plus tard.' : 'Sélection soumise à validation.');

        return back();
    }

    public function correction(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $reason = $this->reason($request);
        $author = $engine->selection($offer)['author'];

        $engine->saveSelection($offer, ['status' => 'Correction demandée', 'note' => $reason]);
        MatchingAudit::record('a demandé une correction', $offer->reference, $reason);
        MatchingNotification::send('Correction demandée', $author, 'Notification interne', $reason, 'Corriger');
        Inertia::flash('toast', 'Correction demandée.');

        return back();
    }

    /**
     * Rejette toute la sélection : chaque profil est rejeté temporairement.
     */
    public function reject(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $reason = $this->reason($request);

        foreach ($engine->selection($offer)['main'] as $key) {
            MatchingRejection::updateOrCreate(['need_offer_id' => $offer->id, 'candidate' => $key], ['reason' => 'Sélection rejetée', 'nature' => 'Temporaire', 'comment' => $reason, 'author' => $request->user()?->name ?? 'Système', 'rejected_on' => $engine->today()]);
        }
        $engine->saveSelection($offer, ['main' => [], 'status' => 'Rejetée']);
        MatchingAudit::record('a rejeté une sélection', $offer->reference, $reason);
        Inertia::flash('toast', 'Sélection rejetée.');

        return back();
    }

    public function reset(MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $engine->saveSelection($offer, ['main' => [], 'replacements' => [], 'status' => 'Sélection en cours']);
        MatchingAudit::record('a réinitialisé une sélection', $offer->reference);
        Inertia::flash('toast', 'Sélection réinitialisée.');

        return back();
    }

    /**
     * Sursélection : réduire, conserver les profils excédentaires comme remplaçants, ou déroger (ils passent en réserve).
     */
    public function overselection(Request $request, MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['reduire', 'remplacants', 'derogation'])],
            'reason' => ['required_if:mode,derogation', 'nullable', 'string', 'min:5', 'max:500'],
        ], ['reason.*' => 'La justification est obligatoire (5 caractères minimum).']);
        $selection = $engine->selection($offer);
        $rest = $offer->stats()['rest'];
        abort_unless(count($selection['main']) > $rest, 422, 'Aucune sursélection à traiter.');

        $excess = array_slice($selection['main'], $rest);
        $replacements = $selection['replacements'];
        if ($data['mode'] !== 'reduire') {
            foreach ($excess as $i => $key) {
                $replacements[$key] = $data['mode'] === 'remplacants' && $i === 0 ? 'Remplaçant principal' : 'Réserve';
            }
        }
        $engine->saveSelection($offer, ['main' => array_slice($selection['main'], 0, $rest), 'replacements' => $replacements]);
        if ($data['mode'] === 'derogation') {
            MatchingAudit::record('a accordé une dérogation de sursélection', $offer->reference, trim($data['reason']));
        }
        Inertia::flash('toast', match ($data['mode']) {
            'reduire' => "Sélection réduite à {$rest} profil(s).",
            'remplacants' => 'Profils excédentaires désignés remplaçants.',
            default => "Dérogation enregistrée · seuls {$rest} profil(s) seront transmis, les autres restent en réserve.",
        });

        return back();
    }

    /**
     * Valide la sélection d’une offre et la transmet aux Affectations.
     */
    public function transmit(MatchingEngine $engine, NeedOffer $offer): RedirectResponse
    {
        $offer = $engine->offer($offer->id);
        $count = $this->transmitOffer($engine, $offer, 'a transmis une sélection aux Affectations');
        abort_unless($count, 422, 'Aucun profil éligible à transmettre.');

        MatchingNotification::send('Proposition d’affectation créée', 'Module Affectations', 'Notification interne', "{$count} proposition(s) · {$offer->reference}", 'Suivre');
        Inertia::flash('toast', "{$count} ouvrier(s) ajouté(s) au besoin {$offer->need->reference} dans Affectations & Offres.");

        return back();
    }

    /**
     * Validation groupée : transmet les sélections de plusieurs offres.
     */
    public function transmitMany(Request $request, MatchingEngine $engine): RedirectResponse
    {
        $ids = $request->validate(['offers' => ['required', 'array', 'min:1'], 'offers.*' => ['integer']])['offers'];
        $total = 0;
        $needs = [];
        foreach ($ids as $id) {
            $offer = $engine->offer((int) $id);
            if ($offer && ($count = $this->transmitOffer($engine, $offer, 'a validé une sélection (validation groupée)'))) {
                $total += $count;
                $needs[$offer->need_id] = true;
            }
        }
        abort_unless($total, 422, 'Aucun profil matché à valider pour les besoins sélectionnés.');

        MatchingNotification::send('Propositions d’affectation créées', 'Module Affectations', 'Notification interne', "{$total} proposition(s) · ".count($needs).' besoin(s)', 'Suivre');
        Inertia::flash('toast', "{$total} profil(s) validé(s) et transmis aux Affectations & Offres pour ".count($needs).' besoin(s).');

        return back();
    }

    /**
     * Crée une affectation « À contacter » par profil retenu (dans la limite des postes restants).
     */
    private function transmitOffer(MatchingEngine $engine, NeedOffer $offer, string $action): int
    {
        $selection = $engine->selection($offer);
        $items = collect(array_slice($selection['main'], 0, $offer->stats()['rest']))
            ->map(fn (string $key): ?array => $engine->item($offer, $key))
            ->filter(fn (?array $x): bool => $x !== null && ! $x['sc']['blocked'])
            ->values();
        if ($items->isEmpty()) {
            return 0;
        }

        foreach ($items as $x) {
            $engine->assign($offer, $x);
        }

        $engine->saveSelection($offer, ['main' => [], 'status' => 'Transmise aux Affectations']);
        if ($offer->last_cycle && ($cycle = MatchingCycle::query()->where('reference', $offer->last_cycle)->first())) {
            $cycle->increment('validations', $items->count());
            $cycle->increment('assignments', $items->count());
        }
        $names = $items->map(fn (array $x): string => "{$x['p']['name']} ({$x['sc']['score']})")->implode(', ');
        MatchingAudit::record($action, $offer->reference, $names);
        MatchingNotification::send('Confirmation attendue', $items->pluck('p.name')->implode(', '), 'SMS', "Confirmez votre disponibilité pour {$offer->need->site->name}", 'Contacter');

        return $items->count();
    }

    /**
     * @return array{0: NeedOffer, 1: array<string, mixed>}
     */
    private function item(Request $request, MatchingEngine $engine, NeedOffer $offer): array
    {
        $key = $request->validate(['key' => ['required', 'string']])['key'];
        $offer = $engine->offer($offer->id);
        $item = $engine->item($offer, $key);
        abort_unless($item, 404, 'Profil introuvable.');

        return [$offer, $item];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function names(MatchingEngine $engine, NeedOffer $offer, array $keys): string
    {
        return collect($keys)->map(fn (string $k): ?string => $engine->item($offer, $k)['p']['name'] ?? null)->filter()->implode(', ');
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.*' => 'Ce champ est obligatoire (5 caractères minimum).'])['reason']);
    }
}
