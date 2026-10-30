<?php

namespace App\Support\Api;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refus ou panne de l’API O’CHANTIER (hors erreurs de validation, converties en erreurs de formulaire).
 *
 * Après une action (POST, PUT…), le message est affiché en notification sur la page précédente. Lors de
 * l’affichage d’une page, la page d’erreur correspondant au statut est rendue.
 */
class ApiException extends HttpException
{
    public const UNAVAILABLE = 'Le service O’CHANTIER est momentanément indisponible. Réessayez dans quelques instants.';

    /**
     * @param  string|null  $errorCode  Code d’erreur de l’API (`permission_denied`, `account_locked`…).
     * @param  array<string, mixed>  $payload  Corps de la réponse.
     */
    public function __construct(int $status, string $message, public readonly ?string $errorCode = null, public readonly array $payload = [])
    {
        parent::__construct($status, $message);
    }

    public static function fromResponse(ClientResponse $response): self
    {
        $payload = (array) $response->json();
        $status = $response->status();

        $message = $status >= 500 ? self::UNAVAILABLE : ($payload['message'] ?? 'La demande n’a pas pu aboutir.');

        return new self($status, $message, $payload['code'] ?? null, $payload);
    }

    public static function unavailable(): self
    {
        return new self(503, self::UNAVAILABLE, 'service_unavailable');
    }

    /**
     * @return RedirectResponse|false Faux : rendu par défaut (page d’erreur du statut).
     */
    public function render(Request $request): RedirectResponse|false
    {
        if ($this->errorCode === 'password_change_required') {
            return to_route('password.change');
        }

        if ($request->isMethod('GET')) {
            return false;
        }

        Inertia::flash('toast', $this->getMessage());

        return back();
    }
}
