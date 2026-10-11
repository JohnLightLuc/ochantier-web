<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\SiteActivityCategory;
use App\Enums\SiteDocumentType;
use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentStatus;
use App\Enums\SitePriority;
use App\Enums\SiteStatus;
use App\Models\Promoter;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Chantiers de la maquette : ceux du tableau de bord, ceux de SICOGI et quatre chantiers à venir ou clôturés.
 *
 * Les valeurs dérivées (dates, effectifs, budget, coordonnées) sont tirées de façon déterministe d’un hachage de l’identifiant.
 */
class SiteSeeder extends Seeder
{
    /**
     * Libellé, promoteur, localisation, type, statut, avancement (%), puis dates et effectif requis quand ils sont imposés.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: SiteStatus, 5: int, 6?: string, 7?: string, 8?: int}>
     */
    private const SITES = [
        ['Résidence Les Oliviers', 'SICOGI', 'Abidjan, Cocody', 'Résidentiel collectif', SiteStatus::InProgress, 75],
        ['Tour Horizon', 'Ivoire Properties', 'Plateau', 'Bureaux', SiteStatus::InProgress, 45],
        ['Cité Belle Rive', 'SAPH', 'Bingerville', 'Logement social', SiteStatus::Late, 20],
        ['Complexe scolaire SA', 'Fonds Éducation CI', 'Yamoussoukro', 'Équipement public', SiteStatus::InProgress, 90],
        ['Les Jardins d’Angré', 'SICOGI', 'Abidjan, Cocody Angré', 'Résidentiel', SiteStatus::InProgress, 40],
        ['Cité Administrative Plateau', 'SICOGI', 'Abidjan, Plateau', 'Bureaux', SiteStatus::Ready, 0],
        ['Logements sociaux Yopougon', 'SICOGI', 'Abidjan, Yopougon', 'Logement social', SiteStatus::Late, 62],
        ['Résidence Bingerville Lot B', 'SICOGI', 'Bingerville', 'Résidentiel', SiteStatus::PendingClosure, 100],
        ['Extension Cité Verte', 'SICOGI', 'Songon', 'Lotissement', SiteStatus::Draft, 0],
        ['Tour Ivoire Business', 'SICOGI', 'Abidjan, Plateau', 'Bureaux', SiteStatus::Suspended, 30],
        ['Villa Duplex Riviera 3', 'SICOGI', 'Abidjan, Riviera', 'Villa', SiteStatus::Recruiting, 15],
        ['Centre de santé d’Anyama', 'Fonds Éducation CI', 'Anyama', 'Infrastructure publique', SiteStatus::Planned, 0, '2026-11-02', '2027-08-27', 40],
        ['Entrepôt logistique Vridi', 'Ivoire Construction', 'Vridi', 'Entrepôt', SiteStatus::Draft, 0, '2026-10-19', '2027-04-30', 25],
        ['Rénovation Hôtel Tiama', 'MK Immobilier', 'Plateau', 'Rénovation', SiteStatus::Closed, 100, '2025-11-03', '2026-06-30', 18],
        ['Marché de Koumassi', 'SICOGI', 'Koumassi', 'Commerce', SiteStatus::Planned, 0, '2026-10-12', '2027-05-28', 35],
    ];

    /**
     * Types de la maquette des promoteurs → types de construction du module.
     */
    private const TYPES = ['Résidentiel collectif' => 'Résidence', 'Résidentiel' => 'Résidence', 'Bureaux' => 'Bureau', 'Logement social' => 'Immeuble', 'Lotissement' => 'Maison individuelle', 'Villa' => 'Maison individuelle', 'Équipement public' => 'Infrastructure publique'];

    private const REQUIRED = [30, 40, 50, 40, 36, 20, 44, 12, 8, 24, 10, 16];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $promoters = Promoter::query()->pluck('id', 'name');

        foreach (self::SITES as $i => $row) {
            $this->seedSite($i, $row, $promoters->get($row[1]));
        }
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function seedSite(int $i, array $row, ?int $promoterId): void
    {
        [$name, $promoter, $location, $type, $status, $progress] = $row;
        $slug = Str::slug($name);
        $h = crc32($slug);
        $commune = self::commune($location);
        [$lat, $lng] = Site::COORDINATES[$commune];
        [$start, $end] = $this->dates($row, $status, $h);
        $required = $row[8] ?? self::REQUIRED[$i % 12];
        $assigned = match (true) {
            $status === SiteStatus::Closed => $required,
            $status->isActive() || $status === SiteStatus::PendingClosure => (int) round($required * match ($status) {
                SiteStatus::Recruiting => 0.45,
                SiteStatus::Ready => 1,
                default => 0.6 + ($h % 40) / 100,
            }),
            default => 0,
        };
        $plannedDays = Site::workingDaysBetween($start, $end);
        $budget = $required * 12000 * $plannedDays + ($h % 9) * 1_000_000;
        $manager = ['Ing. Kouadio Brou', 'Tech. Mariam Sangaré', 'Ing. Paul Kacou', 'Tech. Awa Yéo'][$h % 4];

        $site = Site::create([
            'slug' => $slug,
            'reference' => sprintf('CH-2026-%04d', 101 + $i),
            'name' => $name,
            'promoter_id' => $promoterId,
            'promoter_name' => $promoter,
            'commune' => $commune,
            'city' => Site::cityOf($commune),
            'address' => ['Boulevard Latrille', 'Rue des Jardins', 'Avenue Chardy', 'Carrefour Siporex', 'Route de Bingerville', 'Rue du Commerce'][$h % 6].', '.$commune,
            'latitude' => round($lat + (($h % 40) - 20) / 2000, 4),
            'longitude' => round($lng + ((($h >> 4) % 40) - 20) / 2000, 4),
            'type' => self::TYPES[$type] ?? $type,
            'category' => ['Construction neuve', 'Extension', 'Second œuvre'][$h % 3],
            'manager' => $manager,
            'manager_phone' => $this->phone($h, 3),
            'starts_on' => $start,
            'ends_on' => $end,
            'planned_days' => $plannedDays,
            'progress' => $progress,
            'required_workers' => $required,
            'assigned_workers' => $assigned,
            'present_workers' => $status->isRunning() ? max(0, $assigned - 1 - $h % 3) : 0,
            'status' => $status,
            'priority' => $status === SiteStatus::Late ? SitePriority::High : [SitePriority::Medium, SitePriority::Normal, SitePriority::High][$h % 3],
            'description' => 'Projet '.mb_strtolower($name)." porté par {$promoter} : ".['gros œuvre et second œuvre', 'construction de logements', 'travaux tous corps d’état'][$h % 3].'.',
            ...Site::DEFAULTS,
            'budget' => $budget,
            'engaged_amount' => (int) round($budget * min(1, $progress / 100 + 0.15) / 1000) * 1000,
            'paid_amount' => $status === SiteStatus::Closed ? $budget : (int) round($budget * $progress / 100 * 0.85 / 1000) * 1000,
            'photos' => 3 + $h % 3,
            'submitted_on' => $start->subDays(30),
        ]);

        $this->seedDocuments($site, $h);
        $this->seedHistory($site, $i, $h);
    }

    /**
     * Commune de la carte à partir d’une localisation libre (« Abidjan, Cocody Angré » → Cocody).
     */
    public static function commune(string $location): string
    {
        foreach (array_keys(Site::COORDINATES) as $commune) {
            if (str_contains($location, $commune)) {
                return $commune;
            }
        }

        return preg_match('/Riviera|Angré/', $location) ? 'Cocody' : 'Plateau';
    }

    /**
     * Dates imposées, ou tirées du hachage en restant cohérentes avec le statut.
     *
     * @param  array<int, mixed>  $row
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dates(array $row, SiteStatus $status, int $h): array
    {
        if (isset($row[6], $row[7])) {
            return [CarbonImmutable::parse($row[6]), CarbonImmutable::parse($row[7])];
        }

        if (in_array($status, [SiteStatus::Draft, SiteStatus::Ready], true)) {
            $start = CarbonImmutable::parse('2026-10-05')->addDays(($h % 6) * 7);

            return [$start, $start->addDays(180 + $h % 240)];
        }

        if ($status === SiteStatus::PendingClosure) {
            $end = CarbonImmutable::parse('2026-09-15')->subDays($h % 20);

            return [$end->subDays(300 + $h % 120), $end];
        }

        $start = CarbonImmutable::parse('2026-01-05')->addDays(($h % 9) * 30 - 60);

        return [$start, $start->addDays(180 + $h % 240)];
    }

    private function seedDocuments(Site $site, int $h): void
    {
        $closed = $site->status === SiteStatus::Closed;

        foreach (SiteDocumentType::cases() as $j => $type) {
            $missing = $type === SiteDocumentType::AcceptanceReport && ! $closed;
            $status = match (true) {
                $missing => DocumentStatus::Missing,
                $site->status === SiteStatus::Draft && $j < 3 => DocumentStatus::ToReview,
                $type === SiteDocumentType::SafetyPlan && $h % 4 === 0 => DocumentStatus::Expired,
                default => DocumentStatus::Verified,
            };

            $site->documents()->create([
                'type' => $type,
                'reference' => $missing ? null : mb_strtoupper(mb_substr(explode(' ', $type->label())[0], 0, 3)).'-'.(2026000 + $h % 999),
                'file_name' => $missing ? null : "doc-{$site->slug}-".($j + 1).'.pdf',
                'added_at' => $missing ? null : $site->submitted_on,
                'expires_at' => $type === SiteDocumentType::Insurance ? $site->ends_on->addDays(30) : null,
                'status' => $status,
            ]);
        }
    }

    /**
     * Incidents de démonstration et journal : création, démarrage, incidents, suspension ou clôture.
     */
    private function seedHistory(Site $site, int $i, int $h): void
    {
        $incidents = [];
        if ($site->status === SiteStatus::Late) {
            $incidents[] = ['INC-'.(300 + $i), '2026-09-18', 'Retard d’approvisionnement', SiteIncidentSeverity::High, 'Livraison de ciment reportée de 5 jours', $site->manager, SiteIncidentStatus::InProgress];
        }
        if ($h % 3 === 0 && $site->status->isRunning()) {
            $incidents[] = ['INC-'.(340 + $i), '2026-09-21', 'Accident mineur', SiteIncidentSeverity::Medium, 'Coupure à la main, soins sur place', $site->manager, SiteIncidentStatus::Open];
        }
        if ($site->status === SiteStatus::Suspended) {
            $incidents[] = ['INC-'.(360 + $i), '2026-09-02', 'Arrêt administratif', SiteIncidentSeverity::Critical, 'Suspension en attente de régularisation du permis', 'N. Yao', SiteIncidentStatus::Open];
        }

        $log = fn (string $at, string $author, SiteActivityCategory $category, string $action, ?string $old, ?string $new, ?string $comment, string $origin = 'Back-office') => $site->activities()->create([
            'occurred_at' => $at, 'author' => $author, 'category' => $category, 'action' => $action, 'old_value' => $old, 'new_value' => $new, 'comment' => $comment, 'origin' => $origin,
        ]);

        $log($site->submitted_on->format('Y-m-d').' 10:12', $site->promoter_name, SiteActivityCategory::Creation, 'a créé le chantier', null, SiteStatus::Planned->label(), null, 'Application Promoteur');
        if ($site->executedDays() > 0 || $site->status === SiteStatus::Closed) {
            $log($site->starts_on->format('Y-m-d').' 07:30', 'Système', SiteActivityCategory::Status, 'a démarré le chantier', SiteStatus::Ready->label(), SiteStatus::InProgress->label(), null, 'Système');
        }

        foreach ($incidents as [$reference, $date, $type, $severity, $description, $by, $status]) {
            $site->incidents()->create(['reference' => $reference, 'occurred_on' => $date, 'type' => $type, 'severity' => $severity, 'description' => $description, 'reported_by' => $by, 'status' => $status]);
            $log("{$date} 09:20", $by, SiteActivityCategory::Incident, 'a signalé un incident', null, $status->label(), $type);
        }

        if ($site->status === SiteStatus::Suspended) {
            $log('2026-09-02 10:05', 'N. Yao', SiteActivityCategory::Status, 'a suspendu le chantier', SiteStatus::InProgress->label(), SiteStatus::Suspended->label(), 'Permis de construire à régulariser');
        }
        if ($site->status === SiteStatus::Closed) {
            $log($site->ends_on->addDays(5)->format('Y-m-d').' 16:40', 'N. Yao', SiteActivityCategory::Status, 'a clôturé le chantier', SiteStatus::PendingClosure->label(), SiteStatus::Closed->label(), 'PV de réception vérifié');
        }
    }

    private function phone(int $h, int $n): string
    {
        $groups = array_map(fn (int $j): string => (string) ((($h >> ($n + $j * 3)) % 90) + 10), [0, 1, 2, 3]);

        return '+225 0'.['7', '5', '1'][($h >> $n) % 3].' '.implode(' ', $groups);
    }
}
