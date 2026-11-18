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
        'contractor' => self::CONTRACTOR_FAQ,
    ];

    /**
     * Questions fréquentes de l’application Entrepreneurs et Techniciens.
     *
     * @var array<string, array<string, string>>
     */
    private const CONTRACTOR_FAQ = [
        'Opportunités' => [
            'Pourquoi je ne vois pas certaines demandes ?' => 'Les demandes sont distribuées selon vos métiers validés et votre ville ou zone d’intervention. Complétez votre profil pour en recevoir davantage.',
            'Accepter une offre contractuelle me garantit-il le chantier ?' => 'Non. Le chantier est attribué uniquement après le choix du promoteur et la confirmation de son acompte.',
        ],
        'Paiements' => [
            'Quand suis-je payé pour une visite de devis ?' => 'Après la commande et le paiement confirmé par le promoteur. Les frais de service O’CHANTIER sont à sa charge.',
            'Comment suis-je payé sur un chantier attribué ?' => 'Le promoteur règle chaque échéance de votre forfait via O’CHANTIER. Vous suivez votre part nette dans l’onglet Paiements du chantier.',
        ],
        'Équipe et pointage' => [
            'Comment pointer mon équipe ?' => 'Dans l’onglet Pointage du chantier, enregistrez l’arrivée et le départ de chaque ouvrier avec sa position, une photo et sa signature, puis validez la journée.',
        ],
        'Abonnement' => [
            'Que deviennent mes données si mon abonnement expire ?' => 'Elles restent consultables. Les nouvelles saisies (équipe, pointage, rapport) nécessitent un abonnement actif.',
        ],
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
