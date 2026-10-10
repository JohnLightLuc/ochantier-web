<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\ActivityAuthorKind;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Models\Promoter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Promoteurs de la maquette O’CHANTIER ADMIN, avec leur dossier KYC et leur journal d’activité.
 */
class PromoterSeeder extends Seeder
{
    /**
     * Statuts des 5 pièces KYC, dans l’ordre de DocumentType : v vérifié, a à vérifier, e expiré, r rejeté, m manquant.
     */
    private const DOCUMENT_CODES = ['v' => DocumentStatus::Verified, 'a' => DocumentStatus::ToReview, 'e' => DocumentStatus::Expired, 'r' => DocumentStatus::Rejected, 'm' => DocumentStatus::Missing];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ($this->promoters() as $data) {
            $promoter = Promoter::create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'representative' => $data['representative'],
                'representative_role' => $data['role'],
                'account_type' => $data['type'],
                'legal_form' => $data['form'],
                'rccm' => $data['rccm'],
                'ncc' => $data['ncc'],
                'sector' => $data['sector'],
                'head_office' => $data['office'],
                'location' => $data['location'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'status' => $data['status'],
                'payment_status' => $data['payment'],
                'registered_at' => $data['registered'],
                'last_active_at' => $data['last'],
                'suspension_reason' => $data['suspension'] ?? null,
                'suspended_at' => $data['suspended'] ?? null,
                'demo_relations' => [
                    'sites' => $this->pick($this->sites(), $data['sites']),
                    'offers' => $this->pick($this->offers(), $data['offers']),
                    'payments' => array_map(
                        fn (array $payment): array => $payment['beneficiary'] === 'Promoteur' ? [...$payment, 'beneficiary' => $data['name']] : $payment,
                        $this->pick($this->payments(), $data['payments']),
                    ),
                    'reviews' => $this->pick($this->reviews(), $data['reviews']),
                    'disputes' => $this->pick($this->disputes(), $data['disputes']),
                ],
            ]);

            $this->seedDocuments($promoter, $data['documents']);
            $this->seedActivities($promoter, $data);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @param  array<int, int>  $indexes
     * @return array<int, array<string, mixed>>
     */
    private function pick(array $pool, array $indexes): array
    {
        return array_map(fn (int $i): array => $pool[$i], $indexes);
    }

    private function seedDocuments(Promoter $promoter, string $codes): void
    {
        $defaults = [
            DocumentType::TradeRegister->value => [$promoter->rccm, '2024-01-12', null],
            DocumentType::RepresentativeId->value => ['CNI C0045 8812', '2024-01-12', '2029-03-04'],
            DocumentType::TaxCertificate->value => ['AF-2026-00871', '2026-03-03', '2026-12-31'],
            DocumentType::ProofOfAddress->value => ['Facture CIE · nov. 2025', '2025-11-14', '2027-05-14'],
            DocumentType::Additional->value => ['Statuts de la société', '2024-01-12', null],
        ];

        // Les pièces d’un compte inscrit après le 12 janvier 2024 datent de son inscription.
        $addedAtRegistration = $promoter->registered_at->isAfter('2024-01-12');

        foreach (DocumentType::cases() as $i => $type) {
            $status = self::DOCUMENT_CODES[$codes[$i]];
            [$reference, $added, $expires] = $defaults[$type->value];
            $missing = $status === DocumentStatus::Missing;

            $promoter->documents()->create([
                'type' => $type,
                'status' => $status,
                'reference' => $missing ? null : $reference,
                'added_at' => $missing ? null : ($addedAtRegistration ? $promoter->registered_at : $added),
                'expires_at' => $missing ? null : ($status === DocumentStatus::Expired ? '2026-05-14' : $expires),
            ]);
        }
    }

    /**
     * Journal d’activité reconstitué à partir des données du promoteur (comme dans la maquette).
     *
     * @param  array<string, mixed>  $data
     */
    private function seedActivities(Promoter $promoter, array $data): void
    {
        $base = '/promoteurs/'.$promoter->slug;
        $events = [];
        $add = function (string $at, string $author, ActivityAuthorKind $kind, string $action, ?string $target, string $icon, string $tone, ?string $link = null) use (&$events): void {
            $events[] = compact('at', 'author', 'kind', 'action', 'target', 'icon', 'tone', 'link');
        };
        $relations = $promoter->demo_relations;

        $add('2026-09-23 09:42', $promoter->representative, ActivityAuthorKind::Promoter, 's’est connecté à son espace', 'Espace promoteur', 'login', 'neutral');

        $paid = collect($relations['payments'])->first(fn (array $p): bool => $p['status'] === 'Réussi' && $p['type'] !== 'Commission');
        if ($paid) {
            $add($this->paymentDate($paid['date']), 'Système', ActivityAuthorKind::System, 'a confirmé un paiement', $paid['ref'].' · '.number_format($paid['amount'], 0, ',', ' ').' FCFA', 'payments', 'success', "{$base}/paiements/{$paid['ref']}");
        }
        if ($dispute = $relations['disputes'][0] ?? null) {
            $add($dispute['opened_at'].' 14:10', $promoter->representative, ActivityAuthorKind::Promoter, 'a ouvert un litige', $dispute['ref'].' · '.$dispute['reason'], 'gavel', 'danger', "{$base}/litiges");
        }
        if ($offer = $relations['offers'][0] ?? null) {
            $add('2026-09-14 11:05', $promoter->representative, ActivityAuthorKind::Promoter, 'a publié un besoin', $offer['name'].' · '.$offer['site'], 'campaign', 'info', "{$base}/offres/{$offer['id']}");
        }
        if ($site = $relations['sites'][0] ?? null) {
            $add('2026-09-02 15:30', $promoter->representative, ActivityAuthorKind::Promoter, 'a créé un chantier', $site['name'], 'construction', 'success', "{$base}/chantiers/{$site['id']}");
        }
        if ($data['suspended'] ?? null) {
            $add($data['suspended'].' 16:20', 'N. Yao', ActivityAuthorKind::Admin, 'a suspendu le compte', 'Retards de paiement', 'block', 'danger');
        }
        if ($data['documents'][0] === 'v') {
            $add('2026-08-28 10:12', 'N. Yao', ActivityAuthorKind::Admin, 'a modifié le profil', 'Téléphone du représentant', 'edit', 'purple');
            $add($data['registered'].' 16:48', 'N. Yao', ActivityAuthorKind::Admin, 'a validé le dossier KYC', 'Registre de commerce, pièce d’identité', 'verified', 'success', "{$base}/kyc");
        }
        $add($data['registered'].' 09:15', $promoter->representative, ActivityAuthorKind::Promoter, 'a créé son compte', $promoter->name, 'person_add', 'neutral');

        foreach ($events as $event) {
            $promoter->activities()->create([
                'occurred_at' => $event['at'],
                'author' => $event['author'],
                'author_kind' => $event['kind'],
                'action' => $event['action'],
                'target' => $event['target'],
                'icon' => $event['icon'],
                'tone' => $event['tone'],
                'link' => $event['link'],
            ]);
        }
    }

    /**
     * « 22 sept. 2026 · 10:42 » → « 2026-09-22 10:42 ».
     */
    private function paymentDate(string $label): string
    {
        $months = ['janv.' => 1, 'févr.' => 2, 'mars' => 3, 'avr.' => 4, 'mai' => 5, 'juin' => 6, 'juil.' => 7, 'août' => 8, 'sept.' => 9, 'oct.' => 10, 'nov.' => 11, 'déc.' => 12];
        preg_match('/(\d{1,2}) (\S+) (\d{4}) · (\d{2}:\d{2})/u', $label, $m);

        return Carbon::create((int) $m[3], $months[$m[2]], (int) $m[1])->format('Y-m-d').' '.$m[4];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function promoters(): array
    {
        return [
            ['slug' => 'sicogi', 'name' => 'SICOGI', 'representative' => 'Mireille Konan', 'role' => 'Directrice des opérations', 'type' => AccountType::Institution, 'form' => 'Société d’État', 'rccm' => 'CI-ABJ-1962-B-2104', 'ncc' => '6200154 K', 'sector' => 'Promotion immobilière', 'office' => 'Avenue Terrasson de Fougères, Abidjan-Plateau', 'location' => 'Abidjan, Plateau', 'phone' => '+225 27 20 25 10 00', 'email' => 'm.konan@sicogi.ci', 'status' => PromoterStatus::Active, 'payment' => PromoterPaymentStatus::UpToDate, 'registered' => '2024-01-12', 'last' => '2026-09-23 09:42', 'sites' => [0, 1, 2, 3, 4, 5, 6], 'offers' => [0, 1, 2, 3, 4, 5, 6, 7], 'payments' => [0, 1, 2, 3, 4, 5, 6, 7], 'reviews' => [0, 1, 2, 3], 'disputes' => [0, 1], 'documents' => 'vvaem'],
            ['slug' => 'ivoire-construction', 'name' => 'Ivoire Construction', 'representative' => 'Koffi N’Guessan', 'role' => 'Gérant', 'type' => AccountType::Company, 'form' => 'SARL', 'rccm' => 'CI-ABJ-2015-B-11872', 'ncc' => '1512987 A', 'sector' => 'BTP · Gros œuvre', 'office' => 'Rue des Jardins, Abidjan-Cocody', 'location' => 'Abidjan, Cocody', 'phone' => '+225 07 08 25 14 63', 'email' => 'k.nguessan@ivoireconstruction.ci', 'status' => PromoterStatus::Active, 'payment' => PromoterPaymentStatus::UpToDate, 'registered' => '2024-03-03', 'last' => '2026-09-22 17:20', 'sites' => [0, 1, 2, 3, 4, 5, 6, 7], 'offers' => [0, 1, 3, 7], 'payments' => [0, 1, 4], 'reviews' => [0, 1], 'disputes' => [], 'documents' => 'vvvvv'],
            ['slug' => 'mk-immobilier', 'name' => 'MK Immobilier', 'representative' => 'Mariam Koné', 'role' => 'Directrice générale', 'type' => AccountType::Company, 'form' => 'SA', 'rccm' => 'CI-ABJ-2018-B-20431', 'ncc' => '1820554 C', 'sector' => 'Promotion immobilière', 'office' => 'Boulevard de la République, Abidjan-Plateau', 'location' => 'Abidjan, Plateau', 'phone' => '+225 05 66 42 18 90', 'email' => 'mariam.kone@mkimmo.ci', 'status' => PromoterStatus::Active, 'payment' => PromoterPaymentStatus::UpToDate, 'registered' => '2024-05-21', 'last' => '2026-09-23 08:05', 'sites' => [1, 2, 3, 4, 7], 'offers' => [1, 2], 'payments' => [1, 4, 6], 'reviews' => [1], 'disputes' => [0], 'documents' => 'vvvvm'],
            ['slug' => 'horizon-btp', 'name' => 'Horizon BTP', 'representative' => 'Yao Kouassi', 'role' => 'Fondateur', 'type' => AccountType::Company, 'form' => 'SARL', 'rccm' => 'CI-ABJ-2026-B-03318', 'ncc' => '2604412 F', 'sector' => 'BTP · Second œuvre', 'office' => 'Route de Bingerville, Bingerville', 'location' => 'Bingerville', 'phone' => '+225 01 74 28 10 55', 'email' => 'y.kouassi@horizonbtp.ci', 'status' => PromoterStatus::Pending, 'payment' => PromoterPaymentStatus::Pending, 'registered' => '2026-09-15', 'last' => '2026-09-20 11:00', 'sites' => [2, 5, 7], 'offers' => [5], 'payments' => [7], 'reviews' => [], 'disputes' => [], 'documents' => 'aaamm'],
            ['slug' => 'residences-plus', 'name' => 'Résidences Plus', 'representative' => 'Fatou Diarra', 'role' => 'Responsable projets', 'type' => AccountType::Company, 'form' => 'SAS', 'rccm' => 'CI-YAM-2019-B-00562', 'ncc' => '1904771 B', 'sector' => 'Promotion immobilière', 'office' => 'Quartier Habitat, Yamoussoukro', 'location' => 'Yamoussoukro', 'phone' => '+225 07 57 91 33 20', 'email' => 'f.diarra@residencesplus.ci', 'status' => PromoterStatus::Active, 'payment' => PromoterPaymentStatus::UpToDate, 'registered' => '2024-08-08', 'last' => '2026-09-21 15:30', 'sites' => [0, 1, 3, 4, 6, 7], 'offers' => [0, 3, 4, 6], 'payments' => [0, 2, 4], 'reviews' => [0, 3], 'disputes' => [], 'documents' => 'vvvvv'],
            ['slug' => 'batir-afrique', 'name' => 'Bâtir Afrique', 'representative' => 'Serge Aka', 'role' => 'Gérant', 'type' => AccountType::Company, 'form' => 'SARL', 'rccm' => 'CI-BKE-2020-B-01104', 'ncc' => '2011053 D', 'sector' => 'BTP · Gros œuvre', 'office' => 'Quartier Commerce, Bouaké', 'location' => 'Bouaké', 'phone' => '+225 05 04 88 71 19', 'email' => 's.aka@batirafrique.ci', 'status' => PromoterStatus::Suspended, 'payment' => PromoterPaymentStatus::Late, 'registered' => '2025-02-11', 'last' => '2026-09-11 16:20', 'sites' => [3, 6], 'offers' => [6], 'payments' => [6], 'reviews' => [], 'disputes' => [0], 'documents' => 'vveev', 'suspension' => 'Retards de paiement — 3 échéances impayées sur Logements sociaux Yopougon.', 'suspended' => '2026-09-11'],
            ['slug' => 'at-developpement', 'name' => 'AT Développement', 'representative' => 'Aïcha Traoré', 'role' => 'Directrice', 'type' => AccountType::Company, 'form' => 'SARL', 'rccm' => 'CI-GBA-2021-B-00219', 'ncc' => '2107318 E', 'sector' => 'Aménagement', 'office' => 'Boulevard de France, Grand-Bassam', 'location' => 'Grand-Bassam', 'phone' => '+225 07 78 40 12 33', 'email' => 'a.traore@atdev.ci', 'status' => PromoterStatus::Active, 'payment' => PromoterPaymentStatus::UpToDate, 'registered' => '2025-10-19', 'last' => '2026-09-23 07:50', 'sites' => [0, 2, 4, 7], 'offers' => [3, 7], 'payments' => [0, 1], 'reviews' => [0], 'disputes' => [], 'documents' => 'vvvva'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sites(): array
    {
        $rows = [
            ['Résidence Les Oliviers', 'Abidjan, Cocody', 'Résidentiel collectif', 'Janv. 2024 – Déc. 2026', 28, 75, 'En cours'],
            ['Les Jardins d’Angré', 'Abidjan, Cocody Angré', 'Résidentiel', 'Mars 2025 – Juin 2027', 36, 40, 'En cours'],
            ['Cité Administrative Plateau', 'Abidjan, Plateau', 'Bureaux', 'Nov. 2026 – Déc. 2027', 0, 0, 'À démarrer'],
            ['Logements sociaux Yopougon', 'Abidjan, Yopougon', 'Logement social', 'Févr. 2025 – Nov. 2026', 44, 62, 'En retard'],
            ['Résidence Bingerville Lot B', 'Bingerville', 'Résidentiel', 'Mai 2023 – Juil. 2025', 0, 100, 'Terminé'],
            ['Extension Cité Verte', 'Songon', 'Lotissement', 'À planifier', 0, 0, 'Brouillon'],
            ['Tour Ivoire Business', 'Abidjan, Plateau', 'Bureaux', 'Avr. 2024 – Oct. 2026', 12, 30, 'Suspendu'],
            ['Villa Duplex Riviera 3', 'Abidjan, Riviera', 'Villa', 'Juin 2026 – Mars 2027', 9, 15, 'En cours'],
        ];

        return array_map(fn (array $r, int $i): array => ['id' => 'ch'.($i + 1), 'name' => $r[0], 'location' => $r[1], 'type' => $r[2], 'period' => $r[3], 'workers' => $r[4], 'progress' => $r[5], 'status' => $r[6]], $rows, array_keys($rows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function offers(): array
    {
        $sites = array_column($this->sites(), 'name');
        $rows = [
            ['Renfort maçonnerie', 0, 'Maçon', 'Ponctuelle', 12, '25 000 FCFA/j', '15 oct. 2026', 'Ouverte'],
            ['Coffrage niveau R+4', 1, 'Coffreur', 'Plusieurs jours', 8, '22 000 FCFA/j', '28 oct. 2026', 'Candidatures reçues'],
            ['Électricité bâtiment C', 3, 'Électricien', 'Contractuelle', 5, '1 800 000 FCFA', '10 nov. 2026', 'À valider'],
            ['Pose de carrelage hall', 0, 'Carreleur', 'Plusieurs jours', 9, '20 000 FCFA/j', '5 nov. 2026', 'Validée'],
            ['Étanchéité toiture', 1, 'Étancheur', 'Ponctuelle', 0, '30 000 FCFA/j', '30 sept. 2026', 'Expirée'],
            ['Plomberie sanitaires', 2, 'Plombier', 'Plusieurs jours', 0, '20 000 FCFA/j', '—', 'Brouillon'],
            ['Peinture façades', 3, 'Peintre', 'Ponctuelle', 3, '18 000 FCFA/j', '12 sept. 2026', 'Annulée'],
            ['Chef d’équipe gros œuvre', 0, 'Chef d’équipe', 'Contractuelle', 4, '2 400 000 FCFA', '20 nov. 2026', 'Ouverte'],
        ];

        return array_map(fn (array $r, int $i): array => ['id' => 'of'.($i + 1), 'name' => $r[0], 'site' => $sites[$r[1]], 'trade' => $r[2], 'type' => $r[3], 'applications' => $r[4], 'budget' => $r[5], 'due' => $r[6], 'status' => $r[7]], $rows, array_keys($rows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(): array
    {
        $sites = array_column($this->sites(), 'name');
        $rows = [
            ['PAY-260922-1842', 0, 'Acompte', 'Mobile Money', 3750000, '22 sept. 2026 · 10:42', 'Réussi', 'Koffi Adjoumani'],
            ['PAY-260919-1730', 1, 'Paiement hebdomadaire', 'Virement', 1540000, '19 sept. 2026 · 16:05', 'Réussi', 'Équipe Bâtir Pro'],
            ['PAY-260918-1702', 3, 'Paiement contractuel', 'Virement', 2400000, '18 sept. 2026 · 11:20', 'À autoriser', 'Équipe Menuiserie CI'],
            ['PAY-260917-1688', 0, 'Paiement journalier', 'Wave', 450000, '17 sept. 2026 · 18:40', 'En traitement', 'Koffi Adjoumani'],
            ['PAY-260915-1654', 0, 'Commission', 'Interne', 280000, '15 sept. 2026 · 09:00', 'Réussi', 'O’CHANTIER'],
            ['PAY-260912-1611', 6, 'Remboursement', 'Virement', 600000, '12 sept. 2026 · 14:22', 'Remboursé', 'Promoteur'],
            ['PAY-260910-1590', 1, 'Paiement hebdomadaire', 'Orange Money', 980000, '10 sept. 2026 · 17:15', 'Échoué', 'Mamadou Koné'],
            ['PAY-260909-1575', 3, 'Acompte', 'Mobile Money', 1200000, '9 sept. 2026 · 08:30', 'En attente', 'Issa Bamba'],
        ];

        return array_map(fn (array $r): array => [
            'ref' => $r[0], 'site' => $sites[$r[1]], 'type' => $r[2], 'method' => $r[3], 'amount' => $r[4],
            'commission' => in_array($r[2], ['Commission', 'Remboursement'], true) ? 0 : (int) round($r[4] * 0.08),
            'date' => $r[5], 'status' => $r[6], 'beneficiary' => $r[7],
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviews(): array
    {
        $sites = array_column($this->sites(), 'name');
        $rows = [
            ['Koffi Adjoumani', 'Maçon', 0, '4,8', 'Travail soigné, ponctuel sur toute la durée du lot.', '21 sept. 2026', 'Publié'],
            ['Équipe Bâtir Pro', '6 membres', 1, '4,5', 'Bonne coordination avec le chef de chantier.', '16 sept. 2026', 'Publié'],
            ['Mamadou Koné', 'Plombier', 3, '3,2', 'Finitions à reprendre sur deux logements.', '9 sept. 2026', 'À modérer'],
            ['Issa Bamba', 'Carreleur', 0, '4,6', 'Très bon rendement, pose régulière.', '2 sept. 2026', 'Masqué'],
        ];

        return array_map(fn (array $r, int $i): array => ['id' => 'av'.$i, 'author' => $r[0], 'detail' => $r[1], 'site' => $sites[$r[2]], 'rating' => $r[3], 'comment' => $r[4], 'date' => $r[5], 'status' => $r[6]], $rows, array_keys($rows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function disputes(): array
    {
        $sites = array_column($this->sites(), 'name');

        return [
            ['ref' => 'LIT-0102', 'party' => 'Équipe Bâtir Pro', 'site' => $sites[3], 'reason' => 'Retard de livraison du lot électricité', 'priority' => 'Haute', 'date' => '16 sept. 2026', 'opened_at' => '2026-09-16', 'owner' => 'Awa Bamba', 'status' => 'En analyse'],
            ['ref' => 'LIT-0087', 'party' => 'Koffi Adjoumani', 'site' => $sites[0], 'reason' => 'Paiement journalier contesté', 'priority' => 'Moyenne', 'date' => '2 août 2026', 'opened_at' => '2026-08-02', 'owner' => 'N. Yao', 'status' => 'Résolu'],
        ];
    }
}
