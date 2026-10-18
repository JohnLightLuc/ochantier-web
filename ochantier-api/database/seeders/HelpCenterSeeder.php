<?php

namespace Database\Seeders;

use App\Models\FaqArticle;
use Illuminate\Database\Seeder;

/**
 * Questions fréquentes du centre d’aide de l’application Promoteur.
 */
class HelpCenterSeeder extends Seeder
{
    /**
     * Rubrique => [question => réponse].
     *
     * @var array<string, array<string, string>>
     */
    private const PROMOTER_FAQ = [
        'Réservations' => [
            'Comment réserver des ouvriers ?' => 'Depuis Réservations, touchez « + Réserver », choisissez le chantier, indiquez vos besoins par métier ou choisissez directement des ouvriers, puis publiez.',
            'Puis-je remplacer un ouvrier ?' => 'Oui, depuis le détail du chantier en cours, section Équipe, touchez « Remplacer » et choisissez un remplaçant disponible.',
        ],
        'Paiements' => [
            'Quand l’acompte est-il prélevé ?' => 'L’acompte de 30 % est réglé à la validation d’une offre. La réservation est confirmée dès le paiement.',
        ],
        'Chantiers' => [
            'Comment fonctionne le pointage ?' => 'Chaque arrivée et départ comprend la date, l’heure, la position sur le chantier, une photo et la signature de l’ouvrier. Vous validez la journée à la fin.',
        ],
        'Compte' => [
            'Pourquoi vérifier mon profil ?' => 'Vos pièces justificatives permettent à O’CHANTIER de garantir aux ouvriers des promoteurs identifiés. Dès que tous les documents demandés sont fournis, votre profil est transmis pour vérification.',
        ],
    ];

    public function run(): void
    {
        foreach (self::PROMOTER_FAQ as $section => $articles) {
            foreach ($articles as $question => $answer) {
                FaqArticle::query()->firstOrCreate(
                    ['audience' => 'promoter', 'title' => $question],
                    ['section' => $section, 'content' => $answer, 'is_published' => true, 'published_at' => now()],
                );
            }
        }
    }
}
