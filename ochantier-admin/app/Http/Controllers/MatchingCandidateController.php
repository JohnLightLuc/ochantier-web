<?php

namespace App\Http\Controllers;

use App\Models\MatchingAudit;
use App\Models\MatchingDerogation;
use App\Models\MatchingNotification;
use App\Models\MatchingRecalculation;
use App\Models\MatchingRejection;
use App\Models\NeedOffer;
use App\Support\MatchingEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Décisions sur un profil recommandé : rejet, dérogation, recalcul du score, archivage et demande de vérification.
 */
class MatchingCandidateController extends Controller
{
    public const REJECTION_REASONS = ['Profil non adapté', 'Expérience insuffisante', 'Indisponibilité', 'Distance excessive', 'Coût trop élevé', 'Document manquant', 'Certification absente', 'Conflit de planning', 'Historique insatisfaisant', 'Refus du promoteur', 'Refus de l’ouvrier', 'Doublon', 'Erreur de données', 'Autre motif'];

    public const DEROGATION_REASONS = ['Urgence chantier', 'Accord écrit du promoteur', 'Document en cours de renouvellement', 'Autre'];

    public const RECALCULATION_REASONS = ['Disponibilité', 'Métier', 'Expérience', 'Localisation', 'Coût', 'Certification', 'Note', 'Statut du profil', 'Dates du besoin', 'Budget', 'Nombre de postes', 'Paramètres de Matching'];

    /**
     * Rejette une recommandation : le profil sort de la sélection ; les pondérations ne changent pas.
     */
    public function reject(Request $request, MatchingEngine $engine, NeedOffer $offer, string $key): RedirectResponse
    {
        [$offer, $item] = $this->item($engine, $offer, $key);
        $data = $request->validate([
            'reason' => ['required', Rule::in(self::REJECTION_REASONS)],
            'nature' => ['required', Rule::in(['Temporaire', 'Définitif'])],
            'criterion' => ['nullable', 'string', 'max:120'],
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
            'corrective_action' => ['nullable', 'string', 'max:255'],
        ], ['comment.*' => 'Le commentaire est obligatoire (5 caractères minimum).']);

        $this->removeFromSelection($engine, $offer, $key);
        MatchingRejection::updateOrCreate(['need_offer_id' => $offer->id, 'candidate' => $key], [...$data, 'comment' => trim($data['comment']), 'author' => $request->user()?->name ?? 'Système', 'rejected_on' => $engine->today()]);
        MatchingAudit::record('a rejeté une recommandation', $item['p']['name'], "{$data['reason']} · {$data['nature']} · ".trim($data['comment']));
        MatchingNotification::send('Match rejeté', 'Responsable Matching', 'Notification interne', "{$item['p']['name']} · {$offer->reference} · {$data['reason']}", 'Voir le match');
        Inertia::flash('toast', 'Recommandation rejetée ('.mb_strtolower($data['nature']).').');

        return back();
    }

    /**
     * Dérogation à un critère bloquant (jamais à une interdiction absolue), inscrite au journal d’audit.
     */
    public function derogation(Request $request, MatchingEngine $engine, NeedOffer $offer, string $key): RedirectResponse
    {
        [$offer, $item] = $this->item($engine, $offer, $key);
        abort_unless($item['sc']['blocked'], 422, 'Ce profil n’est pas bloqué.');
        $absolute = collect($item['sc']['blocking'])->firstWhere('absolute', true);
        if ($absolute) {
            abort(422, 'Dérogation impossible : interdiction de sécurité absolue ('.mb_strtolower($absolute['criterion']).').');
        }

        $data = $request->validate([
            'motive' => ['required', Rule::in(self::DEROGATION_REASONS)],
            'justification' => ['required', 'string', 'min:10', 'max:1000'],
        ], ['justification.*' => 'La justification est obligatoire (10 caractères minimum).']);
        $why = "{$data['motive']} — ".trim($data['justification']);

        MatchingDerogation::updateOrCreate(['need_offer_id' => $offer->id, 'candidate' => $key], ['justification' => $why, 'author' => $request->user()?->name ?? 'Système', 'granted_on' => $engine->today()]);
        MatchingAudit::record('a accordé une dérogation', $item['p']['name'], $why);
        Inertia::flash('toast', 'Dérogation accordée · profil sélectionnable avec avertissement.');

        return back();
    }

    /**
     * Recalcule le score ; l’ancien score est conservé dans l’historique.
     */
    public function recalculate(Request $request, NeedOffer $offer, string $key): RedirectResponse
    {
        $engine = new MatchingEngine;
        [$offer, $item] = $this->item($engine, $offer, $key);
        $reason = $request->validate(['reason' => ['required', Rule::in(self::RECALCULATION_REASONS)]])['reason'];
        $config = MatchingEngine::config();

        $offer->update(['last_matched_on' => $engine->today(), 'last_matched_by' => ($request->user()?->name ?? 'Système').' (recalcul)']);
        $fresh = (new MatchingEngine)->find($item['id']);
        $new = $fresh ? $fresh['item']['sc']['score'] : $item['sc']['score'];
        MatchingRecalculation::create([
            'match_reference' => $item['id'], 'recalculated_at' => now(), 'old_score' => $item['sc']['score'], 'new_score' => $new, 'reason' => $reason,
            'old_weights' => $config['weights_version'], 'new_weights' => $config['weights_version'], 'author' => $request->user()?->name ?? 'Système', 'algorithm' => $config['algorithm'],
        ]);
        MatchingAudit::record('a recalculé un score', $item['p']['name'], "{$reason} · {$item['sc']['score']} → {$new}");
        MatchingNotification::send('Score recalculé', $request->user()?->name ?? 'Système', 'Notification interne', "{$item['p']['name']} · {$item['sc']['score']} → {$new}", 'Voir le match');
        Inertia::flash('toast', 'Score recalculé · historique conservé.');

        return back();
    }

    public function archive(Request $request, MatchingEngine $engine, NeedOffer $offer, string $key): RedirectResponse
    {
        [$offer, $item] = $this->item($engine, $offer, $key);

        $this->removeFromSelection($engine, $offer, $key);
        MatchingRejection::updateOrCreate(['need_offer_id' => $offer->id, 'candidate' => $key], ['reason' => 'Archivé', 'nature' => 'Définitif', 'comment' => 'Archivage administratif', 'author' => $request->user()?->name ?? 'Système', 'rejected_on' => $engine->today()]);
        MatchingAudit::record('a archivé une recommandation', $item['id'], $item['p']['name']);
        Inertia::flash('toast', 'Recommandation archivée.');

        return back();
    }

    public function verification(MatchingEngine $engine, NeedOffer $offer, string $key): RedirectResponse
    {
        [, $item] = $this->item($engine, $offer, $key);

        MatchingNotification::send('Vérification demandée', 'Module Ouvriers', 'Notification interne', "Vérifier le dossier de {$item['p']['name']}", 'Vérifier');
        MatchingAudit::record('a demandé une vérification', $item['p']['name'], "Dossier {$item['p']['dossier']} %");
        Inertia::flash('toast', 'Demande de vérification envoyée au module Ouvriers.');

        return back();
    }

    private function removeFromSelection(MatchingEngine $engine, NeedOffer $offer, string $key): void
    {
        $selection = $engine->selection($offer);
        if (in_array($key, $selection['main'], true) || isset($selection['replacements'][$key])) {
            $replacements = $selection['replacements'];
            unset($replacements[$key]);
            $engine->saveSelection($offer, ['main' => array_diff($selection['main'], [$key]), 'replacements' => $replacements]);
        }
    }

    /**
     * @return array{0: NeedOffer, 1: array<string, mixed>}
     */
    private function item(MatchingEngine $engine, NeedOffer $offer, string $key): array
    {
        $offer = $engine->offer($offer->id);
        $item = $engine->item($offer, $key);
        abort_unless($item, 404, 'Profil introuvable.');

        return [$offer, $item];
    }
}
