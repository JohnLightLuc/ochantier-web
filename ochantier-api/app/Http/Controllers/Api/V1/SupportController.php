<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Coordonnées du support, publiques : affichables avant la connexion (écrans de connexion, d’inscription, d’aide).
 * Les valeurs se règlent dans le Back Office (Paramètres › Support).
 */
class SupportController extends Controller
{
    /**
     * Clé du réglage => champ de la réponse.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'support.opening_days' => 'opening_days',
        'support.working_hours' => 'working_hours',
        'support.phone' => 'phone',
        'support.whatsapp' => 'whatsapp',
        'support.email' => 'email',
    ];

    public function __invoke(): JsonResponse
    {
        $settings = Setting::query()->whereIn('key', array_keys(self::FIELDS))->pluck('value', 'key');

        return ApiResponse::success(
            collect(self::FIELDS)->mapWithKeys(fn (string $field, string $key) => [$field => $settings[$key] ?? null])->all(),
            headers: ['Cache-Control' => 'public, max-age=3600'],
        );
    }
}
