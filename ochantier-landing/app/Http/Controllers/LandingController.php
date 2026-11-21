<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\StoreWorkerApplicationRequest;
use App\Support\OchantierApi;
use App\Support\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Site public O’CHANTIER : la page, et ses formulaires (contact, newsletter, « Devenir ouvrier »). Chaque formulaire
 * est validé ici, puis transmis à l’API O’CHANTIER, qui l’enregistre pour le Back Office et donne sa référence.
 */
class LandingController extends Controller
{
    public function __construct(private OchantierApi $api, private SiteContent $content) {}

    /**
     * La page, avec le contenu publié dans le Back Office (ou, si l’API ne répond pas, la dernière version reçue).
     */
    public function index(): View
    {
        $site = $this->content->current();
        $files = array_map(fn (string $id) => route('landing.file', $id), array_combine(array_keys($site['files']), array_keys($site['files'])));

        return view('landing', [
            'seo' => $site['content']['sections']['seo'],
            'shareImage' => $files[$site['content']['sections']['seo']['og_image'] ?? ''] ?? asset('assets/app-accueil.webp'),
            'site' => ['version' => $site['version'], 'order' => $site['content']['order'], 'hidden' => $site['content']['hidden'], 'sections' => $site['content']['sections'], 'plans' => $site['plans'], 'files' => (object) $files],
        ]);
    }

    /**
     * Fichier publié (vidéo, logo, QR code, image de partage), recopié depuis l’API à sa première demande.
     */
    public function file(string $id): BinaryFileResponse
    {
        $file = $this->content->file($id);
        abort_if($file === null, 404);

        return response()->file($file[0], ['Content-Type' => $file[1], 'Cache-Control' => 'public, max-age=86400']);
    }

    public function contact(StoreContactRequest $request): JsonResponse
    {
        return response()->json(['reference' => $this->api->contactMessage($request->validated())], 201);
    }

    public function newsletter(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'email', 'max:190']],
            ['email.*' => 'Saisissez une adresse e-mail valide.'],
        );

        $this->api->newsletter(mb_strtolower($data['email']));

        return response()->json(['ok' => true], 201);
    }

    public function worker(StoreWorkerApplicationRequest $request): JsonResponse
    {
        $reference = $this->api->workerApplication($request->validated(), $request->file('cni'), $request->file('photo'));

        return response()->json(['reference' => $reference], 201);
    }
}
