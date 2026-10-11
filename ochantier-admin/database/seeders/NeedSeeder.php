<?php

namespace Database\Seeders;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedDocumentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use App\Http\Requests\NeedRequest;
use App\Models\Need;
use App\Models\NeedOffer;
use App\Models\Site;
use App\Support\WorkerPool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Besoins de la maquette : chantier, urgence, source, dates relatives à la date de référence, lignes par métier
 * (effectif, ouvriers trouvés et refusés) et cas particuliers (anomalie, validation promoteur, acompte, suspension…).
 *
 * Les ouvriers trouvés sont pris dans le module Ouvriers (même métier), complétés par des profils de démonstration.
 */
class NeedSeeder extends Seeder
{
    /**
     * Chantier, urgence, source, création (J±n), démarrage (J±n), durée (jours), responsable,
     * lignes [corps, métier, spécialité, effectif, expérience, coût, trouvés, refusés, urgence de la ligne], options.
     *
     * @var array<int, array<int, mixed>>
     */
    private const NEEDS = [
        ['Résidence Les Oliviers', 'Élevé', 'Application Promoteur', -14, 6, 42, 'N. Yao', [['Gros œuvre', 'Maçon', 'Maçonnerie générale', 6, 'Expérimenté', 12000, 5, 0], ['Finitions', 'Carreleur', 'Carrelage sol', 3, 'Intermédiaire', 10000, 0, 0, 'Normal']], ['dl' => 2]],
        ['Tour Horizon', 'Urgent', 'Application Promoteur', -6, 4, 5, 'A. Koné', [['Électricité', 'Électricien', 'Courant fort', 4, 'Expérimenté', 13000, 0, 0]], ['dl' => 2, 'just' => 'Mise en service du tableau général basse tension exigée avant le 02/10/2026.']],
        ['Cité Belle Rive', 'Normal', 'Application Promoteur', -1, 28, 120, 'N. Yao', [['Réseaux', 'Plombier', 'Sanitaires', 3, 'Expérimenté', 11000, 0, 0], ['Gros œuvre', 'Manœuvre', 'Manutention', 5, 'Débutant', 7000, 0, 0, 'Faible']], ['dl' => 20]],
        ['Complexe scolaire SA', 'Normal', 'Back-office', -30, -3, 28, 'N. Yao', [['Finitions', 'Peintre', 'Peinture intérieure', 4, 'Intermédiaire', 9500, 4, 0]], ['dl' => -8, 'pv' => true]],
        ['Centre de santé d’Anyama', 'Élevé', 'Application Promoteur', -3, 40, 150, 'M. Diabaté', [['Structure', 'Coffreur', 'Coffrage bois', 6, 'Expérimenté', 13000, 2, 0], ['Structure', 'Ferrailleur', 'Armatures', 4, 'Intermédiaire', 11000, 0, 0, 'Normal']], ['dl' => 30]],
        ['Marché de Koumassi', 'Normal', 'Commercial terrain', -4, 20, 10, 'A. Koné', [['Menuiserie', 'Menuisier', 'Menuiserie bois', 2, 'Expérimenté', 12000, 0, 0]], ['dl' => 12, 'anom' => ['Plan du chantier manquant'], 'fin' => 17]],
        ['Résidence Les Oliviers', 'Critique', 'Back-office', -2, 2, 14, 'M. Diabaté', [['Électricité', 'Électricien', 'Courant faible', 2, 'Expérimenté', 13500, 0, 0]], ['dl' => 1, 'just' => 'Deux électriciens indisponibles ; réception du lot électricité prévue le 09/10/2026.']],
        ['Tour Horizon', 'Normal', 'Application Promoteur', -9, 12, 21, 'A. Koné', [['Réseaux', 'Plombier', 'Réseaux PVC', 2, 'Intermédiaire', 11000, 2, 0], ['Gros œuvre', 'Manœuvre', 'Préparation', 3, 'Débutant', 7000, 3, 1]], ['dl' => 6]],
        ['Entrepôt logistique Vridi', 'Faible', 'Application Promoteur', 0, 35, 90, 'S. Kouamé', [['Métallerie', 'Soudeur', 'Soudure à l’arc', 2, 'Expérimenté', 12500, 0, 0]], ['dl' => 25]],
        ['Cité Belle Rive', 'Élevé', 'Application Promoteur', -12, 9, 6, 'N. Yao', [['Gros œuvre', 'Manœuvre', 'Manutention', 8, 'Débutant', 6500, 3, 0]], ['dl' => -2]],
        ['Complexe scolaire SA', 'Normal', 'Application Promoteur', -40, -12, 35, 'M. Diabaté', [['Finitions', 'Carreleur', 'Faïence murale', 3, 'Intermédiaire', 10000, 3, 0]], ['dl' => -15, 'pv' => true]],
        ['Rénovation Hôtel Tiama', 'Normal', 'Back-office', -160, -140, 120, 'N. Yao', [['Finitions', 'Peintre', 'Peinture décorative', 3, 'Expert', 12000, 3, 0]], ['dl' => -142, 'pv' => true, 'man' => 'closed']],
        ['Marché de Koumassi', 'Normal', 'Application Promoteur', -18, 3, 60, 'A. Koné', [['Gros œuvre', 'Maçon', 'Maçonnerie générale', 4, 'Intermédiaire', 11000, 1, 0]], ['dl' => 1, 'man' => 'suspended', 'motif' => 'Suspension demandée par le promoteur (révision du planning).']],
        ['Tour Horizon', 'Faible', 'Application Promoteur', -20, 15, 5, 'N. Yao', [['Menuiserie', 'Menuisier', 'Pose de portes', 1, 'Junior', 9000, 0, 0]], ['dl' => -4]],
        ['Résidence Les Oliviers', 'Normal', 'Application Promoteur', -10, 1, 3, 'M. Diabaté', [['Gros œuvre', 'Manœuvre', 'Manutention', 4, 'Débutant', 7000, 4, 0]], ['dl' => -1, 'va' => true]],
        ['Centre de santé d’Anyama', 'Normal', 'Application Promoteur', -20, 30, 21, 'A. Koné', [['Électricité', 'Électricien', 'Courant fort', 2, 'Intermédiaire', 11000, 0, 0]], ['dl' => 10, 'man' => 'cancelled', 'motif' => 'Report du lot électricité à la phase 2.']],
        ['Cité Belle Rive', 'Élevé', 'Application Promoteur', -21, 5, 28, 'N. Yao', [['Gros œuvre', 'Maçon', 'Chaînages', 5, 'Expérimenté', 12000, 0, 1]], ['dl' => -3]],
        ['Complexe scolaire SA', 'Urgent', 'Commercial terrain', -1, 5, 10, 'M. Diabaté', [['Structure', 'Ferrailleur', 'Façonnage', 3, 'Intermédiaire', 10500, 1, 0]], ['dl' => 3, 'just' => 'Coulage de la dalle du R+1 programmé ; l’équipe actuelle est incomplète.']],
        ['Marché de Koumassi', 'Normal', 'Application Promoteur', -8, 18, 28, 'A. Koné', [['Finitions', 'Peintre', 'Peinture extérieure', 2, 'Intermédiaire', 9500, 0, 0]], ['dl' => 12]],
        ['Tour Horizon', 'Normal', 'Application Promoteur', -25, -5, 60, 'S. Kouamé', [['Structure', 'Coffreur', 'Coffrage métallique', 4, 'Expérimenté', 13000, 4, 0], ['Structure', 'Ferrailleur', 'Armatures', 2, 'Intermédiaire', 11000, 2, 0]], ['dl' => -10, 'va' => true, 'pay' => true]],
    ];

    private const DOCUMENTS = [['Cahier des charges', 'cahier-des-charges'], ['Fiche de mission', 'fiche-mission'], ['Plan du chantier', 'plan-chantier'], ['Photos du chantier', 'photos-chantier'], ['Consignes de sécurité', 'consignes-securite'], ['Planning', 'planning']];

    private int $offerNumber = 301;

    private int $assignmentNumber = 1201;

    private WorkerPool $pool;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->pool = new WorkerPool;
        $sites = Site::query()->get()->keyBy('name');

        foreach (self::NEEDS as $i => $spec) {
            $this->seedNeed($i, $spec, $sites->get($spec[0]));
        }
    }

    /**
     * @param  array<int, mixed>  $spec
     */
    private function seedNeed(int $i, array $spec, Site $site): void
    {
        [, $urgencyLabel, $source, $created, $start, $duration, $admin, $lines] = $spec;
        $ex = $spec[8] ?? [];
        $today = Need::referenceDate();
        $number = sprintf('%04d', 141 + $i);
        $slug = "bes-2026-{$number}";
        $h = crc32($slug);
        $urgency = NeedUrgency::fromLabel($urgencyLabel);
        $startsOn = $today->addDays($start);
        $endsOn = isset($ex['fin']) ? $today->addDays($ex['fin']) : $startsOn->addDays($duration);
        $createdOn = $today->addDays($created);
        $type = match (true) {
            $urgency->isPressing() => 'Besoin urgent',
            $duration <= 7 => 'Besoin journalier',
            $duration <= 45 => 'Besoin hebdomadaire',
            default => 'Besoin contractuel',
        };
        $justification = $ex['just'] ?? null;
        $manual = isset($ex['man']) ? NeedManualStatus::from($ex['man']) : null;

        $need = Need::create([
            'slug' => $slug,
            'reference' => "BES-2026-{$number}",
            'site_id' => $site->id,
            'urgency' => $urgency,
            'type' => $type,
            'source' => $source,
            'admin' => $admin,
            'district' => ['Riviera 3', 'Zone 4', 'Sicogi', 'Remblais', 'Cité Verte', 'Centre commercial'][$h % 6],
            'contact' => $site->manager,
            'contact_phone' => preg_replace('/\D/', '', substr((string) $site->manager_phone, 5)),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'working_hours' => '07:30 – 17:00 · pause 12:00 – 13:00',
            'working_days' => 'Lun, Mar, Mer, Jeu, Ven, Sam',
            'justification' => $justification,
            'coverage_deadline' => $justification ? $startsOn->subDay() : null,
            'follow_up' => $justification ? $admin : null,
            'conditions' => 'EPI obligatoires · pointage via l’application · briefing sécurité le premier jour',
            'requirements' => ['Pièce d’identité', 'Profil vérifié', 'Absence de litige actif'],
            'anomalies' => $ex['anom'] ?? [],
            'manual_status' => $manual,
            'reason' => $ex['motif'] ?? null,
            'promoter_validated' => (bool) ($ex['pv'] ?? false),
            'deposit_paid' => (bool) (($ex['pv'] ?? false) || ($ex['pay'] ?? false)),
            'created_on' => $createdOn,
            'updated_on' => $today->addDays(min(0, $created + 1 + $h % 3)),
        ]);

        $payType = match ($type) {
            'Besoin hebdomadaire' => 'Hebdomadaire',
            'Besoin contractuel' => 'Contractuel',
            default => 'Journalier',
        };

        foreach ($lines as $j => [$group, $trade, $specialty, $quantity, $experience, $cost, $found, $refused]) {
            $details = NeedRequest::TRADE_DETAILS[$trade];
            $line = $need->lines()->create([
                'position' => $j + 1, 'trade_group' => $group, 'trade' => $trade, 'specialty' => $specialty, 'quantity' => $quantity,
                'experience' => $experience, 'daily_cost' => $cost, 'pay_type' => $payType,
                'skills' => array_slice($details['skills'], 0, 2), 'certifications' => array_slice($details['certifications'], 0, 1), 'own_equipment' => $j % 2 === 1,
            ]);
            $this->seedOffer($need, $line, $j, $found, $refused, NeedUrgency::fromLabel($lines[$j][8] ?? $urgencyLabel), $ex, $manual);
        }

        $need->load('lines', 'offers.line', 'offers.assignments', 'assignments');
        $coverage = $need->coverage();
        if ($coverage['need'] && $coverage['validated'] >= $coverage['need']) {
            Need::query()->whereKey($need->id)->update([
                'notified_on' => $createdOn->addDays(5)->min($today),
                'payment_reference' => $need->deposit_paid ? 'Mobile Money · MM-'.(40000 + $h % 59999) : null,
            ]);
        }
        $this->seedDocuments($need, $h);
        $this->seedHistory($need);
    }

    /**
     * @param  array<string, mixed>  $ex
     */
    private function seedOffer(Need $need, mixed $line, int $j, int $found, int $refused, NeedUrgency $urgency, array $ex, ?NeedManualStatus $manual): void
    {
        $today = Need::referenceDate();
        $h = crc32('off-2026-0'.$this->offerNumber++);
        $deadline = isset($ex['dl']) ? $today->addDays($ex['dl']) : $need->starts_on->subDays(2);
        $plural = $line->quantity > 1 ? 's' : '';

        $offer = $need->offers()->create([
            'need_line_id' => $line->id,
            'reference' => "{$need->reference}/".($j + 1),
            'title' => "{$line->trade}{$plural} · {$need->site->name}",
            'urgency' => $urgency,
            'visibility' => NeedRequest::VISIBILITIES[$h % 6],
            'published_on' => $need->created_on,
            'deadline' => $deadline,
            'starts_on' => $need->starts_on,
            'ends_on' => $need->ends_on,
            'applications_open' => $deadline->greaterThanOrEqualTo($today) && $found < $line->quantity && ! $manual,
            'manual_status' => $manual,
            'auto_renew' => $h % 3 === 0,
            'description' => "Recherche de {$line->quantity} ".mb_strtolower($line->trade)."{$plural} (".mb_strtolower((string) $line->specialty).") pour le chantier {$need->site->name} à {$need->site->commune}.",
            'benefits' => $h % 2 ? 'Indemnité de transport 1 000 FCFA/jour' : 'Repas de midi fourni',
        ]);

        $pool = $this->pool->forTrade($line->trade);
        $validated = ($ex['pv'] ?? false) || ($ex['va'] ?? false);

        for ($q = 0; $q < $found + $refused; $q++) {
            $person = $pool[($q + $h) % count($pool)];
            $date = $need->created_on->addDays(3 + $q % 5);
            $status = match (true) {
                $q >= $found => NeedAssignmentStatus::Refused,
                $validated => NeedAssignmentStatus::Validated,
                default => [NeedAssignmentStatus::Validated, NeedAssignmentStatus::Contacted, NeedAssignmentStatus::ToContact][($q + $h) % 3],
            };
            $offer->assignments()->create([
                'need_id' => $need->id,
                'worker_id' => $person['workerId'],
                'reference' => 'AFF-2026-'.$this->assignmentNumber++,
                'worker_name' => $person['name'],
                'trade' => $line->trade,
                'commune' => $person['commune'],
                'phone' => '07 '.(10 + ($h + $q) % 89).' '.(20 + ($h * 3 + $q) % 79).' '.(11 + ($h + $q * 7) % 88).' '.(30 + ($h + $q * 5) % 69),
                'daily_cost' => $person['cost'],
                'status' => $status,
                'source' => $q % 2 ? 'Matching' : 'Manuel',
                'selected_on' => $selectedOn = ($date->greaterThan($today) ? $today : $date),
                'contacted_on' => in_array($status, [NeedAssignmentStatus::Contacted, NeedAssignmentStatus::Validated], true) ? CarbonImmutable::parse($selectedOn)->addDay()->min($today) : null,
                'channel' => in_array($status, [NeedAssignmentStatus::Contacted, NeedAssignmentStatus::Validated], true) ? ['Appel téléphonique', 'WhatsApp', 'SMS'][($q + $h) % 3] : null,
                'reason' => $status === NeedAssignmentStatus::Refused ? 'Indisponible sur la période — déjà engagé sur un autre chantier' : null,
            ]);
        }
    }

    private function seedDocuments(Need $need, int $h): void
    {
        $planMissing = collect($need->anomalies)->contains(fn (string $a): bool => str_contains($a, 'Plan'));

        foreach (array_slice(self::DOCUMENTS, 0, 3 + $h % 4) as $k => [$type, $file]) {
            $missing = $planMissing && $type === 'Plan du chantier';
            $need->documents()->create([
                'type' => $type,
                'file_name' => $missing ? null : "{$file}-".strtolower($need->reference).($type === 'Photos du chantier' ? '.zip' : '.pdf'),
                'added_on' => $missing ? null : $need->created_on->addDays($k % 2),
                'author' => $k % 2 === 0 && $need->source === 'Application Promoteur' ? $need->site->promoter_name : 'N. Yao',
                'status' => $missing ? NeedDocumentStatus::Missing : NeedDocumentStatus::Validated,
            ]);
        }
    }

    /**
     * Journal : création et publication, offres expirées, soumission au promoteur, validation et décision manuelle.
     */
    private function seedHistory(Need $need): void
    {
        $today = Need::referenceDate();
        $promoter = $need->site->promoter_name;
        $log = fn (CarbonImmutable $day, string $time, string $author, NeedActivityCategory $category, string $action, ?string $old, ?string $new, ?string $comment) => $need->activities()->create([
            'occurred_at' => $day->format('Y-m-d')." {$time}", 'author' => $author, 'category' => $category, 'action' => $action, 'old_value' => $old, 'new_value' => $new, 'comment' => $comment,
            'origin' => $author === $promoter ? 'Application Promoteur' : ($author === 'Système' ? 'Système' : 'Back-office'),
        ]);
        $created = CarbonImmutable::parse($need->created_on);
        $by = match ($need->source) {
            'Application Promoteur' => $promoter,
            'Commercial terrain' => 'Adama Coulibaly (commercial)',
            default => 'N. Yao',
        };
        $coverage = $need->coverage();

        $log($created, '09:02', $by, NeedActivityCategory::Creation, 'a créé le besoin', null, 'Enregistré', $need->source);
        $log($created, '09:05', 'Système', NeedActivityCategory::Publication, 'a publié automatiquement les offres', null, 'Publié', $need->offers->pluck('reference')->implode(', '));

        foreach ($need->offers as $offer) {
            if ($offer->deadline->lessThan($today) && $offer->stats()['selected'] < $offer->line->quantity) {
                $log(CarbonImmutable::parse($offer->deadline), '23:59', 'Système', NeedActivityCategory::Dates, 'a signalé une offre expirée', NeedOffer::PUBLISHED, NeedOffer::EXPIRED, $offer->reference);
            }
        }
        if ($coverage['selected'] >= $coverage['need']) {
            $log($created->addDays(5)->min($today), '11:00', 'Système', NeedActivityCategory::Validation, 'a soumis les affectations validées au promoteur', Need::PARTIAL, 'En attente de validation du promoteur', "{$coverage['selected']} affectation(s)");
        }
        if ($need->promoter_validated) {
            $log(CarbonImmutable::parse($need->starts_on)->subDay(), '15:30', $promoter, NeedActivityCategory::Validation, 'a validé les affectations', 'En attente de validation du promoteur', Need::DONE, 'Chantier en cours');
        }
        if ($need->manual_status) {
            $verb = ['suspended' => 'suspendu', 'cancelled' => 'annulé', 'closed' => 'clôturé', 'archived' => 'archivé'][$need->manual_status->value];
            $log(CarbonImmutable::parse($need->updated_on), '10:00', 'N. Yao', NeedActivityCategory::Status, "a {$verb} le besoin", null, $need->manual_status->label(), $need->reason);
        }
    }
}
