<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\InvitationStatus;
use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentDocumentType;
use App\Enums\SalesAgentStatus;
use App\Http\Requests\WorkerRequest;
use App\Models\SalesAgent;
use App\Models\SalesRanking;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Les 13 commerciaux de la maquette, avec leurs pièces, périodes, objectifs, activités terrain et journal.
 *
 * Les valeurs dérivées (téléphones, zones, sessions) sont tirées de façon déterministe d’un hachage de l’identifiant.
 */
class SalesAgentSeeder extends Seeder
{
    private const TODAY = '2026-09-23';

    /**
     * Statuts des 6 pièces selon le statut du compte : v vérifié, a à vérifier, m manquant, e expiré.
     */
    private const DOCUMENT_CODES = ['active' => 'vvvvvv', 'suspended' => 'vvvvev', 'deactivated' => 'vvvvvm', 'archived' => 'vvvvmm', 'invited' => 'vvaamm', 'pending_activation' => 'vvavam', 'draft' => 'aammmm'];

    /**
     * Dernier mois classé pour les comptes sortis de l’activité.
     */
    private const RANKING_UNTIL = ['suspended' => '2026-08', 'deactivated' => '2026-05', 'archived' => '2025-12'];

    private const FIELD_TYPES = ['Prospection', 'Identification d’un ouvrier', 'Création d’un ouvrier', 'Collecte de documents', 'Mise à jour d’un profil', 'Visite terrain', 'Sensibilisation', 'Suivi d’un dossier', 'Correction d’un dossier rejeté'];

    private const FIELD_COMMENTS = ['Prospection' => 'Présentation d’O’CHANTIER sur un chantier de la zone', 'Sensibilisation' => 'Réunion d’information avec 12 ouvriers', 'Visite terrain' => 'Contrôle des chantiers partenaires'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $agents = [
            ['adama-coulibaly', 'COM-012', 'Adama', 'Coulibaly', 'Homme', 'Abobo', 'Agence Abobo', 'Superviseur commercial', 'CDI', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::Available, '2023-03-06', 286],
            ['mariam-kone', 'COM-015', 'Mariam', 'Koné', 'Femme', 'Cocody', 'Agence Cocody', 'Commercial salarié', 'CDI', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::FieldMission, '2023-05-15', 241],
            ['jean-marc-yao', 'COM-021', 'Jean-Marc', 'Yao', 'Homme', 'Yopougon', 'Agence Yopougon', 'Agent terrain', 'CDD', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::FieldMission, '2023-09-04', 198],
            ['aicha-traore', 'COM-024', 'Aïcha', 'Traoré', 'Femme', 'Bingerville', 'Antenne Bingerville', 'Commercial indépendant', 'Convention', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::Available, '2024-01-08', 167],
            ['serge-koffi', 'COM-027', 'Serge', 'Koffi', 'Homme', 'Bouaké', 'Antenne Bouaké', 'Agent terrain', 'Convention', SalesAgentStatus::Suspended, InvitationStatus::Activated, SalesAgentAvailability::Unavailable, '2024-02-12', 143],
            ['fatou-diarra', 'COM-030', 'Fatou', 'Diarra', 'Femme', 'Grand-Bassam', 'Antenne Grand-Bassam', 'Responsable de zone', 'CDI', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::Partial, '2023-11-20', 213],
            ['ousmane-diabate', 'COM-033', 'Ousmane', 'Diabaté', 'Homme', 'Adjamé', 'Agence Abobo', 'Agent terrain', 'CDD', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::Available, '2024-06-03', 132],
            ['christelle-ndri', 'COM-036', 'Christelle', 'N’Dri', 'Femme', 'Marcory', 'Agence Marcory', 'Commercial salarié', 'CDI', SalesAgentStatus::Active, InvitationStatus::Activated, SalesAgentAvailability::Leave, '2024-07-15', 118],
            ['bakary-sangare', 'COM-041', 'Bakary', 'Sangaré', 'Homme', 'Port-Bouët', 'Agence Marcory', 'Agent terrain', 'Convention', SalesAgentStatus::Invited, InvitationStatus::Sent, SalesAgentAvailability::Available, '2026-09-19', 0],
            ['nadege-kouadio', 'COM-042', 'Nadège', 'Kouadio', 'Femme', 'Plateau', 'Agence Cocody', 'Commercial indépendant', 'Convention', SalesAgentStatus::PendingActivation, InvitationStatus::Viewed, SalesAgentAvailability::Available, '2026-09-15', 0],
            ['herve-tanoh', 'COM-018', 'Hervé', 'Tanoh', 'Homme', 'Treichville', 'Agence Marcory', 'Agent terrain', 'CDD', SalesAgentStatus::Deactivated, InvitationStatus::Activated, SalesAgentAvailability::Unavailable, '2023-07-10', 96],
            ['rokia-doumbia', 'COM-043', 'Rokia', 'Doumbia', 'Femme', 'Koumassi', 'Agence Marcory', 'Agent terrain', 'Convention', SalesAgentStatus::Draft, InvitationStatus::NotSent, SalesAgentAvailability::Available, '2026-09-22', 0],
            ['arsene-gnagne', 'COM-009', 'Arsène', 'Gnagne', 'Homme', 'Songon', 'Agence Yopougon', 'Agent terrain', 'CDD', SalesAgentStatus::Archived, InvitationStatus::Activated, SalesAgentAvailability::Unavailable, '2022-10-03', 74],
        ];

        foreach ($agents as $row) {
            $this->seedAgent(...$row);
        }

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            SalesRanking::create(['month' => $month, 'published' => true]);
        }
    }

    private function seedAgent(string $slug, string $code, string $first, string $last, string $gender, string $zone, string $agency, string $type, string $contract, SalesAgentStatus $status, InvitationStatus $invitation, SalesAgentAvailability $availability, string $startedAt, int $recruited): void
    {
        $h = crc32($slug);
        $name = "{$first} {$last}";
        $activated = $status->wasActivated();
        $independent = $type === 'Commercial indépendant';
        $others = array_values(array_filter(WorkerRequest::COMMUNES, fn (string $c): bool => $c !== $zone));
        $monthly = max(8, (int) round($recruited / 12 * (0.55 + ($h % 15) / 100)));
        $supervisor = match (true) {
            $independent, in_array($type, ['Superviseur commercial', 'Responsable de zone'], true) => 'Direction commerciale',
            in_array($agency, ['Agence Abobo', 'Agence Yopougon'], true) => 'Adama Coulibaly',
            default => 'Fatou Diarra',
        };
        $lastActivity = match (true) {
            $status === SalesAgentStatus::Active => $this->day(self::TODAY, -($h % 5)),
            $activated => $this->day('2026-09-10', -($h % 20)),
            default => null,
        };

        $access = SalesAgent::defaultAccess($type, $zone);
        $access = [...$access,
            'two_factor' => $h % 3 !== 0,
            'sent_at' => $status === SalesAgentStatus::Draft ? null : $startedAt,
            'viewed_at' => $invitation === InvitationStatus::Viewed || $activated ? $this->day($startedAt, 1) : null,
            'activated_at' => $activated ? $this->day($startedAt, 1) : null,
            'expires_at' => in_array($invitation, [InvitationStatus::Sent, InvitationStatus::Viewed], true) ? $this->day(self::TODAY, 2) : null,
            'last_login' => $activated ? ($status === SalesAgentStatus::Active ? sprintf('2026-09-23 08:%d%d', 1 + $h % 5, $h % 9) : '2026-09-10 18:02') : null,
            'password_changed_at' => $activated ? sprintf('2026-%02d-1%d', 4 + $h % 5, $h % 9) : null,
            'failed_attempts' => $h % 4 === 0 ? 2 : 0,
            'sessions' => $status === SalesAgentStatus::Active ? array_values(array_filter([
                ['id' => 's1', 'device' => 'Application Commercial · Android', 'location' => "{$zone}, Abidjan", 'ip' => '102.67.'.($h % 200).'.'.($h % 90), 'last' => 'Aujourd’hui · 08:4'.($h % 9)],
                $h % 2 ? ['id' => 's2', 'device' => 'Navigateur · Chrome', 'location' => 'Plateau, Abidjan', 'ip' => '41.207.'.($h % 150).'.'.($h % 70), 'last' => 'Hier · 17:32'] : null,
            ])) : [],
        ];

        $agent = SalesAgent::create([
            'slug' => $slug,
            'code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'birth_date' => sprintf('%d-%02d-%02d', 1984 + $h % 14, $h % 12 + 1, $h % 27 + 1),
            'nationality' => 'Ivoirienne',
            'phone' => $this->phone($h, 2),
            'phone_secondary' => $h % 2 ? $this->phone($h, 6) : null,
            'email' => str_replace('-', '.', Str::ascii($slug)).'@ochantier.ci',
            'address' => 'Quartier '.['Riviera 3', 'Sicogi', 'Niangon', 'Remblais', 'Zone 4', 'Dokui'][$h % 6].', '.$zone,
            'commune' => $zone,
            'city' => in_array($zone, ['Bouaké', 'Grand-Bassam', 'Bingerville'], true) ? $zone : 'Abidjan',
            'emergency_contact' => ['Awa', 'Koffi', 'Mariam', 'Paul'][$h % 4].' '.$last,
            'emergency_phone' => $this->phone($h, 9),
            'type' => $type,
            'job_title' => match ($type) {
                'Superviseur commercial' => 'Superviseur des équipes terrain',
                'Responsable de zone' => "Responsable de la zone {$zone}",
                default => 'Chargé de recrutement terrain',
            },
            'agency' => $independent ? null : $agency,
            'zone' => $zone,
            'secondary_zones' => array_slice($others, $h % 6, 2),
            'communes' => [$zone, ...array_slice($others, $h % 6, 3)],
            'priority_trades' => array_slice(WorkerRequest::TRADES, $h % 5, 3),
            'specialties' => array_slice(WorkerRequest::TRADES, $h % 4, 2),
            'supervisor' => $supervisor,
            'started_at' => $startedAt,
            'contract' => $contract,
            'weekly_target' => max(2, (int) round($monthly / 4.3)),
            'monthly_target' => $monthly,
            'id_type' => 'Carte nationale d’identité',
            'id_number' => 'CI'.substr((string) (2000000 + $h % 7999999), 0, 9),
            'id_issued_at' => '2022-05-10',
            'id_expires_at' => '2032-05-09',
            'status' => $status,
            'invitation_status' => $invitation,
            'availability' => $availability,
            'suspension_reason' => $status === SalesAgentStatus::Suspended ? 'Profils ouvriers dupliqués détectés (4 doublons)' : null,
            'last_activity_on' => $lastActivity,
            'recruited_total' => $recruited,
            'ranking_until' => self::RANKING_UNTIL[$status->value] ?? null,
            'pay' => SalesAgent::defaultPay($type, $contract, $startedAt, ['Orange Money', 'Wave', 'MTN MoMo'][$h % 3]),
            'access' => $access,
        ]);

        $this->seedDocuments($agent, $status, $contract);
        $this->seedPeriods($agent);
        if ($activated) {
            $this->seedObjectives($agent);
            $this->seedFieldActivities($agent, $h, $lastActivity ?? $startedAt);
        }
        $this->seedActivities($agent, $status, $startedAt, $lastActivity);
    }

    private function seedDocuments(SalesAgent $agent, SalesAgentStatus $status, string $contract): void
    {
        $codes = self::DOCUMENT_CODES[$status->value];
        $references = [$agent->id_number, $agent->id_number, "photo-{$agent->slug}.jpg", 'Facture SODECI · août 2026', "{$contract} n° {$agent->code}", "CV {$agent->name}"];
        $expirations = [$agent->id_expires_at->format('Y-m-d'), $agent->id_expires_at->format('Y-m-d'), null, null, $contract === 'CDD' ? '2027-06-30' : null, null];

        foreach (SalesAgentDocumentType::cases() as $i => $type) {
            $documentStatus = ['v' => DocumentStatus::Verified, 'a' => DocumentStatus::ToReview, 'm' => DocumentStatus::Missing, 'e' => DocumentStatus::Expired][$codes[$i]];
            $missing = $documentStatus === DocumentStatus::Missing;

            $agent->documents()->create([
                'type' => $type,
                'reference' => $missing ? null : $references[$i],
                'file_name' => $missing ? null : Str::slug($type->label()).'.pdf',
                'added_at' => $missing ? null : $agent->started_at,
                'expires_at' => $documentStatus === DocumentStatus::Expired ? '2026-08-31' : $expirations[$i],
                'status' => $documentStatus,
            ]);
        }
    }

    private function seedPeriods(SalesAgent $agent): void
    {
        $periods = [
            'christelle-ndri' => ['2026-09-21', '2026-10-04', SalesAgentAvailability::Leave, 'Congé annuel', 'Mariam Koné', 'Dossiers transférés temporairement à Mariam Koné'],
            'fatou-diarra' => ['2026-09-24', '2026-09-26', SalesAgentAvailability::Partial, 'Formation des nouveaux agents', null, null],
            'serge-koffi' => ['2026-09-10', '2026-10-10', SalesAgentAvailability::Unavailable, 'Suspension administrative', 'Aïcha Traoré', null],
        ];

        if ($period = $periods[$agent->slug] ?? null) {
            [$from, $to, $type, $reason, $replacement, $comment] = $period;
            $agent->periods()->create(['starts_on' => $from, 'ends_on' => $to, 'type' => $type, 'reason' => $reason, 'zone' => $agent->zone, 'replacement' => $replacement, 'comment' => $comment]);
        }
    }

    private function seedObjectives(SalesAgent $agent): void
    {
        $target = $agent->monthly_target;

        foreach ([
            ['Semaine 39 · 21 – 27 sept. 2026', 'Hebdomadaire', $agent->weekly_target, '2026-09-21', '2026-09-27', false],
            ['Septembre 2026', 'Mensuel', $target, '2026-09-01', '2026-09-30', false],
            ['T3 2026 · juillet – septembre', 'Trimestriel', $target * 3, '2026-07-01', '2026-09-30', false],
            ['Août 2026', 'Mensuel', $target, '2026-08-01', '2026-08-31', true],
            ['Octobre 2026', 'Mensuel', $target + 2, '2026-10-01', '2026-10-31', false],
        ] as [$label, $type, $value, $from, $to, $closed]) {
            $agent->objectives()->create(['label' => $label, 'type' => $type, 'target' => $value, 'starts_on' => $from, 'ends_on' => $to, 'closed' => $closed]);
        }
    }

    private function seedFieldActivities(SalesAgent $agent, int $h, string $lastActivity): void
    {
        $workers = Worker::query()->where('recruiter', $agent->name)->get();
        if ($workers->isEmpty()) {
            $workers = Worker::query()->orderBy('id')->skip($h % 5)->take(3)->get();
        }

        for ($i = 0; $i < 12; $i++) {
            $type = self::FIELD_TYPES[($h + $i) % count(self::FIELD_TYPES)];
            $worker = $workers->isEmpty() ? null : $workers[$i % $workers->count()];
            $withWorker = $worker && ! isset(self::FIELD_COMMENTS[$type]);

            $agent->fieldActivities()->create([
                'worker_id' => $withWorker ? $worker->id : null,
                'occurred_at' => $this->day($lastActivity, -intdiv($i, 2)).sprintf(' %02d:%02d', 8 + ($i * 3) % 9, ($h + $i * 7) % 60),
                'type' => $type,
                'location' => $agent->communes[$i % count($agent->communes)],
                'comment' => self::FIELD_COMMENTS[$type] ?? 'Dossier '.($worker->code ?? ''),
                'has_proof' => $i % 3 !== 2,
                'status' => match ($i) {
                    4 => 'Anomalie signalée',
                    1 => 'À vérifier',
                    default => 'Validée',
                },
            ]);
        }
    }

    private function seedActivities(SalesAgent $agent, SalesAgentStatus $status, string $startedAt, ?string $lastActivity): void
    {
        $name = $agent->name;
        $events = [[SalesAgentActivityCategory::Creation, 'N. Yao', 'a créé le commercial', null, 'Brouillon', 'Création depuis le Back-office', 'Back-office', "{$startedAt} 09:05", '41.207.12.8']];

        if ($status !== SalesAgentStatus::Draft) {
            $events[] = [SalesAgentActivityCategory::Invitation, 'Système', 'a envoyé l’invitation par e-mail', 'Non envoyée', 'Envoyée', $agent->email, 'Système', "{$startedAt} 09:06", null];
        }
        if ($status->wasActivated()) {
            $events[] = [SalesAgentActivityCategory::Activation, $name, 'a activé son compte', 'Activation en attente', 'Actif', 'Mot de passe personnel créé', 'Application Commercial', $this->day($startedAt, 1).' 19:42', '102.67.3.21'];
            $events[] = [SalesAgentActivityCategory::Login, $name, 's’est connecté', null, null, 'Application Commercial · Android', 'Application Commercial', ($lastActivity ?? $startedAt).' 08:40', '102.67.3.21'];
            $events[] = [SalesAgentActivityCategory::Objective, 'Adama Coulibaly', 'a attribué un objectif mensuel', null, "{$agent->monthly_target} profils validés", 'Septembre 2026', 'Back-office', '2026-09-01 08:00', null];
            $events[] = [SalesAgentActivityCategory::Score, 'Système', 'a recalculé le score', null, null, 'Recalcul après validation', 'Système', '2026-09-22 23:00', null];
        }
        $events[] = match ($status) {
            SalesAgentStatus::Suspended => [SalesAgentActivityCategory::Suspension, 'N. Yao', 'a suspendu le compte', 'Actif', 'Suspendu', $agent->suspension_reason, 'Back-office', '2026-09-10 15:20', '41.207.12.8'],
            SalesAgentStatus::Deactivated => [SalesAgentActivityCategory::Status, 'N. Yao', 'a désactivé le compte', 'Actif', 'Désactivé', 'Fin de contrat', 'Back-office', '2026-05-31 17:00', '41.207.12.8'],
            SalesAgentStatus::Archived => [SalesAgentActivityCategory::Status, 'N. Yao', 'a archivé le commercial', 'Désactivé', 'Archivé', 'Inactif depuis plus de 12 mois', 'Back-office', '2025-12-15 10:00', null],
            default => null,
        };

        foreach (array_filter($events) as [$category, $author, $action, $old, $new, $comment, $origin, $at, $ip]) {
            $agent->activities()->create(['occurred_at' => $at, 'author' => $author, 'category' => $category, 'action' => $action, 'old_value' => $old, 'new_value' => $new, 'comment' => $comment, 'origin' => $origin, 'ip' => $ip]);
        }
    }

    /**
     * Numéro ivoirien déterministe, au format « +225 07 12 34 56 78 ».
     */
    private function phone(int $h, int $n): string
    {
        $digits = array_map(fn (int $j): string => (string) ((($h >> ($n + $j * 3)) % 90) + 10), range(0, 3));

        return '+225 0'.['7', '5', '1'][($h >> $n) % 3].' '.implode(' ', $digits);
    }

    private function day(string $iso, int $days): string
    {
        return Carbon::parse($iso)->addDays($days)->format('Y-m-d');
    }
}
