<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Promoter\SiteCommunes;
use Illuminate\Console\Command;

/**
 * Chantiers sans commune : la commune est déduite de la position GPS, puis les professionnels sont prévenus des
 * demandes de devis et des offres contractuelles encore ouvertes. Sans --apply, la commande affiche seulement ce
 * qu’elle ferait.
 */
class FillSiteCommunes extends Command
{
    protected $signature = 'ochantier:fill-site-communes {--apply : Enregistre les communes et prévient les professionnels}';

    protected $description = 'Renseigne la commune des chantiers qui n’en ont pas, à partir de leur position GPS';

    public function handle(SiteCommunes $communes): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach (Site::query()->whereNull('commune_id')->orderBy('reference')->get() as $site) {
            $commune = $communes->nearest($site);
            $notified = 0;

            if ($apply && $commune) {
                $communes->fill($site);
                $notified = $communes->notifyOpenRequests($site->refresh());
            }

            $rows[] = [
                $site->reference,
                $site->label,
                $site->status->value,
                $commune ? $commune->name.' ('.$commune->city?->name.')' : 'Aucune à moins de '.SiteCommunes::MAX_DISTANCE_KM.' km (ou pas de GPS)',
                $apply ? ($commune ? $notified.' demande(s) ou offre(s) relayée(s)' : 'Non modifié') : 'Simulation',
            ];
        }

        $this->table(['Chantier', 'Titre', 'Statut', 'Commune déduite', 'Résultat'], $rows);
        $this->info(count($rows).' chantier(s) sans commune'.($apply ? ' traité(s).' : ' : relancez avec --apply pour enregistrer.'));

        return self::SUCCESS;
    }
}
