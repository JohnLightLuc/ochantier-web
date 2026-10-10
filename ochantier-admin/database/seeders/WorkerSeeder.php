<?php

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Les 16 ouvriers de la maquette, avec leurs pièces, expériences, périodes, journal et données de démonstration.
 *
 * Les valeurs dérivées (téléphones, dates, chantiers) sont tirées de façon déterministe d’un hachage de l’identifiant.
 */
class WorkerSeeder extends Seeder
{
    private const TODAY = '2026-09-23';

    private const TRADES = [
        'Maçon' => [['Coffreur', 'Carreleur'], ['Maçonnerie traditionnelle', 'Élévation de murs', 'Chapes et enduits'], ['Lecture de plans', 'Dosage béton', 'Pose de parpaings', 'Coffrage simple'], ['CAP Maçonnerie (2014)', 'Sécurité sur chantier SST (2023)']],
        'Électricien' => [['Soudeur'], ['Courant faible', 'Tableaux électriques'], ['Câblage', 'Mise à la terre', 'Lecture de schémas', 'Norme NF C 15-100'], ['BT Électrotechnique (2016)', 'Habilitation électrique B1V (2025)']],
        'Plombier' => [['Manœuvre'], ['Sanitaires', 'Réseaux PVC'], ['Soudure cuivre', 'Pose de sanitaires', 'Évacuations', 'Recherche de fuites'], ['CAP Plomberie (2015)']],
        'Peintre' => [['Manœuvre'], ['Peinture intérieure', 'Façades'], ['Préparation des supports', 'Enduits de lissage', 'Peinture au pistolet'], ['Attestation de formation peinture (2019)']],
        'Menuisier' => [['Coffreur'], ['Menuiserie bois', 'Pose de portes'], ['Découpe', 'Assemblage', 'Pose de menuiseries', 'Finitions'], ['CAP Menuiserie (2017)']],
        'Carreleur' => [['Maçon'], ['Carrelage sol', 'Faïence murale'], ['Calepinage', 'Pose collée', 'Joints', 'Découpe à la meuleuse'], ['Attestation carrelage (2021)']],
        'Manœuvre' => [['Maçon'], ['Manutention', 'Préparation de chantier'], ['Gâchage', 'Nettoyage de chantier', 'Aide au coffrage'], []],
        'Coffreur' => [['Maçon', 'Ferrailleur'], ['Coffrage bois', 'Coffrage métallique'], ['Lecture de plans', 'Étaiement', 'Décoffrage', 'Traçage'], ['CQP Coffreur-bancheur (2012)']],
        'Ferrailleur' => [['Coffreur'], ['Armatures', 'Façonnage'], ['Lecture de plans de ferraillage', 'Ligature', 'Découpe et pliage'], ['Attestation ferraillage (2020)']],
        'Soudeur' => [['Électricien'], ['Soudure à l’arc', 'Charpente métallique'], ['Soudure MIG', 'Meulage', 'Lecture de plans'], []],
    ];

    private const RECRUITERS = ['Adama Coulibaly', 'Mariam Koné', 'Jean-Marc Yao', 'Aïcha Traoré', 'Serge Koffi', 'Fatou Diarra'];

    /**
     * Chantiers où les ouvriers sont affectés : nom, promoteur, localisation.
     */
    private const SITES = [
        ['Résidence Les Oliviers', 'SICOGI', 'Cocody'], ['Tour Horizon', 'Ivoire Properties', 'Plateau'], ['Cité Belle Rive', 'SAPH', 'Bingerville'], ['Complexe scolaire SA', 'Fonds Éducation CI', 'Yamoussoukro'],
        ['Résidence Les Oliviers', 'SICOGI', 'Abidjan, Cocody'], ['Les Jardins d’Angré', 'SICOGI', 'Abidjan, Cocody Angré'], ['Cité Administrative Plateau', 'SICOGI', 'Abidjan, Plateau'], ['Logements sociaux Yopougon', 'SICOGI', 'Abidjan, Yopougon'],
        ['Résidence Bingerville Lot B', 'SICOGI', 'Bingerville'], ['Extension Cité Verte', 'SICOGI', 'Songon'], ['Tour Ivoire Business', 'SICOGI', 'Abidjan, Plateau'], ['Villa Duplex Riviera 3', 'SICOGI', 'Abidjan, Riviera'],
    ];

    /**
     * Statuts des 6 pièces selon le statut du profil : v vérifié, a à vérifier, m manquant, e expiré, i rejeté.
     */
    private const DOCUMENT_CODES = ['validated' => 'vvvvvv', 'to_review' => 'aaaaam', 'in_review' => 'vvaaam', 'incomplete' => 'avmmmm', 'rejected' => 'ivvvmm', 'suspended' => 'vvvvve', 'archived' => 'vvvvmm'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $workers = [
            ['koffi-adjoumani', 'OUV-0128', 'Koffi', 'Adjoumani', 'Homme', 'Maçon', 8, 12000, WorkerAvailability::Available, WorkerStatus::Validated, 'Cocody', 4.8, 28, 'Adama Coulibaly', '2024-01-12'],
            ['issa-traore', 'OUV-0141', 'Issa', 'Traoré', 'Homme', 'Électricien', 5, 10000, WorkerAvailability::Assigned, WorkerStatus::Validated, 'Yopougon', 4.6, 17, 'Mariam Koné', '2024-02-03'],
            ['mamadou-kone', 'OUV-0152', 'Mamadou', 'Koné', 'Homme', 'Plombier', 7, 11000, WorkerAvailability::OnMission, WorkerStatus::Validated, 'Abobo', 4.7, 22, 'Adama Coulibaly', '2024-02-19'],
            ['yao-kouame', 'OUV-0316', 'Yao', 'Kouamé', 'Homme', 'Peintre', 4, 9000, WorkerAvailability::Available, WorkerStatus::ToReview, 'Marcory', 4.4, 6, 'Jean-Marc Yao', '2026-09-18'],
            ['franck-dohi', 'OUV-0167', 'Franck', 'Dohi', 'Homme', 'Menuisier', 6, 11500, WorkerAvailability::OnMission, WorkerStatus::Validated, 'Treichville', 4.5, 19, 'Aïcha Traoré', '2024-03-08'],
            ['siaka-bamba', 'OUV-0189', 'Siaka', 'Bamba', 'Homme', 'Carreleur', 3, 9500, WorkerAvailability::Unavailable, WorkerStatus::Suspended, 'Koumassi', 4.2, 9, 'Serge Koffi', '2024-05-21'],
            ['diallo-fatoumata', 'OUV-0204', 'Fatoumata', 'Diallo', 'Femme', 'Manœuvre', 3, 7000, WorkerAvailability::Available, WorkerStatus::Validated, 'Yopougon', 4.6, 11, 'Mariam Koné', '2024-06-02'],
            ['traore-ibrahima', 'OUV-0211', 'Ibrahima', 'Traoré', 'Homme', 'Électricien', 6, 11000, WorkerAvailability::PreAssigned, WorkerStatus::Validated, 'Abobo', 4.5, 14, 'Adama Coulibaly', '2024-06-17'],
            ['awa-kone', 'OUV-0322', 'Awa', 'Koné', 'Femme', 'Carreleur', 2, 8500, WorkerAvailability::Available, WorkerStatus::InReview, 'Bingerville', 4.1, 3, 'Fatou Diarra', '2026-09-10'],
            ['serge-nguessan', 'OUV-0098', 'Serge', 'N’Guessan', 'Homme', 'Coffreur', 9, 13000, WorkerAvailability::Assigned, WorkerStatus::Validated, 'Port-Bouët', 4.7, 31, 'Aïcha Traoré', '2023-11-04'],
            ['moussa-ouattara', 'OUV-0233', 'Moussa', 'Ouattara', 'Homme', 'Ferrailleur', 5, 10500, WorkerAvailability::Available, WorkerStatus::Validated, 'Adjamé', 4.3, 12, 'Jean-Marc Yao', '2024-08-11'],
            ['jean-kouassi', 'OUV-0331', 'Jean-Baptiste', 'Kouassi', 'Homme', 'Soudeur', 1, 9000, WorkerAvailability::Unavailable, WorkerStatus::Incomplete, 'Abobo', 0, 0, 'Serge Koffi', '2026-09-21'],
            ['ibrahim-sylla', 'OUV-0298', 'Ibrahim', 'Sylla', 'Homme', 'Maçon', 4, 10000, WorkerAvailability::Available, WorkerStatus::Rejected, 'Yopougon', 3.9, 5, 'Mariam Koné', '2026-08-02'],
            ['aminata-toure', 'OUV-0176', 'Aminata', 'Touré', 'Femme', 'Peintre', 7, 10500, WorkerAvailability::PreAssigned, WorkerStatus::Validated, 'Cocody', 4.8, 16, 'Fatou Diarra', '2024-04-15'],
            ['paul-ake', 'OUV-0087', 'Paul', 'Aké', 'Homme', 'Plombier', 10, 12500, WorkerAvailability::OnMission, WorkerStatus::Validated, 'Grand-Bassam', 4.6, 27, 'Aïcha Traoré', '2023-09-28'],
            ['lacina-coulibaly', 'OUV-0119', 'Lacina', 'Coulibaly', 'Homme', 'Manœuvre', 2, 6500, WorkerAvailability::Unavailable, WorkerStatus::Archived, 'Bouaké', 4.0, 4, 'Serge Koffi', '2024-01-30'],
        ];

        foreach ($workers as $row) {
            $this->seedWorker(...$row);
        }
    }

    private function seedWorker(string $slug, string $code, string $first, string $last, string $gender, string $trade, int $exp, int $rate, WorkerAvailability $availability, WorkerStatus $status, string $commune, float $rating, int $reviews, string $recruiter, string $registered): void
    {
        $h = crc32($slug);
        $pick = fn (int $k): array => self::SITES[($h + $k * 3) % count(self::SITES)];
        [$secondary, $specialties, $skills, $certifications] = self::TRADES[$trade];
        $name = "{$first} {$last}";
        $city = in_array($commune, ['Grand-Bassam', 'Bouaké', 'Bingerville'], true) ? $commune : 'Abidjan';
        $settled = in_array($status, [WorkerStatus::Validated, WorkerStatus::Suspended, WorkerStatus::Archived], true);

        $assignments = $settled ? $this->assignments($slug, $availability, $trade, $rate, $h, $pick) : [];
        $current = collect($assignments)->firstWhere('status', 'En cours') ?? collect($assignments)->firstWhere('status', 'Remplacée') ?? collect($assignments)->firstWhere('status', 'Terminée');
        $timesheets = $current ? $this->timesheets($current) : [];
        $payouts = $current ? $this->payouts($current, $code, $h) : [];
        $reviewList = $this->reviews($name, $rating, $reviews, $exp, $h, $pick);
        $replacements = $this->replacements($slug, $assignments, $pick);

        // Une affectation remplacée est en cours de remplacement tant que le dossier n’est pas clôturé.
        if ($slug === 'siaka-bamba') {
            $assignments = array_map(fn (array $a): array => $a['status'] === 'Remplacée' ? [...$a, 'status' => 'Remplacement demandé'] : $a, $assignments);
        }

        $missions = $settled ? ($h % 12) + $exp * 4 : 0;
        $worker = Worker::create([
            'slug' => $slug,
            'code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'birth_date' => sprintf('%d-%02d-%02d', 1980 + $h % 18, $h % 12 + 1, $h % 27 + 1),
            'nationality' => match ($slug) {
                'diallo-fatoumata' => 'Guinéenne', 'moussa-ouattara' => 'Burkinabè', default => 'Ivoirienne'
            },
            'phone' => $this->phone($h, 1),
            'phone_secondary' => $h % 2 ? $this->phone($h, 5) : null,
            'email' => str_replace('-', '.', $slug).'@gmail.com',
            'address' => 'Quartier '.['Riviera 2', 'Sicogi', 'Sideci', 'Belleville', 'Zone 4', 'Koweït', 'Anono', 'Biabou'][$h % 8].', '.$commune,
            'commune' => $commune,
            'city' => $city,
            'country' => 'Côte d’Ivoire',
            'emergency_contact' => ['Marie', 'Adjoua', 'Ali', 'Rose', 'Didier', 'Fanta'][$h % 6].' '.$last,
            'emergency_phone' => $this->phone($h, 9),
            'trade' => $trade,
            'secondary_trades' => $secondary,
            'specialties' => $specialties,
            'skills' => $skills,
            'certifications' => $certifications,
            'qualification' => $exp >= 7 ? 'Ouvrier hautement qualifié' : ($exp >= 3 ? 'Ouvrier qualifié' : 'Ouvrier spécialisé'),
            'experience_years' => $exp,
            'experience_description' => "{$exp} an".($exp > 1 ? 's' : '').' d’expérience en '.mb_strtolower($trade).' sur des chantiers résidentiels et tertiaires du district d’Abidjan.',
            'daily_rate' => $rate,
            'mobility' => ['Tout le district d’Abidjan', 'Abidjan et environs (50 km)', 'Commune et communes limitrophes'][$h % 3],
            'zones' => [$commune, ...array_slice(array_values(array_diff(['Plateau', 'Yopougon', 'Bingerville', 'Marcory'], [$commune])), 0, 2)],
            'works_solo' => true,
            'works_in_team' => true,
            'accepts_contracts' => $exp >= 3,
            'id_type' => 'Carte nationale d’identité',
            'id_number' => 'CI'.substr((string) (1000000 + $h % 8999999), 0, 9),
            'id_issued_at' => '2021-03-14',
            'id_expires_at' => '2031-03-13',
            'recruiter' => $recruiter,
            'sales_zone' => ['Abidjan Nord', 'Cocody', 'Yopougon', 'Bingerville', 'Bouaké', 'Grand-Bassam'][array_search($recruiter, self::RECRUITERS, true) % 6],
            'acquisition_channel' => ['Terrain', 'Recommandation', 'Application mobile', 'Agence'][$h % 4],
            'sales_note' => 'Profil recommandé par un chef de chantier partenaire. Références vérifiées par téléphone.',
            'status' => $status,
            'availability' => $availability,
            'suspension_reason' => $slug === 'siaka-bamba' ? 'Absences répétées non justifiées avant l’arrêt médical' : null,
            'rejection' => $slug === 'ibrahim-sylla' ? ['reason' => 'Incohérence d’identité', 'comment' => 'La date de naissance du CV ne correspond pas à la pièce d’identité.', 'request' => 'Fournir une nouvelle pièce d’identité lisible'] : null,
            'identity_mismatch' => $slug === 'ibrahim-sylla',
            'possible_duplicate' => $slug === 'awa-kone',
            'rating' => $rating,
            'reviews_count' => $reviews,
            'registered_at' => $registered,
            'demo_relations' => [
                'assignments' => $assignments,
                'timesheets' => $timesheets,
                'payouts' => $payouts,
                'reviews' => $reviewList,
                'replacements' => $replacements,
                'stats' => [
                    'missions' => $missions,
                    'days' => $missions * 11 + ($settled ? $h % 30 : 0),
                    'presence' => $settled ? 88 + $h % 11 : 0,
                    'acceptance' => $settled ? 72 + $h % 23 : 0,
                    'cancellation' => $settled ? 1 + $h % 7 : 0,
                ],
            ],
        ]);

        $this->seedDocuments($worker, $h, $pick, $certifications);
        $this->seedExperiences($worker, $trade, $h, $pick);
        $this->seedPeriods($worker);
        $this->seedActivities($worker, $assignments, $timesheets, $payouts, $reviewList);
    }

    private function phone(int $h, int $n): string
    {
        $groups = array_map(fn (int $j): string => (string) ((($h >> ($n + $j * 3)) % 90) + 10), [0, 1, 2, 3]);

        return '+225 0'.['7', '5', '1'][($h >> $n) % 3].' '.implode(' ', $groups);
    }

    private function day(string $iso, int $days): string
    {
        return Carbon::parse($iso)->addDays($days)->format('Y-m-d');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignments(string $slug, WorkerAvailability $availability, string $trade, int $rate, int $h, callable $pick): array
    {
        $list = [];
        $add = function (int $k, string $status, string $start, string $end, int $progress, string $type, string $pay) use (&$list, $pick, $trade, $rate): void {
            [$site, $promoter] = $pick($k);
            $list[] = [
                'id' => 'a'.(count($list) + 1), 'site' => $site, 'promoter' => $promoter,
                'offer' => ['Renfort ', 'Équipe ', 'Lot '][$k % 3].mb_strtolower($trade), 'trade' => $trade, 'type' => $type,
                'start' => $start, 'end' => $end, 'pay' => $pay, 'amount' => $type === 'Contractuelle' ? $rate * 40 : $rate, 'status' => $status, 'progress' => $progress,
            ];
        };

        match ($availability) {
            WorkerAvailability::OnMission => $add(0, 'En cours', '2026-09-01', '2026-10-30', 42, 'Plusieurs jours', 'Hebdomadaire'),
            WorkerAvailability::Assigned => $add(0, 'Confirmée', '2026-09-29', '2026-11-15', 0, 'Contractuelle', 'Contractuel'),
            WorkerAvailability::PreAssigned => $add(0, 'En attente d’acceptation', '2026-10-06', '2026-10-31', 0, 'Plusieurs jours', 'Hebdomadaire'),
            WorkerAvailability::Available => $add(0, 'Proposée', '2026-10-05', '2026-10-09', 0, 'Ponctuelle', 'Journalier'),
            default => null,
        };
        if ($slug === 'koffi-adjoumani') {
            $add(4, 'Acceptée', '2026-10-12', '2026-12-18', 0, 'Contractuelle', 'Contractuel');
        }
        if ($slug === 'siaka-bamba') {
            $add(0, 'Remplacée', '2026-09-01', '2026-09-30', 30, 'Plusieurs jours', 'Hebdomadaire');
        }
        $add(1, 'Terminée', '2026-05-12', '2026-07-25', 100, 'Contractuelle', 'Contractuel');
        $add(2, 'Terminée', '2026-02-03', '2026-03-28', 100, 'Plusieurs jours', 'Hebdomadaire');
        if ($h % 3 === 0) {
            $add(3, 'Annulée', '2026-04-06', '2026-04-10', 0, 'Ponctuelle', 'Journalier');
        }

        return $list;
    }

    /**
     * Les 10 derniers jours ouvrés de la mission courante (dimanches exclus).
     *
     * @param  array<string, mixed>  $current
     * @return array<int, array<string, mixed>>
     */
    private function timesheets(array $current): array
    {
        $patterns = [
            ['Incomplet', '07:41', '', 'En attente', ''], ['Complet', '07:32', '17:05', 'En attente', ''], ['En retard', '08:14', '17:20', 'Validé', 'Retard de 44 min'],
            ['Validé', '07:28', '17:02', 'Validé', ''], ['Absence', '', '', 'Validé', 'Absence non justifiée'], ['Validé', '07:35', '16:58', 'Validé', ''],
            ['Contesté', '07:30', '15:40', 'Contesté', 'Heure de départ contestée par le promoteur'], ['Validé', '07:25', '17:10', 'Validé', ''],
            ['Corrigé', '07:33', '17:00', 'Validé', 'Oubli de pointage de départ'], ['À vérifier', '07:39', '17:04', 'En attente', 'Photo d’arrivée floue'],
        ];
        $done = $current['status'] === 'Terminée';
        $date = Carbon::parse($done ? $current['end'] : '2026-09-22');
        $list = [];

        for ($i = 0; count($list) < 10 && $i < 30; $i++, $date->subDay()) {
            if ($date->isSunday()) {
                continue;
            }
            [$status, $in, $out, $validation, $anomaly] = $patterns[count($list)];
            $list[] = [
                'id' => 'p'.count($list), 'date' => $date->format('Y-m-d'), 'site' => $current['site'], 'in' => $in, 'out' => $out,
                'status' => $done ? 'Validé' : $status, 'validation' => $done ? 'Validé' : $validation, 'anomaly' => $done ? '' : $anomaly,
                'correction' => $status === 'Corrigé' ? 'Départ saisi à 17:00 sur attestation du chef de chantier' : '', 'photoOk' => $status !== 'À vérifier',
            ];
        }

        return $list;
    }

    /**
     * Versements hebdomadaires de la mission courante.
     *
     * @param  array<string, mixed>  $current
     * @return array<int, array<string, mixed>>
     */
    private function payouts(array $current, string $code, int $h): array
    {
        $done = $current['status'] === 'Terminée';
        $statuses = $done ? ['Payé', 'Payé', 'Payé', 'Payé'] : ['À calculer', 'En attente de validation', 'Validé', 'Programmé', 'En cours', 'Payé', 'Payé', 'Échoué', ...($h % 2 ? ['Contesté', 'Remboursé'] : ['Suspendu'])];
        $end = $done ? $current['end'] : '2026-09-21';
        $method = ['Orange Money', 'MTN MoMo', 'Wave', 'Moov Money'][$h % 4];

        return array_map(function (string $status, int $j) use ($end, $current, $code, $method): array {
            $from = $this->day($end, -6 * ($j + 1) + 1);
            $to = $this->day($end, -6 * $j);
            $days = match ($status) {
                'À calculer' => 2, 'Contesté', 'Remboursé' => 1, default => 5 - ($j % 3 === 2 ? 1 : 0)
            };

            return [
                'id' => 'pay'.$j, 'ref' => 'PO-'.substr($code, 4).'-'.(2609 - $j * 7), 'site' => $current['site'], 'from' => $from, 'to' => $to, 'days' => $days, 'status' => $status,
                'paidAt' => in_array($status, ['Payé', 'Remboursé'], true) ? $this->day($to, 2) : ($status === 'Programmé' ? '2026-09-26' : null), 'method' => $method,
            ];
        }, $statuses, array_keys($statuses));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviews(string $name, float $rating, int $count, int $exp, int $h, callable $pick): array
    {
        $comments = ['Travail soigné et régulier, très bonne ponctualité.', 'Respect strict des consignes de sécurité.', 'Bonne entente avec l’équipe, rendement satisfaisant.', 'Quelques retards en début de mission, qualité correcte.', 'Autonome et rigoureux sur les finitions.'];
        $clamp = fn (float $v): float => max(1, min(5, round($v, 1)));

        return array_map(function (int $j) use ($name, $rating, $count, $exp, $h, $pick, $comments, $clamp): array {
            [$site, $promoter] = $pick($j + 1);
            $note = $clamp($rating + [0.2, -0.1, 0, -0.4, 0.1][$j]);

            return [
                'id' => 'av'.$j, 'team' => $j === 3 && $exp >= 3, 'site' => $site, 'promoter' => $promoter, 'date' => $this->day('2026-09-20', -$j * 23),
                'note' => $note, 'criteria' => [$note, $clamp($note + 0.2), $clamp($note - 0.1), $note, $clamp($note - 0.2)],
                'comment' => $comments[($h + $j) % 5], 'reply' => $j === 1 ? 'Merci pour ce retour, transmis à l’ouvrier et à son commercial référent.' : '',
                'status' => $j === 2 && $count > 10 ? 'Signalé' : ($j === 4 && $count > 20 ? 'Masqué' : 'Publié'),
                'teamName' => 'Équipe Bâtir Pro', 'teamNote' => $clamp($note - 0.1), 'teamComment' => 'Équipe bien coordonnée, délais tenus sur le lot gros œuvre.',
                'members' => [[$name, $note], ['Moussa Ouattara', 4.3], ['Diallo Fatoumata', 4.6]],
            ];
        }, $count > 0 ? range(0, min($count, 5) - 1) : []);
    }

    /**
     * Dossiers de remplacement (workflow en 9 étapes).
     *
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function replacements(string $slug, array $assignments, callable $pick): array
    {
        if ($slug === 'siaka-bamba') {
            $assignment = collect($assignments)->firstWhere('status', 'Remplacée');

            return [[
                'id' => 'REP-0042', 'assignmentId' => $assignment['id'] ?? null, 'site' => $assignment['site'] ?? '—', 'moment' => 'Pendant l’exécution du chantier',
                'start' => '2026-09-15', 'end' => '2026-10-10', 'reason' => 'Blessure', 'comment' => 'Blessure à la main sur chantier, arrêt de 3 semaines.',
                'urgency' => 'Élevée', 'recovery' => 'Oui — après la date de fin', 'step' => 4, 'status' => 'Remplaçant proposé', 'chosen' => null,
                'times' => ['15 sept. · 08:10', '15 sept. · 09:30', '15 sept. · 10:05', '15 sept. · 10:20'],
            ]];
        }

        if ($slug === 'mamadou-kone') {
            return [[
                'id' => 'REP-0031', 'assignmentId' => null, 'site' => $pick(1)[0], 'moment' => 'Avant le début du chantier',
                'start' => '2026-05-10', 'end' => '2026-05-16', 'reason' => 'Raison familiale', 'comment' => 'Deuil familial.',
                'urgency' => 'Moyenne', 'recovery' => 'Oui — après la date de fin', 'step' => 9, 'status' => 'Clôturé',
                'chosen' => ['name' => 'Paul Aké', 'detail' => 'Plombier · 10 ans', 'score' => 91],
                'times' => ['08 mai', '08 mai', '08 mai', '09 mai', '09 mai', '09 mai', '10 mai', '10 mai', '11 mai'],
            ]];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $certifications
     */
    private function seedDocuments(Worker $worker, int $h, callable $pick, array $certifications): void
    {
        $codes = self::DOCUMENT_CODES[$worker->status->value];
        $statuses = ['v' => DocumentStatus::Verified, 'a' => DocumentStatus::ToReview, 'm' => DocumentStatus::Missing, 'e' => DocumentStatus::Expired, 'i' => DocumentStatus::Rejected];
        $defaults = [
            WorkerDocumentType::IdFront->value => [$worker->id_number, '2031-03-13'],
            WorkerDocumentType::IdBack->value => [$worker->id_number, '2031-03-13'],
            WorkerDocumentType::ProofOfAddress->value => ['Facture CIE · août 2026', null],
            WorkerDocumentType::Cv->value => ['CV '.$worker->name, null],
            WorkerDocumentType::Diploma->value => [$certifications[0] ?? 'Attestation de formation', null],
            WorkerDocumentType::ProfessionalCertificate->value => ['Attestation '.$pick(1)[1], '2027-03-31'],
        ];
        $added = $worker->status === WorkerStatus::Validated ? $worker->registered_at : $worker->registered_at->copy()->addDay();

        foreach (WorkerDocumentType::cases() as $i => $type) {
            $status = $statuses[$codes[$i]];
            $missing = $status === DocumentStatus::Missing;
            [$reference, $expires] = $defaults[$type->value];

            $worker->documents()->create([
                'type' => $type,
                'status' => $status,
                'reference' => $missing ? null : $reference,
                'file_name' => $missing ? null : str_replace('_', '-', $type->value).'.pdf',
                'added_at' => $missing ? null : $added,
                'expires_at' => $missing ? null : ($status === DocumentStatus::Expired ? '2026-08-31' : $expires),
                'rejection_reason' => $status === DocumentStatus::Rejected ? 'Document illisible — photo floue' : null,
            ]);
        }
    }

    private function seedExperiences(Worker $worker, string $trade, int $h, callable $pick): void
    {
        foreach ([0, 1] as $k) {
            [$site, $promoter, $location] = $pick($k + 1);
            $worker->experiences()->create([
                'company' => $promoter,
                'mission' => ['Gros œuvre R+4', 'Second œuvre et finitions'][$k],
                'trade' => $trade,
                'site' => $site,
                'location' => $location,
                'started_at' => $k ? '2025-02-03' : '2025-09-01',
                'ended_at' => $k ? '2025-07-25' : '2026-03-28',
                'description' => 'Travaux réalisés au sein d’une équipe de '.(6 + $k * 4).' ouvriers.',
                'reference' => ['M. Konan — chef de chantier', 'A. Bamba — conducteur de travaux'][$k].' · '.$this->phone($h, 12 + $k),
            ]);
        }
    }

    private function seedPeriods(Worker $worker): void
    {
        $periods = match ($worker->slug) {
            'koffi-adjoumani' => [['2026-09-27', '2026-09-27', AvailabilityType::Rest, 'Repos hebdomadaire supplémentaire', null], ['2026-09-30', '2026-10-02', AvailabilityType::Partial, 'Matinées uniquement', 'Formation SST l’après-midi']],
            'issa-traore' => [['2026-10-02', '2026-10-02', AvailabilityType::DeclaredAbsence, 'Rendez-vous administratif', null]],
            'siaka-bamba' => [['2026-09-15', '2026-10-10', AvailabilityType::Unavailable, 'Blessure à la main — arrêt médical', 'Certificat médical fourni']],
            default => [],
        };

        foreach ($periods as [$from, $to, $type, $reason, $comment]) {
            $worker->periods()->create(['starts_on' => $from, 'ends_on' => $to, 'type' => $type, 'reason' => $reason, 'zone' => $worker->commune, 'comment' => $comment]);
        }
    }

    /**
     * Journal reconstitué à partir des données de l’ouvrier (comme dans la maquette).
     *
     * @param  array<int, array<string, mixed>>  $assignments
     * @param  array<int, array<string, mixed>>  $timesheets
     * @param  array<int, array<string, mixed>>  $payouts
     * @param  array<int, array<string, mixed>>  $reviews
     */
    private function seedActivities(Worker $worker, array $assignments, array $timesheets, array $payouts, array $reviews): void
    {
        $registered = $worker->registered_at->format('Y-m-d');
        $settled = in_array($worker->status, [WorkerStatus::Validated, WorkerStatus::Suspended, WorkerStatus::Archived], true);
        $events = [[WorkerActivityCategory::Creation, $worker->recruiter, 'a créé le profil', '', 'Profil incomplet', 'Inscription terrain', 'Application commercial', $registered, '09:12']];

        if ($settled || $worker->status === WorkerStatus::Rejected) {
            $events[] = [WorkerActivityCategory::Status, 'Système', 'a soumis le profil pour vérification', 'Profil incomplet', 'À vérifier', '', 'Système', $this->day($registered, 1), '10:03'];
        }
        if ($settled) {
            $events[] = [WorkerActivityCategory::Validation, 'N. Yao', 'a validé le profil', 'À vérifier', 'Validé', 'Pièces conformes', 'Back-office', $this->day($registered, 3), '16:40'];
            foreach (array_reverse($assignments) as $a) {
                $events[] = [WorkerActivityCategory::Assignment, 'A. Bamba', 'a affecté l’ouvrier à '.$a['site'], '', $a['status'], $a['offer'], 'Back-office', $this->day($a['start'], -5), '11:20'];
            }
        }
        if ($first = $timesheets[0] ?? null) {
            $events[] = [WorkerActivityCategory::Timesheet, $worker->name, 'a pointé son arrivée', '', $first['in'] ?: '—', $first['site'], 'Application mobile', $first['date'], $first['in'] ?: '07:40'];
        }
        if ($paid = collect($payouts)->firstWhere('status', 'Payé')) {
            $events[] = [WorkerActivityCategory::Payment, 'Système', 'a versé un paiement', 'Programmé', 'Payé', $paid['ref'], 'Système', $paid['paidAt'], '18:00'];
        }
        if ($review = $reviews[0] ?? null) {
            $events[] = [WorkerActivityCategory::Review, $review['promoter'], 'a publié un avis', '', number_format($review['note'], 1, ',', '').' / 5', $review['site'], 'Application mobile', $review['date'], '19:05'];
        }
        if ($worker->status === WorkerStatus::Rejected) {
            $events[] = [WorkerActivityCategory::Rejection, 'N. Yao', 'a rejeté le profil', 'À vérifier', 'Rejeté', $worker->rejection['comment'], 'Back-office', '2026-08-05', '15:12'];
        }
        if ($worker->status === WorkerStatus::Suspended) {
            $events[] = [WorkerActivityCategory::Unavailability, $worker->name, 'a déclaré une indisponibilité', 'Disponible', 'Indisponible', 'Blessure à la main', 'Application mobile', '2026-09-15', '07:55'];
            $events[] = [WorkerActivityCategory::Replacement, 'N. Yao', 'a lancé un remplacement', '', 'Signalé', 'REP-0042', 'Back-office', '2026-09-15', '08:10'];
            $events[] = [WorkerActivityCategory::Suspension, 'N. Yao', 'a suspendu le profil', 'Validé', 'Suspendu', $worker->suspension_reason, 'Back-office', '2026-09-16', '17:30'];
        }
        if ($worker->status === WorkerStatus::Archived) {
            $events[] = [WorkerActivityCategory::Status, 'N. Yao', 'a archivé le profil', 'Validé', 'Archivé', 'Inactif depuis plus de 6 mois', 'Back-office', '2026-07-01', '10:00'];
        }
        if ($period = $worker->periods()->first()) {
            $events[] = [WorkerActivityCategory::Availability, $worker->name, 'a mis à jour sa disponibilité', '', $period->type->label(), $period->reason, 'Application mobile', $period->starts_on->copy()->subDays(3)->format('Y-m-d'), '20:15'];
        }

        foreach ($events as [$category, $author, $action, $old, $new, $comment, $origin, $date, $time]) {
            $worker->activities()->create([
                'occurred_at' => "{$date} {$time}",
                'author' => $author,
                'category' => $category,
                'action' => $action,
                'old_value' => $old ?: null,
                'new_value' => $new ?: null,
                'comment' => $comment ?: null,
                'origin' => $origin,
            ]);
        }
    }
}
