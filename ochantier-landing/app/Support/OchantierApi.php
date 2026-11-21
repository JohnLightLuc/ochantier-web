<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Transmission des formulaires du site à l’API O’CHANTIER, avec la clé partagée (en-tête X-Landing-Key). Les
 * champs de l’API sont traduits depuis ceux du formulaire, et ses erreurs reviennent sur les champs du formulaire.
 */
class OchantierApi
{
    /**
     * Champ de l’API => champ du formulaire, pour renvoyer une erreur au bon endroit.
     */
    private const FORM_FIELDS = [
        'first_name' => 'prenom', 'last_name' => 'nom', 'phone' => 'tel', 'birth_date' => 'naissance', 'gender' => 'genre',
        'identity_document' => 'cni', 'trade' => 'metier', 'daily_rate' => 'tarif', 'experience_years' => 'exp', 'skills' => 'sec',
        'certifications' => 'cert', 'neighborhood' => 'quartier', 'mobility' => 'radius', 'availability' => 'dispo',
        'available_days' => 'days', 'payout_method' => 'pay', 'payout_number' => 'paynum', 'target_site' => 'chantier',
        'target_site_commune' => 'chantier_commune', 'profile' => 'profil', 'name' => 'nom', 'company' => 'entreprise',
        'subject' => 'objet',
    ];

    /**
     * Candidature « Devenir ouvrier » : renvoie la référence attribuée par l’API.
     *
     * @param  array<string, mixed>  $data  Champs validés du formulaire.
     */
    public function workerApplication(array $data, UploadedFile $identity, ?UploadedFile $photo): string
    {
        $fields = array_filter([
            'first_name' => $data['prenom'], 'last_name' => $data['nom'], 'phone' => $data['tel'], 'email' => $data['email'] ?? null,
            'birth_date' => $data['naissance'] ?? null, 'gender' => $data['genre'] ?? null, 'trade' => $data['metier'],
            'daily_rate' => $data['tarif'] ?? null, 'experience_years' => $data['exp'], 'level' => $data['level'],
            'skills' => $data['sec'] ?? [], 'certifications' => $data['cert'] ?? [], 'bio' => $data['bio'] ?? null,
            'commune' => $data['commune'], 'neighborhood' => $data['quartier'] ?? null, 'mobility' => $data['radius'],
            'availability' => $data['dispo'], 'available_days' => $data['days'] ?? [], 'payout_method' => $data['pay'],
            'payout_number' => $data['paynum'] ?? null, 'target_site' => $data['chantier'] ?? null,
            'target_site_commune' => $data['chantier_commune'] ?? null, 'consent' => '1',
        ], fn ($value) => $value !== null && $value !== []);

        $request = $this->client()->attach('identity_document', fopen($identity->getRealPath(), 'r'), $identity->getClientOriginalName());

        if ($photo) {
            $request = $request->attach('photo', fopen($photo->getRealPath(), 'r'), $photo->getClientOriginalName());
        }

        return $this->reference($this->send(fn () => $request->post('landing/worker-applications', $this->multipart($fields))));
    }

    /**
     * Message du formulaire de contact : renvoie la référence attribuée par l’API.
     *
     * @param  array<string, mixed>  $data  Champs validés du formulaire.
     */
    public function contactMessage(array $data): string
    {
        return $this->reference($this->send(fn () => $this->client()->asJson()->post('landing/contact-messages', array_filter([
            'profile' => $data['profil'], 'name' => $data['nom'], 'company' => $data['entreprise'] ?? null, 'phone' => $data['tel'],
            'email' => $data['email'], 'subject' => $data['objet'], 'message' => $data['message'], 'consent' => true,
        ], fn ($value) => $value !== null))));
    }

    public function newsletter(string $email): void
    {
        $this->send(fn () => $this->client()->asJson()->post('landing/newsletter', ['email' => $email]));
    }

    /**
     * Contenu en ligne du site (version publiée dans le Back Office), ou null si l’API ne répond pas.
     *
     * @return array<string, mixed>|null
     */
    public function content(): ?array
    {
        try {
            $response = $this->client()->timeout((int) config('services.ochantier_api.content_timeout', 4))->get('landing/content');
        } catch (ConnectionException $e) {
            Log::warning('Contenu du site : API O’CHANTIER injoignable', ['erreur' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data.content.sections'))) {
            Log::warning('Contenu du site : réponse inattendue de l’API', ['statut' => $response->status()]);

            return null;
        }

        return $response->json('data');
    }

    /**
     * Fichier publié (vidéo, logo, QR code, image de partage), ou null s’il n’est pas disponible.
     */
    public function file(string $id): ?Response
    {
        try {
            $response = $this->client()->timeout(60)->get("landing/files/{$id}");
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.ochantier_api.url'))
            ->acceptJson()
            ->withHeaders(['X-Landing-Key' => (string) config('services.ochantier_api.key')])
            ->timeout((int) config('services.ochantier_api.timeout', 15));
    }

    /**
     * Envoie la requête : une erreur de saisie revient sur le champ du formulaire ; une API injoignable ou en
     * erreur devient une erreur « service indisponible ».
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::error('API O’CHANTIER injoignable', ['erreur' => $e->getMessage()]);

            throw new ServiceUnavailable;
        }

        if ($response->status() === 422) {
            $errors = collect((array) $response->json('errors'))->mapWithKeys(fn ($messages, string $field) => [self::FORM_FIELDS[strtok($field, '.')] ?? strtok($field, '.') => (array) $messages]);

            throw ValidationException::withMessages($errors->all());
        }

        if (! $response->successful()) {
            Log::error('API O’CHANTIER en erreur', ['statut' => $response->status(), 'message' => $response->json('message')]);

            throw new ServiceUnavailable;
        }

        return $response;
    }

    private function reference(Response $response): string
    {
        return (string) $response->json('data.reference');
    }

    /**
     * Champs d’un envoi multipart : les listes deviennent des champs « nom[] ».
     *
     * @param  array<string, mixed>  $fields
     * @return list<array{name: string, contents: string}>
     */
    private function multipart(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $parts[] = ['name' => is_array($value) ? $name.'[]' : $name, 'contents' => (string) $item];
            }
        }

        return $parts;
    }
}
