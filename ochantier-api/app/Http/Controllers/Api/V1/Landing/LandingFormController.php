<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Landing\ContactMessageRequest;
use App\Http\Requests\V1\Landing\WorkerApplicationRequest;
use App\Support\ApiResponse;
use App\Support\Landing\LandingContent;
use App\Support\Landing\LandingIntake;
use App\Support\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Formulaires du site public, déposés par son serveur (clé partagée) : candidature « Devenir ouvrier », message de
 * contact et inscription à la lettre d’information. Chaque dépôt renvoie sa référence.
 */
class LandingFormController extends Controller
{
    public function __construct(private LandingIntake $intake, private LandingContent $content, private MediaStore $media) {}

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

    /**
     * Contenu en ligne du site (version publiée, formules d’abonnement promoteur actives, fichiers cités).
     */
    public function content(): JsonResponse
    {
        return ApiResponse::success($this->content->published());
    }

    /**
     * Fichier cité par la version en ligne (vidéo, logo, QR code, image de partage).
     */
    public function file(string $file): StreamedResponse
    {
        $media = $this->content->publishedFile($file);
        abort_if($media === null, 404);

        return $this->media->response($media);
    }
}
