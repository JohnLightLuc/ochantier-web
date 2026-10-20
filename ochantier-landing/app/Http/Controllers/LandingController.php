<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\StoreWorkerApplicationRequest;
use App\Models\ContactRequest;
use App\Models\NewsletterSubscriber;
use App\Models\WorkerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function index(): View
    {
        return view('landing');
    }

    public function contact(StoreContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        $contact = ContactRequest::create([
            'profil' => $data['profil'],
            'nom' => $data['nom'],
            'entreprise' => $data['entreprise'] ?? null,
            'telephone' => $data['tel'],
            'email' => $data['email'],
            'objet' => $data['objet'],
            'message' => $data['message'],
        ]);

        return response()->json(['reference' => $contact->reference], 201);
    }

    public function newsletter(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'email', 'max:190']],
            ['email.*' => 'Saisissez une adresse e-mail valide.'],
        );

        NewsletterSubscriber::updateOrCreate(
            ['email' => mb_strtolower($data['email'])],
            ['desinscrit_le' => null],
        );

        return response()->json(['ok' => true], 201);
    }

    public function worker(StoreWorkerApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Pièces privées : jamais exposées dans public/.
        $cni = $request->file('cni')->store('ouvriers/cni', 'local');
        $photo = $request->file('photo')?->store('ouvriers/photos', 'local');

        $application = WorkerApplication::create([
            'prenom' => $data['prenom'],
            'nom' => $data['nom'],
            'telephone' => $data['tel'],
            'email' => $data['email'] ?? null,
            'date_naissance' => $data['naissance'] ?? null,
            'genre' => $data['genre'] ?? null,
            'photo_path' => $photo,
            'cni_path' => $cni,
            'metier' => $data['metier'],
            'tarif_journalier' => $data['tarif'] ?? null,
            'experience_annees' => $data['exp'],
            'niveau' => $data['level'],
            'competences' => $data['sec'] ?? [],
            'certifications' => $data['cert'] ?? [],
            'presentation' => $data['bio'] ?? null,
            'commune' => $data['commune'],
            'quartier' => $data['quartier'] ?? null,
            'rayon_deplacement' => $data['radius'],
            'disponibilite' => $data['dispo'],
            'jours_disponibles' => $data['days'] ?? [],
            'moyen_paiement' => $data['pay'],
            'numero_paiement' => $data['paynum'] ?? null,
            'chantier_cible' => $data['chantier'] ?? null,
            'chantier_commune' => $data['chantier_commune'] ?? null,
        ]);

        return response()->json(['reference' => $application->reference], 201);
    }
}
