<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Landing\ContactMessageRequest;
use App\Http\Requests\V1\Landing\WorkerApplicationRequest;
use App\Support\ApiResponse;
use App\Support\Landing\LandingIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Formulaires du site public, déposés par son serveur (clé partagée) : candidature « Devenir ouvrier », message de
 * contact et inscription à la lettre d’information. Chaque dépôt renvoie sa référence.
 */
class LandingFormController extends Controller
{
    public function __construct(private LandingIntake $intake) {}

    public function application(WorkerApplicationRequest $request): JsonResponse
    {
        $application = $this->intake->application($request->validated(), $request->file('identity_document'), $request->file('photo'));

        return ApiResponse::success(['reference' => $application->reference], 'Candidature reçue.', 201);
    }

    public function message(ContactMessageRequest $request): JsonResponse
    {
        $message = $this->intake->message($request->validated());

        return ApiResponse::success(['reference' => $message->reference], 'Message reçu.', 201);
    }

    public function newsletter(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']], ['email.*' => 'Saisissez une adresse e-mail valide.']);
        $this->intake->subscribe($data['email']);

        return ApiResponse::success(['subscribed' => true], 'Inscription enregistrée.', 201);
    }
}
