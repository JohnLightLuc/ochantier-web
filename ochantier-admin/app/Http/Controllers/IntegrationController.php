<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\Payment;
use App\Models\SettingChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Intégrations : test de connexion (simulé à partir de l’état du service) et suspension ou réactivation.
 */
class IntegrationController extends Controller
{
    private const SECTION = 'Intégrations';

    public function test(Integration $integration): RedirectResponse
    {
        if ($integration->status === 'Suspendu') {
            throw ValidationException::withMessages(['integration' => 'Réactivez l’intégration avant de la tester.']);
        }
        $expired = $integration->expires_on->lt(Payment::referenceDate());
        $result = $expired ? 'HTTP 401 · certificat expiré' : 'HTTP 200 · '.($integration->latency ?: 200).' ms';
        $integration->update([
            'tested_at' => now(), 'test_ok' => ! $expired, 'last_result' => $result,
            'status' => $expired ? 'Erreur' : ($integration->status === 'Erreur' ? 'Actif' : $integration->status),
        ]);
        SettingChange::record(self::SECTION, "Test de connexion {$integration->name} ".($expired ? 'en échec' : 'réussi'), null, $result);
        Inertia::flash('toast', $expired ? "Échec du test : {$integration->name} · certificat expiré." : "Connexion à {$integration->name} réussie.");

        return back();
    }

    public function status(Integration $integration): RedirectResponse
    {
        $old = $integration->status;
        $integration->update(['status' => $old === 'Suspendu' ? ($integration->test_ok ? 'Actif' : 'Erreur') : 'Suspendu']);
        SettingChange::record(self::SECTION, "{$integration->name} ".($integration->status === 'Suspendu' ? 'suspendue' : 'réactivée'), $old, $integration->status);
        Inertia::flash('toast', "{$integration->name} ".($integration->status === 'Suspendu' ? 'suspendue.' : 'réactivée.'));

        return back();
    }
}
