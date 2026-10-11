<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\Promoter;
use App\Models\QualityActivity;
use App\Models\QualityNotification;
use App\Models\Review;
use App\Models\Worker;
use App\Support\QualityInsights;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Avis & litiges de la maquette :
 * - avis des promoteurs sur un tiers des prestations payées (statuts de modération, droits de réponse,
 *   signalements, décisions), avis d’équipe, six avis suspects en attente de modération (détections automatiques),
 *   un doublon bloqué, une affectation annulée, un brouillon, deux tests et six évaluations internes ;
 * - 22 litiges couvrant tout le cycle (recevabilité, contradictoire, médiation, décision, exécution, recours) avec
 *   parties, déclarations, preuves, demandes transmises à Paiements, Affectations et Pointages, et journal ;
 * - 14 réclamations, dont 4 transformées en litige ;
 * - notifications et journal d’audit.
 */
class QualitySeeder extends Seeder
{
    private const POSITIVE = ['Travail soigné et livré dans les temps, rien à reprendre.', 'Très ponctuel, respecte les consignes du chef de chantier.', 'Bonne communication, signale rapidement les problèmes.', 'Finitions propres, poste de travail rangé chaque soir.', 'Rendement régulier sur toute la semaine, je recommande.', 'Maîtrise son métier et a proposé une solution pour le ferraillage.', 'Respect strict des règles de sécurité, port des EPI constant.'];

    private const MIXED = ['Travail correct mais quelques retards le matin.', 'Bonne volonté, finitions à améliorer sur les angles.', 'Communication à renforcer avec le conducteur de travaux.'];

    private const NEGATIVE = ['Finitions à reprendre sur deux logements, retard de trois jours.', 'Absences non signalées en début de semaine, planning décalé.', 'Consignes de sécurité non respectées sur l’échafaudage.'];

    private const ELIGIBLE = ['auth' => true, 'linked' => true, 'affConf' => true, 'done' => true, 'ptClosed' => true, 'target' => true, 'window' => true, 'noDup' => true, 'self' => true];

    /**
     * Litiges : catégorie, sous-catégorie, objet, demandeur (P promoteur, O ouvrier), statut, priorité, gravité, part du
     * montant contestée, source, médiation, issue, ancienneté (jours), référence imposée.
     *
     * @var array<int, array<int, mixed>>
     */
    private const DISPUTES = [
        ['Retard d’exécution', 'Lot électricité', 'Retard de livraison du lot électricité', 'P', 'Analyse en cours', 'Élevée', 'Majeure', 0.6, 'Réclamation', 'Non proposée', '', 14, 'LIT-0102'],
        ['Paiement non reçu', 'Paiement journalier', 'Paiement journalier contesté', 'O', 'Résolu', 'Normale', 'Modérée', 1, 'Paiement', 'Non proposée', 'Paiement libéré', 52, 'LIT-0087'],
        ['Qualité du travail', 'Finitions', 'Finitions de carrelage non conformes', 'P', 'Médiation en cours', 'Normale', 'Modérée', 0.4, 'Avis contesté', 'En cours', '', 11, null],
        ['Pointage contesté', 'Heures supplémentaires', 'Heures supplémentaires non reconnues', 'O', 'Réponse de la partie adverse attendue', 'Normale', 'Mineure', 0.15, 'Pointage', 'Non proposée', '', 4, null],
        ['Abandon de chantier', 'Départ sans préavis', 'Abandon du chantier en cours de lot', 'P', 'Décision en préparation', 'Urgente', 'Grave', 0.8, 'Affectation', 'Échec', '', 9, null],
        ['Sécurité', 'Travail en hauteur', 'Travail en hauteur sans harnais', 'P', 'Ouvert', 'Critique', 'Critique', 0, 'Chantier', 'Non proposée', '', 1, null],
        ['Dommage matériel', 'Bétonnière', 'Bétonnière endommagée pendant le coulage', 'P', 'En attente d’exécution', 'Normale', 'Modérée', 0.5, 'Réclamation', 'Non proposée', 'Demande partiellement acceptée', 30, null],
        ['Retenue contestée', 'Retenue pour absence', 'Retenue pour absence contestée', 'O', 'Recours ouvert', 'Normale', 'Modérée', 0.2, 'Paiement', 'Non proposée', 'Demande rejetée', 40, null],
        ['Montant incorrect', 'Taux journalier', 'Taux journalier inférieur au contrat', 'O', 'Accord trouvé', 'Normale', 'Mineure', 0.25, 'Paiement', 'Accord validé', 'Accord amiable', 22, null],
        ['Absence', 'Absences répétées', 'Absences répétées non justifiées', 'P', 'Informations demandées', 'Élevée', 'Majeure', 0.3, 'Pointage', 'Non proposée', '', 6, null],
        ['Avis contesté', 'Avis négatif', 'Avis jugé diffamatoire par l’ouvrier', 'O', 'Analyse en cours', 'Normale', 'Mineure', 0, 'Avis contesté', 'Non proposée', '', 5, null],
        ['Commission contestée', 'Frais de service', 'Frais de service appliqués deux fois', 'P', 'Clôturé', 'Normale', 'Modérée', 0.08, 'Paiement', 'Non proposée', 'Ajustement financier demandé', 70, null],
        ['Fraude présumée', 'Faux pointage', 'Pointages réalisés hors zone de chantier', 'P', 'Recevabilité en cours', 'Urgente', 'Grave', 0.5, 'Pointage', 'Non proposée', '', 2, null],
        ['Comportement', 'Altercation', 'Altercation avec le chef d’équipe', 'P', 'Médiation proposée', 'Élevée', 'Majeure', 0, 'Chantier', 'En attente d’acceptation', '', 7, null],
        ['Travail incomplet', 'Enduits', 'Enduits non terminés à la fin du contrat', 'P', 'Réexamen', 'Normale', 'Modérée', 0.35, 'Réclamation', 'Échec', 'Demande acceptée', 45, null],
        ['Remboursement', 'Acompte', 'Remboursement d’acompte non versé', 'P', 'Décision rendue', 'Élevée', 'Majeure', 0.9, 'Paiement', 'Non proposée', 'Remboursement demandé', 18, null],
        ['Matériel perdu', 'Outillage', 'Perforateur disparu sur le chantier', 'O', 'Non recevable', 'Faible', 'Mineure', 0.1, 'Réclamation', 'Non proposée', '', 25, null],
        ['Heures contestées', 'Journée incomplète', 'Demi-journée décomptée à tort', 'O', 'Résolu', 'Faible', 'Mineure', 0.1, 'Pointage', 'Non proposée', 'Correction de pointage demandée', 33, null],
        ['Remplacement', 'Indisponibilité', 'Remplaçant non proposé après désistement', 'P', 'En attente d’exécution', 'Urgente', 'Majeure', 0, 'Affectation', 'Non proposée', 'Remplacement ordonné', 8, null],
        ['Non-respect des consignes', 'Plan de ferraillage', 'Ferraillage non conforme au plan', 'P', 'Affecté', 'Normale', 'Majeure', 0.45, 'Chantier', 'Non proposée', '', 3, null],
        ['Affectation contestée', 'Métier', 'Affectation sur un métier non déclaré', 'O', 'Soumis', 'Faible', 'Mineure', 0, 'Affectation', 'Non proposée', '', 0, null],
        ['Document falsifié', 'Attestation', 'Attestation de formation suspecte', 'P', 'Annulé', 'Normale', 'Majeure', 0, 'Opération administrative', 'Non proposée', '', 60, null],
    ];

    /**
     * Mesures financières par catégorie : type, part du montant, statut, mesure conservatoire.
     *
     * @var array<string, array<int, array{0: string, 1: float, 2: string, 3?: bool}>>
     */
    private const MEASURES = [
        'Paiement non reçu' => [['Libération d’un paiement', 1, 'Exécutée']], 'Dommage matériel' => [['Retenue justifiée', 0.5, 'En traitement']], 'Montant incorrect' => [['Ajustement créditeur', 0.6, 'Exécutée']],
        'Absence' => [['Suspension d’un paiement', 1, 'Exécutée', true]], 'Commission contestée' => [['Remboursement d’une commission', 1, 'Exécutée']], 'Fraude présumée' => [['Suspension d’un reversement', 1, 'Exécutée', true]],
        'Abandon de chantier' => [['Suspension d’un reversement', 0.5, 'Exécutée', true]], 'Remboursement' => [['Remboursement partiel', 1, 'Transmise à Paiements']],
        'Travail incomplet' => [['Ajustement débiteur', 1, 'Suspendue (recours en cours)']], 'Retard d’exécution' => [['Suspension d’un paiement', 0.5, 'Exécutée', true]],
    ];

    private const CLAIMS = [
        ['Retard de livraison du lot électricité', 'Retard d’exécution', 'Transformée en litige', 15, 0], ['Bétonnière endommagée pendant le coulage', 'Dommage matériel', 'Transformée en litige', 31, 6],
        ['Enduits non terminés à la fin du contrat', 'Travail incomplet', 'Transformée en litige', 46, 14], ['Perforateur disparu sur le chantier', 'Matériel perdu', 'Transformée en litige', 26, 16],
        ['Badge de pointage non reconnu', 'Pointage contesté', 'Résolue', 12, -1], ['Retard de virement Wave', 'Paiement non reçu', 'Traitement simple', 2, -1], ['Casque de sécurité non fourni', 'Sécurité', 'À qualifier', 1, -1],
        ['Demande de changement d’horaire', 'Autre', 'Rejetée', 20, -1], ['Bruit sur chantier le dimanche', 'Comportement', 'Information demandée', 3, -1], ['Erreur de nom sur le reçu', 'Montant incorrect', 'Clôturée', 35, -1],
        ['Retard de remise des clés du local', 'Conflit contractuel', 'Reçue', 0, -1], ['Commission appliquée sur un acompte', 'Commission contestée', 'Soumise', 0, -1], ['Livraison de ciment incomplète', 'Autre', 'Résolue', 9, -1],
        ['Avis en double sur la même semaine', 'Avis contesté', 'Résolue', 5, -1],
    ];

    private CarbonImmutable $today;

    private int $reviewNumber = 0;

    /** @var Collection<int, array<string, mixed>> */
    private Collection $prestations;

    /** @var Collection<string, Worker> */
    private Collection $workers;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->today = QualityInsights::today();
        QualityInsights::forgetPrestations();
        $this->prestations = QualityInsights::prestations();
        $this->workers = Worker::query()->get()->keyBy('slug');
        if ($this->prestations->isEmpty()) {
            return;
        }
        $this->reviews();
        $disputes = collect(self::DISPUTES)->map(fn (array $s, int $i): Dispute => $this->dispute($s, $i))->values();
        $this->claims($disputes);
        $this->notifications($disputes);
        QualityActivity::create(['occurred_at' => $this->today->subDays(2)->setTime(15, 32), 'author' => 'A. Koné · Gestionnaire de litiges', 'category' => 'document', 'action' => 'a exporté la liste des litiges', 'new_value' => 'Excel', 'comment' => 'Rapports · 22 lignes', 'origin' => 'Back-office · IP 41.207.x.x']);
    }

    private function hash(string $s): int
    {
        return crc32($s);
    }

    private function log(string $reference, CarbonImmutable $day, string $time, string $author, string $category, string $action, ?string $old, ?string $new, ?string $comment, string $origin = 'Back-office'): void
    {
        QualityActivity::create(['subject_reference' => $reference, 'occurred_at' => $day->format('Y-m-d')." {$time}", 'author' => $author, 'category' => $category, 'action' => $action, 'old_value' => $old ?: null, 'new_value' => $new ?: null, 'comment' => $comment ?: null, 'origin' => $origin]);
    }

    /**
     * Notes par critère autour d’une note de base (variation déterministe).
     *
     * @return array<string, int>
     */
    private function criteria(string $kind, float $base, int $h): array
    {
        return collect(config('admin.quality.criteria')[$kind])->mapWithKeys(fn (array $c, int $i): array => [$c[0] => (int) max(1, min(5, round($base + ((($h >> ($i % 12)) & 3) - 1.5) * 0.45)))])->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $o
     */
    private function review(array $p, array $o): Review
    {
        $this->reviewNumber++;
        $date = CarbonImmutable::parse($o['date'] ?? $this->today);
        $owners = config('admin.quality.owners');
        $data = [
            'reference' => 'AV-'.$date->year.'-'.sprintf('%04d', $this->reviewNumber), 'kind' => 'Ouvrier', 'author' => "{$p['author']} · {$p['promoter']}", 'author_type' => 'Promoteur',
            'promoter_id' => $p['promoterId'], 'promoter_name' => $p['promoter'], 'site_id' => $p['siteId'], 'worker_id' => $p['workerId'], 'target' => $p['worker'], 'target_type' => 'Ouvrier',
            'operation_reference' => $p['opRef'], 'assignment_reference' => $p['assignment'], 'trade' => $p['trade'], 'prestation' => $p['prestation'], 'period_from' => $p['from'], 'period_to' => $p['to'],
            'weights_version' => config('admin.quality.weights_version'), 'comment' => '', 'positives' => '', 'negatives' => '', 'recommends' => 'Oui', 'proofs' => [], 'verification' => 'Vérifié',
            'status' => 'Publié', 'visibility' => 'Public', 'reply' => ['status' => 'Non exercé'], 'detections' => [], 'reports' => [], 'eligibility' => self::ELIGIBLE,
            'device' => 'APP-'.($this->hash($p['author']) % 9000 + 1000), ...$o, 'date' => $date,
        ];
        $review = Review::create($data);
        $h = $this->hash($review->reference);
        $this->log($review->reference, $date, '18:'.sprintf('%02d', 10 + $h % 49), $review->author, 'creation', 'a soumis l’avis', null, 'Soumis', $review->kind === 'Équipe' ? 'Évaluation d’équipe' : 'Évaluation individuelle', 'Application Promoteur');
        if (! in_array($review->status, ['Brouillon', 'Soumis', 'En cours de vérification'], true)) {
            $this->log($review->reference, $date->addDay(), '09:20', 'Système', 'controle', 'a vérifié l’éligibilité', 'Soumis', $review->isEligible() ? 'Vérifié' : 'Non éligible', "Prestation {$p['opRef']} · affectation {$review->assignment_reference}", 'Système');
        }
        if (in_array($review->status, ['Publié', 'Signalé', 'Contesté', 'Restauré', 'En réexamen', 'Masqué temporairement', 'Archivé'], true)) {
            $this->log($review->reference, $date->addDay(), '11:05', $owners[$h % 4], 'publication', 'a publié l’avis', 'Vérifié', 'Publié', 'Règle MOD-01 · contenu conforme');
        }

        return $review;
    }

    private function reviews(): void
    {
        $owners = config('admin.quality.owners');
        $t = $this->today->format('Y-m-d');
        foreach ($this->prestations as $p) {
            $h = $this->hash($p['opRef'].'av');
            if ($h % 100 >= 34) {
                continue;
            }
            $date = CarbonImmutable::parse($p['to'])->addDays(1 + $h % 9);
            if ($date->format('Y-m-d') > $t) {
                continue;
            }
            $age = (int) $date->diffInDays($this->today);
            $negative = $h % 17 === 0;
            $mixed = ! $negative && $h % 7 === 0;
            $base = $negative ? 2.1 : ($mixed ? 3.3 : min(4.8, ($this->workers[$p['workerSlug']]->rating ?? 4.3) + 0.1));
            $status = match (true) {
                $age <= 2 => Review::PENDING[$h % 3],
                $h % 41 === 0 => 'Signalé', $h % 43 === 0 => 'Contesté', $h % 47 === 0 => 'Masqué temporairement', $h % 53 === 0 => 'Rejeté', $h % 59 === 0 => 'Restauré',
                $h % 61 === 0 => 'Archivé', $h % 67 === 0 => 'Annulé', $h % 71 === 0 => 'En réexamen',
                default => 'Publié',
            };
            $by = $owners[$h % 4];
            $reply = ['status' => 'Non exercé'];
            if ($negative || $h % 13 === 0) {
                $reply = match ($h % 3) {
                    0 => ['status' => 'En attente de modération', 'text' => 'Les deux jours d’absence étaient justifiés par un certificat médical transmis au chef de chantier.', 'author' => $p['worker'], 'date' => $date->addDays(2)->format('Y-m-d'), 'pieces' => ['certificat-medical.pdf']],
                    1 => ['status' => 'Publiée', 'text' => 'Merci pour ce retour, les reprises ont été faites la semaine suivante.', 'author' => $p['worker'], 'date' => $date->addDay()->format('Y-m-d'), 'pieces' => []],
                    default => ['status' => 'Non exercé'],
                };
            }
            $this->review($p, [
                'date' => $date, 'criteria' => $this->criteria('Ouvrier', $base, $h), 'comment' => ($negative ? self::NEGATIVE : ($mixed ? self::MIXED : self::POSITIVE))[$h % ($negative || $mixed ? 3 : 7)],
                'positives' => $negative ? '' : 'Rigueur et ponctualité', 'negatives' => $negative || $mixed ? 'Finitions et communication' : '', 'recommends' => $negative ? 'Non' : 'Oui', 'status' => $status,
                'verification' => match ($status) {
                    'Soumis' => 'Non vérifié',
                    'En cours de vérification' => 'En cours de vérification',
                    default => 'Vérifié',
                },
                'visibility' => match (true) {
                    in_array($status, ['Masqué temporairement', 'Rejeté', 'Annulé', 'Archivé'], true) => 'Non public',
                    in_array($status, Review::COUNTED, true) => 'Public',
                    default => 'En attente',
                },
                'proofs' => $h % 5 === 0 ? [['name' => 'photo-chantier-'.($h % 90).'.jpg', 'size' => '1,2 Mo']] : [], 'reply' => $reply,
                'reports' => match ($status) {
                    'Signalé' => [['id' => 'sg-1', 'author' => $p['worker'], 'motif' => 'Faux avis', 'comment' => 'Je n’étais pas affecté à ce lot la semaine indiquée.', 'proof' => 'planning-semaine.pdf', 'date' => $date->addDays(2)->format('Y-m-d'), 'status' => 'Ouvert', 'decision' => '']],
                    'Contesté' => [['id' => 'sg-1', 'author' => $p['worker'], 'motif' => 'Mauvaise prestation', 'comment' => 'La note concerne un autre lot réalisé par une autre équipe.', 'proof' => '', 'date' => $date->addDay()->format('Y-m-d'), 'status' => 'En examen', 'decision' => '']],
                    default => [],
                },
                'moderation' => match ($status) {
                    'Rejeté' => ['decision' => 'Rejeté', 'motif' => 'Contenu sans rapport avec la prestation', 'rule' => 'MOD-04 · Pertinence', 'comment' => 'Le texte évoque un autre chantier.', 'by' => $by, 'date' => $date->addDays(2)->format('Y-m-d')],
                    'Masqué temporairement' => ['decision' => 'Masqué temporairement', 'motif' => 'Enquête en cours', 'rule' => 'MOD-07 · Suspicion de manipulation', 'comment' => 'Plusieurs avis depuis le même réseau.', 'by' => $by, 'date' => $date->addDay()->format('Y-m-d')],
                    default => null,
                },
            ]);
        }

        $this->prestations->groupBy(fn (array $p): string => $p['siteId'].'|'.$p['to'])->each(function (Collection $list, string $key) use ($t): void {
            $members = $list->unique('workerSlug')->values();
            $h = $this->hash($key);
            if ($members->count() < 3 || $h % 4) {
                return;
            }
            $p = $members->first();
            $date = CarbonImmutable::parse($p['to'])->addDays(2 + $h % 5);
            if ($date->format('Y-m-d') > $t) {
                return;
            }
            $this->review($p, [
                'kind' => 'Équipe', 'target_type' => 'Équipe', 'target' => 'Équipe '.explode(' ', $p['worker'])[0].' · '.implode(' ', array_slice(explode(' ', $p['site']), 0, 2)),
                'team' => ['chief' => $p['worker'], 'members' => $members->map(fn (array $m): array => ['name' => $m['worker'], 'trade' => $m['trade'], 'slug' => $m['workerSlug']])->all(), 'scope' => ['Toute l’équipe', 'Le chef d’équipe', 'Une étape contractuelle', 'L’ensemble du contrat'][$h % 4]],
                'date' => $date, 'criteria' => $this->criteria('Équipe', $h % 9 === 0 ? 3 : 4.3, $h),
                'comment' => $h % 9 === 0 ? 'Coordination difficile entre les corps de métier, planning glissé d’une semaine.' : 'Équipe bien organisée, livrables conformes au planning convenu.',
                'status' => $date->diffInDays($this->today) <= 2 ? 'En attente de modération' : 'Publié', 'assignment_reference' => 'CTR-'.substr($p['to'], 0, 4).'-'.(100 + $h % 800),
            ]);
        });

        $r = $this->prestations->take(40)->values();
        $suspects = [
            [1, 'Très bon travail. Pour d’autres chantiers appelez-moi directement au 07 08 45 12 90.', [['Informations personnelles', 'Élevé', 'Numéro de téléphone détecté dans le commentaire']]],
            [4, 'Ouvrier incompétent et malhonnête, un vrai voleur.', [['Insultes', 'Élevé', 'Termes injurieux détectés'], ['Accusations graves sans contexte', 'Modéré', 'Accusation de vol sans élément']]],
            [7, 'Super super travail !!! Visitez promo-btp-express.ci pour vos devis.', [['Spam', 'Modéré', 'Lien externe publicitaire'], ['Contenu sans rapport', 'Faible', 'Promotion commerciale']]],
            [10, 'Travail soigné et livré dans les temps, rien à reprendre.', [['Contenu dupliqué', 'Faible', 'Texte identique à 6 autres avis du même auteur'], ['Plusieurs avis depuis le même appareil', 'Modéré', 'Appareil APP-4471 · 3 avis en 20 min']]],
            [13, 'Nul.', [['Variation extrême', 'Modéré', 'Note 1,0 / 5 pour une moyenne du profil de 4,6'], ['Relation non vérifiée', 'Élevé', 'Auteur non rattaché au chantier dans Affectations']]],
            [16, 'Paiement à faire sur le compte 0123 4567 8901 23 pour la prime.', [['Données bancaires', 'Critique', 'Numéro de compte détecté']]],
        ];
        foreach ($suspects as $i => [$index, $comment, $detections]) {
            if (! $p = $r->get($index)) {
                continue;
            }
            $this->review($p, [
                'date' => $this->today->subDays($i % 3), 'criteria' => $this->criteria('Ouvrier', $i === 4 ? 1 : ($i === 1 ? 1.6 : 4.4), $this->hash($comment)), 'comment' => $comment,
                'status' => 'En attente de modération', 'verification' => $i === 4 ? 'Relation non vérifiée' : 'Vérifié', 'visibility' => 'En attente', 'device' => $i === 3 ? 'APP-4471' : 'APP-'.(2000 + $i),
                'detections' => array_map(fn (array $d): array => ['key' => $d[0], 'level' => $d[1], 'detail' => $d[2], 'status' => 'À examiner', 'auto' => true], $detections),
                'eligibility' => $i === 4 ? [...self::ELIGIBLE, 'linked' => false] : self::ELIGIBLE,
            ]);
        }
        if ($p = $r->get(20)) {
            $original = Review::query()->where('operation_reference', $p['opRef'])->where('kind', 'Ouvrier')->value('reference');
            $this->review($p, ['date' => $this->today->subDays(5), 'criteria' => $this->criteria('Ouvrier', 4, 7), 'comment' => 'Deuxième avis sur la même prestation.', 'status' => 'Rejeté', 'visibility' => 'Non public',
                'duplicate_of' => $original ?? 'AV-2026-0001', 'eligibility' => [...self::ELIGIBLE, 'noDup' => false],
                'moderation' => ['decision' => 'Rejeté', 'motif' => 'Doublon', 'rule' => 'ELIG-08 · Un seul avis actif par prestation', 'comment' => 'Blocage automatique confirmé.', 'by' => 'M. Diabaté', 'date' => $this->today->subDays(4)->format('Y-m-d')]]);
        }
        if ($p = $r->get(22)) {
            $this->review($p, ['date' => $this->today->subDays(9), 'criteria' => $this->criteria('Ouvrier', 3, 9), 'comment' => 'Évaluation d’une affectation annulée.', 'status' => 'Rejeté', 'visibility' => 'Non public',
                'eligibility' => [...self::ELIGIBLE, 'affConf' => false, 'done' => false],
                'moderation' => ['decision' => 'Rejeté', 'motif' => 'Affectation annulée avant exécution', 'rule' => 'ELIG-05', 'comment' => 'Aucune prestation réalisée.', 'by' => 'N. Yao', 'date' => $this->today->subDays(8)->format('Y-m-d')]]);
        }
        if ($p = $r->get(25)) {
            $this->review($p, ['date' => $this->today->subDay(), 'criteria' => $this->criteria('Ouvrier', 4, 3), 'comment' => '', 'status' => 'Brouillon', 'visibility' => 'Non public', 'verification' => 'Non vérifié']);
        }
        foreach ([2, 3] as $i => $index) {
            if ($p = $r->get($index)) {
                $this->review($p, ['date' => $this->today->subDays(20 + $i), 'criteria' => $this->criteria('Ouvrier', 5, $i), 'comment' => 'Avis de test recette application.', 'is_test' => true, 'author' => 'Compte test QA']);
            }
        }
        $this->prestations->filter(fn (array $p, int $i): bool => $i % 31 === 5)->take(6)->each(function (array $p): void {
            $date = CarbonImmutable::parse($p['to'])->addDays(3);
            $this->review($p, ['kind' => 'Interne', 'author_type' => 'Administration (évaluation interne)', 'author' => 'Contrôle qualité O’CHANTIER', 'date' => $date->greaterThan($this->today) ? $this->today : $date,
                'criteria' => $this->criteria('Ouvrier', 4.2, $this->hash($p['opRef'])), 'comment' => 'Visite de contrôle qualité : conformité des ouvrages vérifiée.', 'visibility' => 'Interne']);
        });
    }

    /**
     * @param  array<int, mixed>  $s
     */
    private function dispute(array $s, int $i): Dispute
    {
        [$category, $sub, $subject, $claimantRole, $status, $priority, $severity, $share, $source, $mediationStatus, $result, $ago, $fixedRef] = $s;
        $t = $this->today;
        $cfg = config('admin.quality');
        $owners = $cfg['owners'];
        $open = $t->subDays($ago);
        $openIso = $open->format('Y-m-d');
        $demo = $fixedRef ? Promoter::query()->get()->flatMap(fn (Promoter $p): array => $p->demo_relations['disputes'] ?? [])->firstWhere('ref', $fixedRef) : null;
        $candidates = $this->prestations->filter(fn (array $x): bool => $x['to'] <= $openIso && (! $demo || $x['site'] === $demo['site']))->values();
        if ($candidates->isEmpty()) {
            $candidates = $this->prestations->filter(fn (array $x): bool => $x['to'] <= $openIso)->values();
        }
        if ($candidates->isEmpty()) {
            $candidates = $this->prestations;
        }
        $p = $candidates[($i * 5) % $candidates->count()];
        $worker = $this->workers[$p['workerSlug']] ?? null;
        $promoter = Promoter::query()->find($p['promoterId']);
        $ref = $fixedRef ?? 'LIT-'.sprintf('%04d', 110 + $i);
        $h = $this->hash($ref);
        $pro = ['name' => "{$p['author']} · {$p['promoter']}", 'role' => 'Promoteur', 'slug' => $p['promoterSlug'], 'link' => 'pro', 'phone' => $promoter?->phone ?? ''];
        $ouv = ['name' => $p['worker'], 'role' => 'Ouvrier', 'slug' => $p['workerSlug'], 'link' => 'ouv', 'phone' => $worker?->phone ?? '07 58 21 44 '.(10 + $this->hash($p['workerSlug']) % 89)];
        $claimant = $claimantRole === 'P' ? $pro : $ouv;
        $respondent = $fixedRef === 'LIT-0102' ? ['name' => 'Équipe Bâtir Pro', 'role' => 'Équipe contractuelle', 'slug' => null, 'link' => null, 'phone' => ''] : ($claimantRole === 'P' ? $ouv : $pro);
        $amount = $share ? (int) round($p['amount'] * $share / 500) * 500 : 0;
        $owner = $demo['owner'] ?? $owners[$i % 4];
        $early = ['Brouillon', 'Soumis', 'Reçu', 'À qualifier', 'Recevabilité en cours', 'Non recevable'];
        $qualified = ! in_array($status, $early, true);
        $responded = $qualified && ! in_array($status, ['Ouvert', 'Affecté', 'Réponse de la partie adverse attendue', 'Annulé'], true);
        $decided = in_array($status, ['Décision rendue', 'En attente d’exécution', 'Résolu', 'Clôturé', 'Recours ouvert', 'Réexamen'], true) && $mediationStatus !== 'Accord validé';
        $agreement = $mediationStatus === 'Accord validé';
        $final = in_array($status, Dispute::FINAL, true);
        $qualAt = $open->addDay();
        $respAt = $qualAt->addDays(3);
        $medAt = $respAt->addDay();
        $decAt = $open->addDays(max(3, (int) round($ago * 0.6)));
        $resolvedAt = in_array($status, ['Résolu', 'Clôturé', 'Accord trouvé'], true) ? $open->addDays(max(2, (int) round($ago * 0.8))) : null;
        $phase = Dispute::PHASES[$status][0] ?? null;
        $phaseAt = $final || ! $phase ? null : $t->subDays($this->hash($ref.'ph') % ($cfg['sla'][$phase] + 4));
        if ($phaseAt && $phaseAt->lessThan($open)) {
            $phaseAt = $open;
        }
        $attendances = $worker ? Attendance::query()->where('worker_id', $worker->id)->orderBy('date')->pluck('reference')->take(-3)->values() : collect();
        $money = fn (int $v): string => number_format($v, 0, ',', ' ');

        $kinds = [['Photos du lot concerné', 'Photo', 'jpg', '2,4 Mo'], ['Relevé des pointages de la période', 'Pointage', 'pdf', '180 Ko'], ["Reçu de paiement {$p['opRef']}", 'Reçu', 'pdf', '95 Ko'], ['Échanges avec le chef de chantier', 'Message', 'pdf', '320 Ko'], ['Contrat de prestation', 'Contrat', 'pdf', '410 Ko'], ['Rapport du conducteur de travaux', 'Rapport', 'pdf', '260 Ko'], ['Témoignage du chef d’équipe', 'Témoignage', 'pdf', '60 Ko'], ['Localisation des pointages', 'Localisation', 'json', '12 Ko']];
        $proofs = [];
        for ($k = 0; $k < 2 + $h % 3; $k++) {
            $q = $kinds[($h + $k * 3) % count($kinds)];
            $by = $k % 2 ? $respondent : $claimant;
            $deposited = $open->addDays($k)->format('Y-m-d');
            $contested = $k === 1 && $responded;
            $proofs[] = [
                'id' => "pv-{$k}", 'ref' => 'PRV-'.substr($ref, 4).'-'.($k + 1), 'title' => $q[0], 'type' => $q[1], 'author' => $by['name'], 'role' => $by['role'], 'deposited_at' => $deposited, 'facts_at' => $open->subDays(2)->format('Y-m-d'),
                'file' => Str::slug(Str::limit($q[0], 28, '')).'.'.$q[2], 'size' => $q[3], 'visibility' => $q[1] === 'Témoignage' ? 'Confidentiel' : 'Parties',
                'status' => ! $qualified ? 'À vérifier' : ($contested ? 'Contestée' : 'Vérifiée'), 'comment' => $contested ? "Contestée par {$claimant['name']} : pièce incomplète" : '', 'version' => 1,
                'meta' => 'Empreinte SHA-256 '.substr(dechex($this->hash($ref.$k)).'a3f9c1', 0, 12).'… · horodatage '.QualityInsights::date($deposited),
                'history' => [['v' => 1, 'at' => $deposited, 'by' => $by['name'], 'note' => 'Dépôt initial · fichier d’origine conservé']], 'views' => 0,
                'duplicate_of' => $k === 2 && $i % 5 === 0 ? 'PRV-'.sprintf('%04d', 110 + ($i + 3) % 20).'-1' : '',
            ];
        }
        $pieces = fn (array $party): array => array_values(array_column(array_filter($proofs, fn (array $x): bool => $x['author'] === $party['name']), 'ref'));
        $declarations = [['id' => 'dc-1', 'author' => $claimant['name'], 'role' => 'Demandeur', 'date' => $openIso, 'type' => 'Déclaration initiale', 'content' => "{$subject}. Faits constatés le ".$open->subDays(2)->format('d/m/Y')." sur {$p['site']}.", 'pieces' => $pieces($claimant), 'visibility' => 'Parties', 'status' => 'Déposée', 'version' => 1, 'history' => []]];
        if ($responded) {
            $declarations[] = ['id' => 'dc-2', 'author' => $respondent['name'], 'role' => 'Partie mise en cause', 'date' => $respAt->format('Y-m-d'), 'type' => 'Réponse', 'content' => ['Les faits sont contestés : le lot a été livré conformément aux consignes reçues.', 'Le retard est dû à la livraison tardive des matériaux par le promoteur.', 'Nous reconnaissons un retard partiel et proposons une reprise sans frais.'][$i % 3], 'pieces' => $pieces($respondent), 'visibility' => 'Parties', 'status' => 'Déposée', 'version' => $i % 4 === 0 ? 2 : 1, 'history' => $i % 4 === 0 ? [['v' => 1, 'at' => $respAt->subDay()->format('Y-m-d'), 'note' => 'Version initiale conservée']] : []];
        }
        if ($responded && ($decided || $mediationStatus !== 'Non proposée')) {
            $declarations[] = ['id' => 'dc-3', 'author' => $claimant['name'], 'role' => 'Demandeur', 'date' => $respAt->addDays(2)->format('Y-m-d'), 'type' => 'Observation complémentaire', 'content' => 'Maintien de la demande : les photos datées montrent l’état du lot à la date indiquée.', 'pieces' => [], 'visibility' => 'Parties', 'status' => 'Déposée', 'version' => 1, 'history' => []];
        }
        $proposed = $mediationStatus !== 'Non proposée';
        $mediator = $proposed ? $cfg['mediators'][0] : '';
        $mediation = ['status' => $mediationStatus, 'mediator' => $mediator, 'proposed_at' => $proposed ? $medAt->format('Y-m-d') : null, 'claimant_accepts' => $proposed ? true : null, 'respondent_accepts' => in_array($mediationStatus, ['En cours', 'Accord validé', 'Échec'], true) ? true : null,
            'calendar' => $proposed && $mediationStatus !== 'En attente d’acceptation' ? [['date' => $medAt->addDays(3)->format('Y-m-d'), 'time' => '10:00', 'place' => 'Visioconférence'], ['date' => $medAt->addDays(7)->format('Y-m-d'), 'time' => '15:00', 'place' => 'Bureau O’CHANTIER Plateau']] : [],
            'exchanges' => [], 'proposals' => [], 'agreement' => null];
        if (in_array($mediationStatus, ['En cours', 'Échec', 'Accord validé'], true)) {
            $mediation['exchanges'] = [['date' => $medAt->addDays(3)->format('Y-m-d'), 'by' => $mediator, 'text' => 'Première séance : exposé des positions.'], ['date' => $medAt->addDays(5)->format('Y-m-d'), 'by' => $mediator, 'text' => 'Échange des propositions écrites.']];
            $mediation['proposals'] = [['date' => $medAt->addDays(4)->format('Y-m-d'), 'by' => $claimant['name'], 'text' => $amount ? 'Versement de '.$money($amount).' FCFA' : 'Reprise complète du lot', 'status' => 'Contre-proposée'],
                ['date' => $medAt->addDays(5)->format('Y-m-d'), 'by' => $respondent['name'], 'text' => $amount ? 'Versement de '.$money((int) round($amount * 0.6 / 500) * 500).' FCFA' : 'Reprise partielle sous 8 jours', 'status' => match ($mediationStatus) {
                    'Échec' => 'Refusée', 'Accord validé' => 'Acceptée', default => 'En discussion',
                }]];
        }
        if ($agreement) {
            $mediation['agreement'] = ['claimant' => 'Accepte la régularisation proposée et retire sa demande.', 'respondent' => 'Verse le complément convenu via O’CHANTIER.', 'amount' => (int) round($amount * 0.6 / 500) * 500, 'replacement' => 'Aucun', 'delay' => '7 jours', 'payments' => 'Ajustement créditeur transmis à Paiements', 'reviews' => 'Aucune conséquence sur les avis', 'claimant_validated' => true, 'respondent_validated' => true, 'effective_at' => $medAt->addDays(8)->format('Y-m-d')];
        }
        $decision = null;
        if ($decided || $status === 'Décision en préparation') {
            $decision = [
                'status' => $decided ? 'Rendue' : 'Brouillon', 'version' => 1, 'result' => $result ?: ($category === 'Abandon de chantier' ? 'Remplacement ordonné' : 'Demande partiellement acceptée'),
                'facts' => "{$subject} constaté sur {$p['site']} ({$p['commune']}).", 'requests' => 'Demandeur : '.($amount ? 'indemnisation de '.$money($amount).' FCFA' : 'mesure corrective').' · Partie mise en cause : rejet de la demande.',
                'proofs' => implode(', ', array_column($proofs, 'ref')), 'retained' => implode(' ; ', array_column(array_filter($proofs, fn (array $x): bool => $x['status'] === 'Vérifiée'), 'title')) ?: '—',
                'rejected' => implode(' ; ', array_map(fn (array $x): string => "{$x['title']} (incomplète)", array_filter($proofs, fn (array $x): bool => $x['status'] === 'Contestée'))) ?: 'Aucun',
                'analysis' => 'Les pièces vérifiées et les déclarations concordantes établissent '.($result === 'Demande rejetée' ? 'l’absence de manquement' : 'le manquement allégué').'. La décision ne repose pas sur la seule note laissée.',
                'rules' => 'Conditions générales art. 12 (qualité des prestations) · Règlement des litiges art. 4 à 9', 'measures' => [], 'delay' => "{$cfg['sla']['exec']} jours",
                'appeal' => 'Recours possible jusqu’au '.$decAt->addDays($cfg['appeal_window'])->format('d/m/Y'), 'author' => $owner, 'approver' => $decided ? $cfg['approver'] : '', 'date' => $decAt->format('Y-m-d'),
            ];
        }
        $financial = [];
        foreach (self::MEASURES[$category] ?? [] as $j => $m) {
            $conservatory = $m[3] ?? false;
            $at = ($conservatory ? $qualAt : $decAt)->format('Y-m-d');
            $financial[] = [
                'ref' => 'DPA-2026-'.sprintf('%04d', 501 + $i * 2 + $j), 'type' => $m[0], 'amount' => (int) round(($amount ?: $p['net']) * $m[1] / 500) * 500,
                'motif' => $conservatory ? 'Mesure conservatoire pendant l’instruction' : 'Exécution de la décision', 'source' => $conservatory ? 'Mesure conservatoire' : ($decision ? "Décision {$ref} v1" : ($agreement ? 'Accord de médiation' : '—')),
                'approvals' => $conservatory ? [$owner] : [$owner, 'Awa Bamba'], 'status' => $m[2], 'op_ref' => $p['opRef'], 'payment_ref' => $p['paymentRef'], 'at' => $at,
                'executed_at' => $m[2] === 'Exécutée' ? CarbonImmutable::parse($at)->addDay()->format('Y-m-d') : null, 'released' => false,
            ];
        }
        $replacements = in_array($category, ['Abandon de chantier', 'Remplacement'], true) ? [[
            'ref' => 'DRA-2026-'.sprintf('%04d', 80 + $i), 'worker' => $p['worker'], 'assignment' => $p['assignment'], 'motif' => $category, 'urgency' => 'Urgente', 'date' => $t->addDays(2)->format('Y-m-d'),
            'transfer' => 'Pointages et documents transférés au remplaçant', 'status' => $category === 'Remplacement' ? 'Transmise à Affectations' : 'Remplaçant proposé',
            'replacement' => $category === 'Remplacement' ? '' : (Worker::query()->where('status', 'validated')->where('trade', $p['trade'])->where('slug', '!=', $p['workerSlug'])->first()?->name ?? 'Profil similaire'),
            'result' => $category === 'Remplacement' ? 'En attente de proposition' : 'En attente de confirmation du promoteur', 'at' => ($category === 'Remplacement' ? $decAt : $qualAt)->format('Y-m-d'),
        ]] : [];
        $attendanceRequests = in_array($category, ['Heures contestées', 'Pointage contesté'], true) ? [[
            'ref' => 'DCP-2026-'.sprintf('%04d', 40 + $i), 'attendance' => $attendances->first() ?? 'PT-2026-0412', 'old' => 'Arrivée 07:30 · départ 12:00 (demi-journée)', 'contested' => 'Départ réel 17:05', 'requested' => 'Journée complète',
            'proof' => 'Photo horodatée 17:02 · témoignage du chef d’équipe', 'decision' => $status === 'Résolu' ? 'Correction ordonnée' : 'En attente de décision', 'status' => $status === 'Résolu' ? 'Corrigée dans Pointages' : 'Transmise à Pointages',
            'at' => ($status === 'Résolu' ? $decAt : $qualAt)->format('Y-m-d'),
        ]] : [];
        $appeals = in_array($status, ['Recours ouvert', 'Réexamen'], true) ? [[
            'ref' => 'RCS-2026-'.sprintf('%04d', 20 + $i), 'by' => $claimant['name'], 'role' => 'Demandeur', 'motif' => $status === 'Réexamen' ? 'Nouvelle preuve' : 'Preuve non prise en compte',
            'description' => $status === 'Réexamen' ? 'Constat d’huissier établi après la décision.' : 'Le certificat médical transmis n’a pas été examiné.', 'new_elements' => $status === 'Réexamen' ? 'constat-huissier.pdf' : 'certificat-medical.pdf',
            'date' => $decAt->addDays(4)->format('Y-m-d'), 'status' => $status === 'Réexamen' ? 'Réexamen' : 'Recevabilité en cours', 'outcome' => '',
        ]] : [];
        $recidive = Dispute::query()->where('worker_id', $p['workerId'])->exists();
        $auto = Dispute::autoPriority($category, $amount, $openIso, $recidive);
        $reasons = ['Critique' => 'risque humain immédiat', 'Urgente' => 'chantier à l’arrêt', 'Élevée' => 'délai contractuel proche', 'Normale' => 'impact limité constaté', 'Faible' => 'aucune urgence'];
        $priorityReason = $auto['level'] !== $priority ? "Ajustée de « {$auto['level']} » à « {$priority} » par {$owner} : {$reasons[$priority]}" : null;
        $assigned = in_array($status, ['Brouillon', 'Soumis', 'Reçu', 'À qualifier'], true) ? null : $owner;
        $parties = [
            ['role' => 'Demandeur', ...$claimant, 'notified_at' => $openIso, 'response' => 'Déclaration déposée', 'documents' => count(array_filter($declarations, fn (array $d): bool => $d['author'] === $claimant['name'])) + count($pieces($claimant)), 'confidentiality' => 'Standard'],
            ['role' => 'Partie mise en cause', ...$respondent, 'notified_at' => $qualified ? $qualAt->format('Y-m-d') : null, 'response' => $responded ? 'Réponse déposée le '.$respAt->format('d/m/Y') : ($qualified ? 'Réponse attendue avant le '.$qualAt->addDays($cfg['sla']['rep'])->format('d/m/Y') : 'Non notifiée'), 'documents' => count(array_filter($declarations, fn (array $d): bool => $d['author'] === $respondent['name'])) + count($pieces($respondent)), 'confidentiality' => 'Standard'],
            ['role' => 'Représentant', 'name' => 'Chef de chantier · pour '.$p['promoter'], 'slug' => null, 'link' => null, 'phone' => '', 'notified_at' => $qualified ? $qualAt->format('Y-m-d') : null, 'response' => 'Représente le promoteur', 'documents' => 0, 'confidentiality' => 'Standard'],
            ...($i % 3 === 1 ? [['role' => 'Témoin', 'name' => 'Seydou Traoré · chef d’équipe', 'slug' => null, 'link' => null, 'phone' => '05 44 12 09 87', 'notified_at' => $qualified ? $qualAt->format('Y-m-d') : null, 'response' => 'Témoignage recueilli', 'documents' => 1, 'confidentiality' => 'Identité protégée']] : []),
            ['role' => 'Administrateur en charge', 'name' => $assigned ?? 'Non affecté', 'slug' => null, 'link' => null, 'phone' => '', 'notified_at' => null, 'response' => '—', 'documents' => 0, 'confidentiality' => 'Interne'],
            ...($mediator ? [['role' => 'Médiateur', 'name' => $mediator, 'slug' => null, 'link' => null, 'phone' => '', 'notified_at' => $mediation['proposed_at'], 'response' => $mediationStatus, 'documents' => 0, 'confidentiality' => 'Tenu au secret']] : []),
        ];

        $dispute = Dispute::create([
            'reference' => $ref, 'subject' => $subject, 'category' => $category, 'subcategory' => $sub, 'description' => "{$subject}. ".($claimantRole === 'P' ? 'Le promoteur' : 'L’ouvrier').' demande '.($amount ? 'un règlement de '.$money($amount).' FCFA.' : 'une mesure corrective.'),
            'facts_at' => $open->subDays(2), 'opened_at' => $open, 'source' => $source,
            'source_reference' => match ($source) {
                'Paiement' => $p['opRef'], 'Pointage' => $attendances->first() ?? 'PT-2026-0412', 'Affectation' => $p['assignment'],
                'Avis contesté' => Review::query()->where('worker_id', $p['workerId'])->value('reference'), default => null,
            },
            'claimant' => $claimant, 'respondent' => $respondent, 'site_id' => $p['siteId'], 'promoter_id' => $p['promoterId'], 'worker_id' => $p['workerId'], 'operation_reference' => $p['opRef'],
            'assignment_reference' => $p['assignment'], 'prestation' => $p['prestation'], 'trade' => $p['trade'], 'amount' => $amount, 'wish' => $amount ? 'Règlement financier' : 'Mesure corrective',
            'urgent' => in_array($priority, ['Urgente', 'Critique'], true), 'priority' => $priority, 'severity' => $severity, 'priority_reason' => $priorityReason, 'owner' => $assigned, 'status' => $status,
            'qualified_at' => $qualified ? $qualAt : null, 'responded_at' => $responded ? $respAt : null, 'first_response_hours' => 2 + $h % 40, 'phase_started_at' => $phaseAt,
            'suspension' => $status === 'Informations demandées' ? 'En attente des justificatifs d’absence du demandeur' : null, 'decided_at' => $decision && $decided ? $decAt : null,
            'resolved_at' => $resolvedAt, 'closed_at' => $status === 'Clôturé' ? $open->addDays($ago - 2) : null, 'reopen_count' => $status === 'Réexamen' ? 1 : 0,
            'admissibility' => ['identity' => true, 'relation' => true, 'chantier' => true, 'aff' => true, 'delai' => $ago < $cfg['claim_window'] + 5, 'desc' => $status !== 'Non recevable', 'pieces' => true, 'noDup' => true, 'noFinal' => true],
            'inadmissibility' => $status === 'Non recevable' ? 'Délai de réclamation dépassé et aucune pièce établissant la présence du matériel.' : null,
            'parties' => $parties, 'declarations' => $declarations, 'proofs' => $proofs, 'mediation' => $mediation, 'decision' => $decision, 'financial_requests' => $financial, 'replacements' => $replacements,
            'attendance_requests' => $attendanceRequests, 'appeals' => $appeals,
            'notes' => [['by' => $owner, 'date' => $qualAt->format('Y-m-d'), 'text' => 'Vérifier la cohérence des pointages avec le planning du conducteur de travaux.'], ...($i % 3 === 0 ? [['by' => 'Awa Bamba', 'date' => $qualAt->addDays(2)->format('Y-m-d'), 'text' => 'Antécédent similaire sur ce chantier en 2025 : prudence sur la qualification de faute.']] : [])],
        ]);

        $ev = function (CarbonImmutable|string $day, string $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, ?string $author = null, string $origin = 'Back-office') use ($ref, $owner): void {
            $day = CarbonImmutable::parse($day);
            $this->log($ref, $day, sprintf('%02d:%02d', 8 + $this->hash($action.$day->format('Y-m-d')) % 10, $this->hash($day->format('Y-m-d').$action) % 60), $author ?? $owner, $category, $action, $old, $new, $comment, $origin);
        };
        $appOrigin = fn (array $party): string => $party['role'] === 'Ouvrier' ? 'Application Ouvrier' : 'Application Promoteur';
        $ev($open, 'creation', 'a ouvert le dossier', null, 'Soumis', "Source : {$source}".($dispute->source_reference ? " {$dispute->source_reference}" : ''), $claimant['name'], $appOrigin($claimant));
        $ev($open, 'notification', 'a envoyé l’accusé de réception', null, 'Reçu', "Délai {$cfg['sla']['ack']} j", 'Système', 'Système');
        if ($qualified) {
            $ev($qualAt, 'controle', 'a déclaré le litige recevable', 'Recevabilité en cours', 'Ouvert', '9 contrôles conformes');
            $ev($qualAt, 'affectation', 'a affecté le dossier', null, $owner);
            $ev($qualAt, 'notification', 'a notifié les parties', null, 'Résumé communiqué', "Délai de réponse {$cfg['sla']['rep']} j");
            if ($priorityReason) {
                $ev($qualAt, 'modification', 'a modifié la priorité', $auto['level'], $priority, $priorityReason);
            }
        }
        foreach ($proofs as $x) {
            $ev($x['deposited_at'], 'document', 'a déposé une preuve', null, $x['ref'], $x['title'], $x['author'], $x['role'] === 'Ouvrier' ? 'Application Ouvrier' : 'Application Promoteur');
        }
        if ($responded) {
            $ev($respAt, 'statut', 'a reçu la réponse de la partie mise en cause', 'Réponse attendue', 'Analyse en cours', null, $respondent['name'], 'Application');
        }
        if ($mediation['proposed_at']) {
            $ev($mediation['proposed_at'], 'diffusion', 'a proposé une médiation', 'Non proposée', 'Proposée', $mediator);
        }
        if ($mediationStatus === 'Échec') {
            $ev($medAt->addDays(8), 'statut', 'a constaté l’échec de la médiation', 'En cours', 'Échec', 'Aucun accord sur le montant');
        }
        if ($agreement) {
            $ev($mediation['agreement']['effective_at'], 'validation', 'a validé l’accord de médiation', 'Accord provisoire', 'Accord validé', 'Validé par les deux parties');
        }
        if ($decision && $decided) {
            $ev($decAt, 'validation', 'a rendu la décision', 'Décision en préparation', 'Décision rendue', "{$decision['result']} · approuvée par Awa Bamba");
        }
        foreach ($financial as $f) {
            $ev($f['at'], 'anomalie', 'a transmis une demande à Paiements', null, $f['ref'], "{$f['type']} · ".$money($f['amount']).' FCFA');
        }
        foreach ($replacements as $r) {
            $ev($r['at'], 'affectation', 'a transmis une demande de remplacement', null, $r['ref'], 'Affectations');
        }
        foreach ($attendanceRequests as $r) {
            $ev($r['at'], 'modification', 'a transmis une demande de correction de pointage', null, $r['ref'], $r['attendance']);
        }
        foreach ($appeals as $r) {
            $ev($r['date'], 'statut', 'a ouvert un recours', 'Décision rendue', $r['status'], $r['motif'], $r['by'], 'Application');
        }
        if ($resolvedAt) {
            $ev($resolvedAt, 'validation', 'a marqué le litige résolu', null, 'Résolu');
        }
        if ($dispute->closed_at) {
            $ev($dispute->closed_at->toImmutable(), 'statut', 'a clôturé le litige', 'Résolu', 'Clôturé', 'Toutes les mesures exécutées');
        }
        if ($status === 'Non recevable') {
            $ev($qualAt, 'controle', 'a déclaré la demande non recevable', 'Recevabilité en cours', 'Non recevable', $dispute->inadmissibility);
        }
        if ($status === 'Annulé') {
            $ev($open->addDays(5), 'statut', 'a annulé le litige', 'Analyse en cours', 'Annulé', 'Retrait de la demande par le demandeur');
        }

        return $dispute;
    }

    /**
     * @param  Collection<int, Dispute>  $disputes
     */
    private function claims(Collection $disputes): void
    {
        $owners = config('admin.quality.owners');
        $resolutions = ['correction effectuée par le chef de chantier', 'reçu corrigé et renvoyé', 'livraison complétée par le fournisseur', 'doublon supprimé après vérification'];
        foreach (self::CLAIMS as $i => [$subject, $category, $status, $ago, $linked]) {
            $p = $this->prestations[($i * 11 + 3) % $this->prestations->count()];
            $dispute = $linked >= 0 ? $disputes[$linked] : null;
            $promoter = ['name' => "{$p['author']} · {$p['promoter']}", 'role' => 'Promoteur', 'slug' => $p['promoterSlug']];
            $worker = ['name' => $p['worker'], 'role' => 'Ouvrier', 'slug' => $p['workerSlug']];
            $claimant = $dispute ? $dispute->claimant : ($i % 2 ? $worker : $promoter);
            $date = $this->today->subDays($ago);
            $reference = 'REC-2026-'.sprintf('%04d', 301 + $i);
            Claim::create([
                'reference' => $reference, 'claimant' => $claimant, 'other_party' => $dispute ? $dispute->respondent : ($i % 2 ? $promoter : $worker),
                'site_id' => $dispute ? $dispute->site_id : $p['siteId'], 'assignment_reference' => $dispute ? $dispute->assignment_reference : $p['assignment'], 'operation_reference' => $dispute ? $dispute->operation_reference : $p['opRef'],
                'category' => $category, 'subject' => $subject, 'description' => "{$subject}. Signalé via l’application le ".$date->format('d/m/Y').'.', 'date' => $date,
                'priority' => $dispute ? $dispute->priority : ['Normale', 'Faible', 'Élevée'][$i % 3], 'owner' => $owners[$i % 4], 'due_date' => $date->addDays(config('admin.quality.sla.qualif')),
                'status' => $status, 'dispute_id' => $dispute?->id, 'resolution' => in_array($status, ['Résolue', 'Clôturée'], true) ? 'Traité sans litige : '.$resolutions[$i % 4] : null,
            ]);
            $this->log($reference, $date, '08:'.sprintf('%02d', 10 + $i * 3), $claimant['name'], 'creation', 'a déposé la réclamation', null, 'Soumise', $category, $claimant['role'] === 'Ouvrier' ? 'Application Ouvrier' : 'Application Promoteur');
            $dispute?->update(['source_reference' => $reference]);
        }
    }

    /**
     * @param  Collection<int, Dispute>  $disputes
     */
    private function notifications(Collection $disputes): void
    {
        $sla = config('admin.quality.sla');
        $list = [['avis disponible', 'Promoteurs', 'Push', 'Évaluer les prestations terminées'], ['avis soumis', 'Modération', 'E-mail', 'Vérifier et publier'], ['droit de réponse', 'Ouvrier', 'SMS', 'Répondre sous 7 jours'], ['avis signalé', 'Modération', 'Push', 'Examiner le signalement'],
            ['réclamation reçue', 'Gestionnaire', 'E-mail', "Qualifier sous {$sla['qualif']} j"], ['litige ouvert', 'Parties', 'SMS', 'Consulter le résumé'], ['réponse attendue', 'Partie mise en cause', 'SMS', "Répondre sous {$sla['rep']} j"],
            ['preuve ajoutée', 'Gestionnaire', 'Push', 'Vérifier la pièce'], ['médiation proposée', 'Parties', 'E-mail', 'Accepter ou refuser'], ['accord proposé', 'Parties', 'Push', 'Valider l’accord'], ['décision rendue', 'Parties', 'E-mail', 'Consulter la décision'],
            ['mesure à exécuter', 'Paiements', 'Interne', 'Exécuter la demande'], ['recours ouvert', 'Responsable qualité', 'E-mail', 'Examiner la recevabilité'], ['SLA bientôt dépassé', 'Gestionnaire', 'Push', 'Traiter en priorité'], ['litige clôturé', 'Parties', 'SMS', 'Aucune']];
        foreach ($list as $i => [$event, $to, $channel, $expected]) {
            $d = $disputes[$i % $disputes->count()];
            QualityNotification::create(['sent_at' => $this->today->subDays(intdiv($i, 2))->setTime(10 + $i % 8, $i % 6), 'recipient' => $to, 'channel' => $channel, 'content' => "Notification « {$event} » · {$d->reference}", 'expected_action' => $expected, 'reference' => $d->reference,
                'status' => $i % 7 === 3 ? 'Échec d’envoi' : ($i % 5 === 0 ? 'Lue' : 'Envoyée')]);
        }
    }
}
