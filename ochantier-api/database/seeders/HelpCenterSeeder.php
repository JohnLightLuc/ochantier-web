<?php

namespace Database\Seeders;

use App\Models\FaqArticle;
use Illuminate\Database\Seeder;

/**
 * Questions fréquentes du centre d’aide des applications Promoteur et Commercial.
 */
class HelpCenterSeeder extends Seeder
{
    /**
     * Application => rubrique => [question => réponse].
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const FAQ = [
        'promoter' => self::PROMOTER_FAQ,
        'commercial' => self::COMMERCIAL_FAQ,
    ];

    /**
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

    /**
     * @var array<string, array<string, string>>
     */
    private const COMMERCIAL_FAQ = [
        'Gains' => [
            'Quand ma commission est-elle versée ?' => 'Elle est confirmée dès la validation du dossier par le Back office, puis versée en fin de mois par Mobile Money.',
        ],
        'Dossiers' => [
            'Pourquoi un dossier est-il à corriger ?' => 'Le Back office a repéré un document illisible, expiré ou manquant. Ouvrez le dossier pour voir la correction demandée.',
        ],
        'Compte' => [
            'Comment changer ma zone d’activité ?' => 'Depuis Profil › Zone d’activité, envoyez une demande. Le changement s’applique après validation de votre responsable.',
        ],
    ];

    public function run(): void
    {
        foreach (self::FAQ as $audience => $sections) {
            foreach ($sections as $section => $articles) {
                foreach ($articles as $question => $answer) {
                    FaqArticle::query()->firstOrCreate(
                        ['audience' => $audience, 'title' => $question],
                        ['section' => $section, 'content' => $answer, 'is_published' => true, 'published_at' => now()],
                    );
                }
            }
        }
    }
}
