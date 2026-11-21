<?php

namespace App\Console\Commands;

use App\Models\ContactRequest;
use App\Models\NewsletterSubscriber;
use App\Models\WorkerApplication;
use App\Support\OchantierApi;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transmet une fois à l’API O’CHANTIER les demandes enregistrées sur le site avant le branchement de ses
 * formulaires sur l’API (candidatures avec leurs pièces, messages de contact, inscrits à la newsletter).
 */
class ForwardStoredForms extends Command
{
    protected $signature = 'landing:transmettre';

    protected $description = 'Transmet à l’API O’CHANTIER les demandes reçues avant le branchement du site sur l’API';

    public function handle(OchantierApi $api): int
    {
        $failures = 0;

        foreach (ContactRequest::query()->whereNull('reference_api')->orderBy('created_at')->get() as $contact) {
            $failures += $this->forward("Message {$contact->reference}", fn () => $contact->update(['reference_api' => $api->contactMessage([
                'profil' => $contact->profil, 'nom' => $contact->nom, 'entreprise' => $contact->entreprise, 'tel' => $contact->telephone,
                'email' => $contact->email, 'objet' => $contact->objet, 'message' => $contact->message,
            ])]));
        }

        foreach (WorkerApplication::query()->whereNull('reference_api')->orderBy('created_at')->get() as $application) {
            $failures += $this->forward("Candidature {$application->reference}", fn () => $application->update(['reference_api' => $api->workerApplication([
                'prenom' => $application->prenom, 'nom' => $application->nom, 'tel' => $application->telephone, 'email' => $application->email,
                'naissance' => $application->date_naissance?->toDateString(), 'genre' => $application->genre, 'metier' => $application->metier,
                'tarif' => $application->tarif_journalier, 'exp' => $application->experience_annees, 'level' => $application->niveau,
                'sec' => $application->competences, 'cert' => $application->certifications, 'bio' => $application->presentation,
                'commune' => $application->commune, 'quartier' => $application->quartier, 'radius' => $application->rayon_deplacement,
                'dispo' => $application->disponibilite, 'days' => $application->jours_disponibles, 'pay' => $application->moyen_paiement,
                'paynum' => $application->numero_paiement, 'chantier' => $application->chantier_cible, 'chantier_commune' => $application->chantier_commune,
            ], $this->file($application->cni_path), $application->photo_path ? $this->file($application->photo_path) : null)]));
        }

        foreach (NewsletterSubscriber::query()->whereNull('reference_api')->whereNull('desinscrit_le')->get() as $subscriber) {
            $failures += $this->forward("Inscrit {$subscriber->email}", function () use ($api, $subscriber): void {
                $api->newsletter($subscriber->email);
                $subscriber->update(['reference_api' => 'transmis']);
            });
        }

        $this->components->info($failures ? "Terminé avec {$failures} échec(s) : relancez la commande." : 'Toutes les demandes sont transmises.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function forward(string $label, callable $send): int
    {
        try {
            $send();
            $this->components->twoColumnDetail($label, 'transmis');

            return 0;
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($label, '<fg=red>échec : '.$e->getMessage().'</>');

            return 1;
        }
    }

    private function file(string $path): UploadedFile
    {
        return new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true);
    }
}
