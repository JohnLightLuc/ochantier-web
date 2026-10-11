<?php

namespace Database\Seeders;

use App\Enums\WorkerStatus;
use App\Models\Attendance;
use App\Models\Site;
use App\Models\Worker;
use App\Support\AttendanceCalculator as Calc;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Pointages de la maquette : pour chaque ouvrier validé, une journée attendue sur son chantier actif pendant les
 * 12 derniers jours ouvrés et le lendemain. Les journées passées sont pour la plupart complètes et clôturées ;
 * certaines portent un retard, un départ anticipé, des heures supplémentaires, une absence, une anomalie
 * (hors zone, écart d’horloge, durée courte, preuve en double) ou une demande de correction.
 * Le service de nuit (19:00 – 04:00) traverse minuit et reste rattaché à sa date de début.
 */
class AttendanceSeeder extends Seeder
{
    /**
     * Chantiers où les ouvriers sont pointés.
     *
     * @var array<int, string>
     */
    private const SITES = ['residence-les-oliviers', 'tour-horizon', 'cite-belle-rive', 'complexe-scolaire-sa', 'les-jardins-dangre', 'logements-sociaux-yopougon'];

    private const NIGHT_SITE = 'logements-sociaux-yopougon';

    private int $number = 400;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $today = Attendance::referenceDate();
        $sites = Site::query()->whereIn('slug', self::SITES)->get()->keyBy('slug');
        $active = collect(self::SITES)->filter(fn (string $slug): bool => $sites->has($slug))->values();
        $days = [];
        for ($d = $today; count($days) < 12; $d = $d->subDay()) {
            if (! $d->isSunday()) {
                $days[] = $d;
            }
        }
        $days = array_reverse([...$days]);
        $days[] = $today->addDay();

        $workers = Worker::query()->where('status', WorkerStatus::Validated)->orderBy('id')->get();
        foreach ($workers as $wi => $worker) {
            $assignment = collect($worker->demo('assignments'))->first(fn (array $x): bool => $active->contains(fn (string $slug): bool => $sites[$slug]->name === $x['site']) && preg_match('/En cours|Confirmée|Acceptée/', $x['status']));
            $slug = $assignment ? $active->first(fn (string $s): bool => $sites[$s]->name === $assignment['site']) : $active[$wi % $active->count()];
            $site = $sites[$slug];
            $night = $slug === self::NIGHT_SITE && $wi % 2 === 1;
            preg_match('/(\d\d:\d\d)\s*–\s*(\d\d:\d\d)/', (string) $site->working_hours, $hours);
            $start = $night ? '19:00' : ($hours[1] ?? '07:30');
            $end = $night ? '04:00' : ($hours[2] ?? '17:00');
            $reference = 'AFF-2026-'.sprintf('%04d', 310 + $wi);

            foreach ($days as $date) {
                $this->seedDay($worker, $site, $date, $today, $start, $end, $night, $reference);
            }
        }

        $this->seedDuplicateProof($today->subDays(2));
    }

    private function seedDay(Worker $worker, Site $site, CarbonImmutable $date, CarbonImmutable $today, string $start, string $end, bool $night, string $assignment): void
    {
        $this->number++;
        $h = crc32($worker->slug.$date->format('Y-m-d'));
        $scenario = $date->greaterThan($today) ? 'future' : ($date->equalTo($today) ? 'today' : 'past');
        $k = $scenario === 'today' ? $h % 8 : $h % 40;
        $by = $site->promoter_name.' · '.($site->manager ?: 'Responsable chantier');
        $day = $date->format('Y-m-d');
        $location = fn (int $meters, int $accuracy): array => ['meters' => $meters, 'accuracy' => $accuracy, 'status' => Calc::locationStatus($meters, $accuracy), 'at' => $day, 'source' => null];
        $file = mb_strtolower($worker->last_name);
        $proofs = fn (string $kind, string $time, int $seed): array => [
            ['id' => "{$kind}1", 'type' => Calc::PHOTO, 'file' => ($kind === 'a' ? 'arrivee-' : 'depart-')."{$file}-{$day}.jpg", 'at' => "{$day} {$time}", 'by' => $by, 'status' => 'Reçue', 'hash' => 'SHA-'.strtoupper(dechex($seed % 99999)), 'version' => 1, 'comment' => null],
            ['id' => "{$kind}2", 'type' => Calc::SIGNATURE, 'file' => 'signature-'.($kind === 'a' ? 'arrivee-' : 'depart-')."{$day}.png", 'at' => "{$day} {$time}", 'by' => $by, 'status' => 'Reçue', 'hash' => 'SIG-'.strtoupper(dechex($seed % 77777)), 'version' => 1, 'comment' => null],
        ];
        $startMin = Calc::minutes($start);
        $endMin = Calc::minutes($end);
        $data = [
            'arrival' => Attendance::event('Non enregistrée'), 'departure' => Attendance::event('Non enregistré'), 'pauses' => [], 'absence' => null,
            'lateness' => null, 'early_leave' => null, 'overtime' => null, 'closure' => ['status' => ''], 'transmission' => Attendance::NOT_TRANSMITTED,
        ];
        $anomalies = [];
        $corrections = [];

        $arrive = function (int $offset, ?string $status = null) use (&$data, $startMin, $by, $day, $location, $proofs, $h): void {
            $t = Calc::time($startMin + $offset);
            $pending = $status && $status !== 'Validée';
            $data['arrival'] = Attendance::event($status ?? 'Validée', [
                'time' => $t, 'by' => $by, 'at' => "{$day} {$t}", 'validated_by' => $pending ? null : $by, 'validated_at' => $pending ? null : "{$day} ".Calc::time($startMin + $offset + 3),
                'location' => $location(20 + $h % 110, 8 + $h % 30), 'proofs' => $proofs('a', $t, $h), 'comment' => 'Ouvrier présent à l’entrée du chantier, EPI portés.',
            ]);
        };
        $leave = function (int $offset, ?string $status = null) use (&$data, $endMin, $by, $day, $location, $proofs, $h, $night): void {
            $t = Calc::time($endMin + $offset);
            $pending = $status && $status !== 'Validé';
            $data['departure'] = Attendance::event($status ?? 'Validé', [
                'time' => $t, 'by' => $by, 'at' => "{$day} {$t}", 'validated_by' => $pending ? null : $by, 'validated_at' => $pending ? null : "{$day} ".Calc::time($endMin + $offset + 4),
                'location' => $location(15 + $h % 90, 10 + $h % 25), 'proofs' => $proofs('d', $t, $h * 7), 'comment' => 'Fin de journée, poste rangé.',
            ]);
            if (! $night) {
                $data['pauses'] = [['type' => 'Pause déjeuner', 'start' => '12:00', 'end' => '13:00', 'minutes' => 60, 'validated' => true, 'by' => $by]];
            }
        };
        $close = function () use (&$data, $h, $day): void {
            $data['closure'] = ['status' => $h % 3 ? 'Clôturée automatiquement' : 'Clôturée manuellement', 'by' => $h % 3 ? 'Système' : 'N. Yao', 'at' => "{$day} 23:59"];
            $data['transmission'] = Attendance::TRANSMITTED;
        };

        if ($scenario === 'today') {
            match (true) {
                $night => $arrive(2 + $h % 6),
                $k === 2 => $arrive(4 + $h % 20, 'En attente de validation'),
                $k >= 3 && $k <= 5 => $arrive($k === 5 ? 27 : -4 + $h % 9),
                $k === 6 => (function () use ($arrive, $leave, &$data): void {
                    $arrive(-3);
                    $leave(-95, 'En attente de validation');
                    $data['departure']['comment'] = 'Rendez-vous médical';
                })(),
                $k === 7 => $data['absence'] = ['motif' => 'Problème de transport', 'comment' => 'Grève des gbakas sur l’axe Abobo', 'status' => 'En attente de validation', 'by' => $by, 'at' => "{$day} 08:05", 'proof' => null],
                default => null,
            };
        } elseif ($scenario === 'past') {
            if ($k <= 27) {
                $arrive($k % 5 === 0 ? 18 + $h % 20 : -6 + $h % 14);
                $leave($k % 11 === 0 ? 80 : ($k % 13 === 0 ? -55 : $h % 20));
                if ($k % 13 === 0) {
                    $data['departure']['comment'] = 'Autorisé par le chef de chantier';
                }
                $close();
            } elseif ($k <= 30) {
                $data['absence'] = ['motif' => ['Maladie', 'Urgence familiale', 'Absence non signalée'][$k - 28], 'comment' => null, 'status' => $k === 30 ? 'Non justifiée' : 'Justifiée', 'by' => $by, 'at' => "{$day} 09:10", 'proof' => $k === 30 ? null : "certificat-{$day}.pdf"];
                $data['closure'] = ['status' => 'Clôturée automatiquement', 'by' => 'Système', 'at' => "{$day} 23:59"];
            } elseif ($k <= 33) {
                $arrive($h % 10);
            } elseif ($k === 34) {
                $arrive(3);
                $leave(8, 'En attente de validation');
            } elseif ($k === 35) {
                $arrive(1);
                $leave(10);
                $data['arrival']['location'] = $location(820, 12);
                $anomalies[] = ['type' => 'Pointage hors zone', 'level' => 'Élevée', 'status' => 'À vérifier', 'note' => 'Arrivée relevée à 820 m du chantier.'];
            } elseif ($k === 36) {
                $arrive(0);
                $leave(5);
                $anomalies[] = ['type' => 'Heure du terminal différente de l’heure serveur', 'level' => 'Modérée', 'status' => 'Nouvelle', 'note' => 'Écart de 12 min entre le terminal et le serveur.'];
            } elseif ($k === 37) {
                $arrive(2);
                $leave(6);
                $corrections[] = ['field' => 'Heure de départ', 'old_value' => $data['departure']['time'], 'new_value' => Calc::time(Calc::minutes($data['departure']['time']) + 45), 'motif' => 'Oubli de pointage à l’heure réelle', 'comment' => 'Travaux de coulage prolongés', 'proof' => 'attestation-chef-chantier.pdf', 'status' => 'Soumise', 'requested_by' => $by, 'requested_at' => "{$day} 19:20"];
            } elseif ($k === 38) {
                $arrive(0);
                $t = Calc::time($startMin + 150);
                $data['departure'] = Attendance::event('Validé', ['time' => $t, 'by' => $by, 'at' => "{$day} {$t}", 'validated_by' => $by, 'validated_at' => "{$day} {$t}", 'location' => $location(30, 10)]);
                $anomalies[] = ['type' => 'Durée anormalement courte', 'level' => 'Modérée', 'status' => 'Justification demandée', 'note' => 'Présence de 2 h 30 pour une journée prévue de 8 h 30.'];
            } else {
                $arrive(1);
                $leave(4);
            }
        }

        if ($data['arrival']['time'] && Calc::minutes($data['arrival']['time']) - $startMin > 10) {
            $data['lateness'] = ['status' => $scenario === 'today' ? 'Justification attendue' : ($h % 2 ? 'Justifié' : 'Non justifié'), 'motif' => $h % 2 ? 'Embouteillage à Adjamé' : null];
        }
        if (in_array($data['departure']['status'], ['Validé', 'En attente de validation'], true)) {
            $departure = Calc::minutes($data['departure']['time']);
            $gap = ($endMin - $departure + 1440) % 1440;
            if ($gap > 15 && $gap < 600) {
                $data['early_leave'] = ['status' => $data['departure']['comment'] && $data['departure']['comment'] !== 'Fin de journée, poste rangé.' ? 'Autorisé' : 'Justification attendue', 'motif' => $data['departure']['comment']];
            }
            $over = ($departure - $endMin + 1440) % 1440;
            if ($over > 30 && $over < 600) {
                $data['overtime'] = ['status' => $data['closure']['status'] ? 'Transmises au calcul financier' : 'En attente de validation', 'motif' => 'Coulage de dalle', 'authorized_by' => $by];
            }
        }

        $attendance = Attendance::create([
            ...$data,
            'reference' => 'PT-2026-'.sprintf('%04d', $this->number),
            'date' => $date,
            'worker_id' => $worker->id,
            'site_id' => $site->id,
            'assignment_reference' => $assignment,
            'planned_start' => $start,
            'planned_end' => $end,
            'night' => $night,
            'notes' => [],
            'last_update' => "{$day} {$end}",
        ]);
        foreach ($anomalies as $anomaly) {
            $attendance->anomalies()->create([...$anomaly, 'detected_on' => $date]);
        }
        foreach ($corrections as $correction) {
            $attendance->corrections()->create($correction);
        }
        $this->seedHistory($attendance);
    }

    /**
     * Deux pointages du même jour partagent la même photo : anomalie critique sur le second.
     */
    private function seedDuplicateProof(CarbonImmutable $date): void
    {
        $pair = Attendance::query()->whereDate('date', $date)->orderBy('id')->get()->filter(fn (Attendance $p): bool => (bool) ($p->arrival['proofs'] ?? []))->take(2)->values();
        if ($pair->count() < 2) {
            return;
        }
        [$first, $second] = $pair->all();
        $arrival = $second->arrival;
        $arrival['proofs'][0]['hash'] = $first->arrival['proofs'][0]['hash'];
        $second->update(['arrival' => $arrival]);
        $second->anomalies()->create(['type' => 'Même preuve utilisée plusieurs fois', 'level' => 'Critique', 'status' => 'Nouvelle', 'detected_on' => $date, 'note' => "Photo identique à celle de {$first->reference}."]);
    }

    /**
     * Journal : enregistrements et validations du promoteur, puis clôture.
     */
    private function seedHistory(Attendance $p): void
    {
        $day = $p->date->format('Y-m-d');
        $log = fn (string $time, ?string $author, string $category, string $action, ?string $new, ?string $comment, string $origin) => $p->activities()->create([
            'occurred_at' => "{$day} {$time}", 'author' => $author ?? 'Système', 'category' => $category, 'action' => $action, 'new_value' => $new, 'comment' => $comment, 'origin' => $origin,
        ]);
        $a = $p->arrival;
        $d = $p->departure;
        if ($a['time']) {
            $log($a['time'], $a['by'], 'pointage', 'a enregistré l’arrivée', $a['time'], null, 'Application promoteur');
        }
        if ($a['validated_by']) {
            $log(substr((string) $a['validated_at'], 11) ?: $a['time'], $a['validated_by'], 'validation', 'a validé l’arrivée', $a['time'], null, 'Application promoteur');
        }
        if ($d['time']) {
            $log($d['time'], $d['by'], 'pointage', 'a enregistré le départ', $d['time'], $d['comment'], 'Application promoteur');
        }
        if ($d['validated_by']) {
            $log(substr((string) $d['validated_at'], 11) ?: $d['time'], $d['validated_by'], 'validation', 'a validé le départ', $d['time'], null, 'Application promoteur');
        }
        if ($p->closure['status'] ?? '') {
            $log('23:59', $p->closure['by'], 'statut', 'a clôturé la journée', $p->closure['status'], null, $p->closure['by'] === 'Système' ? 'Système' : 'Back-office');
        }
    }
}
