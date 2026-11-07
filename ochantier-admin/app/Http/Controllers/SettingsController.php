<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres, lus dans l’API (api/v1/admin/settings) : seuls les réglages que le code applique réellement.
 */
class SettingsController extends Controller
{
    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $d = $this->api->get('settings')->json('data');
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);
        $date = fn (array $row): array => [...$row, 'effective_from' => $this->moment($row['effective_from']), 'effective_to' => $this->moment($row['effective_to'])];

        return Inertia::render('Settings/Index', [
            'groups' => $d['groups'],
            'pricing' => ['current' => $d['pricing']['current'], 'pending' => array_map($date, $d['pricing']['pending']), 'history' => array_map($date, $d['pricing']['history'])],
            'matching' => $d['matching'],
            'trades' => $d['trades'],
            'integrations' => ['runtime' => $d['integrations']['runtime'], 'providers' => array_map(fn (array $p): array => [...$p, 'last_tested_at' => $this->moment($p['last_tested_at'])], $d['integrations']['providers'])],
            'history' => array_map(fn (array $h): array => [...$h, 'date' => $this->moment($h['date'])], $d['history']),
            'can' => ['update' => $allowed('settings.update'), 'approve' => $allowed('settings.approve'), 'admins' => $allowed('admins.read'), 'audit' => $allowed('audit.read')],
            'adminId' => $user instanceof AdminUser ? $user->getAuthIdentifier() : null,
        ]);
    }

    public function saveValue(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('settings/values', $request->only('key', 'value', 'reason')));
    }

    public function saveMatching(Request $request): RedirectResponse
    {
        $weights = [];

        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'weight_')) {
                $weights[substr($key, 7)] = (int) $value;
            }
        }

        return $this->act(fn () => $this->api->post('settings/matching', [...$request->only('radius', 'radius_max', 'tolerance_max', 'reason'), 'weights' => $weights]));
    }

    public function proposeRate(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('settings/rates', $request->only('code', 'value', 'effective_from', 'reason')));
    }

    public function approveRate(string $rate): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("settings/rates/{$rate}/approve"));
    }

    public function refuseRate(Request $request, string $rate): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("settings/rates/{$rate}/refuse", $request->only('reason')));
    }

    public function storeTrade(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('settings/trades', $request->only('label')));
    }

    public function updateTrade(Request $request, string $trade): RedirectResponse
    {
        return $this->act(fn () => $this->api->patch("settings/trades/{$trade}", $request->only('label')));
    }

    public function toggleTrade(string $trade): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("settings/trades/{$trade}/toggle"));
    }

    /**
     * Les anciennes sections renvoient vers la page unique des paramètres.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette section a été remplacée : voici les paramètres.');

        return to_route('settings.index');
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['pricing', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
