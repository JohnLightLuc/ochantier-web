<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * « Règles & commissions » d’O’CHANTIER PRO : chaque réglage modifié est enregistré dans les paramètres de la
 * plateforme (api/v1/admin/settings/values), avec le même motif, dans l’historique et le journal d’audit.
 */
class ProRulesController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Indiquez le motif de la modification.', 'reason.min' => 'Le motif est trop court.']);
        $current = collect(app(ContractorController::class)->rules())->keyBy('key');
        $changed = 0;

        foreach (ContractorController::RULES as $key) {
            $field = str_replace('.', '_', $key);
            $value = $request->input($field);

            if ($value === null || $value === '' || (string) $value === (string) ($current[$key]['value'] ?? '')) {
                continue;
            }

            try {
                $this->api->post('settings/values', ['key' => $key, 'value' => (int) $value, 'reason' => trim((string) $request->input('reason'))]);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([$field => collect($e->errors())->flatten()->first()]);
            }

            $changed++;
        }

        Inertia::flash($changed ? 'toast' : 'toastError', $changed ? ($changed > 1 ? $changed.' réglages enregistrés.' : 'Réglage enregistré.') : 'Aucune valeur n’a changé.');

        return back();
    }
}
