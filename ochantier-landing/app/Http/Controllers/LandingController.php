<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\StoreWorkerApplicationRequest;
use App\Support\OchantierApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Site public O’CHANTIER : la page, et ses formulaires (contact, newsletter, « Devenir ouvrier »). Chaque formulaire
 * est validé ici, puis transmis à l’API O’CHANTIER, qui l’enregistre pour le Back Office et donne sa référence.
 */
class LandingController extends Controller
{
    public function __construct(private OchantierApi $api) {}

    public function index(): View
    {
        return view('landing');
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
