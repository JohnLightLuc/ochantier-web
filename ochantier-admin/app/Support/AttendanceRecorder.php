<?php

namespace App\Support;

use App\Enums\SiteStatus;
use App\Models\Attendance;
use App\Support\AttendanceCalculator as Calc;

/**
 * Simulation de l’Application Promoteur : chaque événement se fait en deux temps (enregistrer, puis valider),
 * l’heure provient du serveur et la localisation est celle du promoteur. Le Back-office ne pointe pas
 * à la place du promoteur : ces actions sont tracées comme venant de l’application.
 */
class AttendanceRecorder
{
    /**
     * Actions : clé d’URL → libellé.
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'arrivee' => 'Pointer l’arrivée', 'validation-arrivee' => 'Valider l’arrivée', 'depart' => 'Pointer le départ',
        'validation-depart' => 'Valider le départ', 'absence' => 'Déclarer absent', 'validation-absence' => 'Valider l’absence',
    ];

    /**
     * Étape suivante annoncée après chaque action.
     *
     * @var array<string, string>
     */
    public const NEXT = [
        'arrivee' => 'valider l’arrivée', 'validation-arrivee' => 'pointer le départ en fin de journée', 'depart' => 'valider le départ',
        'validation-depart' => 'clôture de la journée', 'absence' => 'valider l’absence', 'validation-absence' => 'aucune',
    ];

    public const ABSENCE_MOTIFS = ['Absence non signalée', 'Maladie', 'Urgence familiale', 'Accident', 'Congé autorisé', 'Problème de transport', 'Chantier inaccessible', 'Chantier suspendu', 'Remplacement prévu', 'Autre motif'];

    /**
     * Heure serveur simulée, proche de l’heure prévue (déterministe pour un pointage donné).
     */
    public static function serverTime(Attendance $p, string $kind): string
    {
        $h = crc32($p->reference.$kind.$p->reminders);

        return $kind === 'arrival'
            ? Calc::time(Calc::minutes($p->planned_start) - 6 + $h % 22)
            : Calc::time(Calc::minutes($p->planned_end) - 10 + $h % 40);
    }

    /**
     * Raison pour laquelle l’action est impossible (chaîne vide si elle est permise).
     */
    public static function check(Attendance $p, string $action): string
    {
        $c = Calc::calculate($p);

        return match (true) {
            in_array($p->site->status, [SiteStatus::Suspended, SiteStatus::Closed, SiteStatus::Cancelled], true) => 'Chantier '.mb_strtolower($p->site->status->label()).' : pointage impossible.',
            ! $p->date->equalTo(Attendance::referenceDate()) => 'Pointage hors de la journée en cours.',
            $p->absence && ! in_array($action, ['absence', 'validation-absence'], true) => 'Ouvrier déjà déclaré absent.',
            $action === 'arrivee' && ! in_array($p->arrival['status'], ['Non enregistrée', 'Rejetée'], true) => 'Une arrivée est déjà active pour cette affectation aujourd’hui.',
            $action === 'validation-arrivee' && ! $c['arrivalPending'] => 'Aucune arrivée en attente de validation.',
            $action === 'depart' && ! $c['arrivalValid'] => 'Départ impossible : l’arrivée n’est pas validée.',
            $action === 'depart' && ! in_array($p->departure['status'], ['Non enregistré', 'Rejeté'], true) => 'Un départ existe déjà pour cette arrivée.',
            $action === 'validation-depart' && ! $c['departurePending'] => 'Aucun départ en attente de validation.',
            $action === 'absence' && $p->absence => 'Absence déjà déclarée.',
            $action === 'absence' && $p->arrival['time'] => 'Arrivée déjà enregistrée.',
            $action === 'validation-absence' && ($p->absence['status'] ?? null) !== 'En attente de validation' => 'Aucune absence en attente de validation.',
            default => '',
        };
    }

    /**
     * Exécute l’action et renvoie l’heure concernée (vide pour une absence).
     *
     * @param  array{outside?: bool, photo?: bool, signature?: bool, comment?: string|null, motif?: string, proof?: string|null}  $o
     */
    public static function record(Attendance $p, string $action, array $o = []): string
    {
        $by = $p->promoterLabel();
        $today = Attendance::referenceDate()->format('Y-m-d');
        $now = now()->format('H:i');
        $cfg = Calc::rules();
        $h = crc32($p->reference.$action);
        $log = fn (string $category, string $act, ?string $new, ?string $comment = null) => $p->logActivity($category, $act, null, $new, $comment, $by, 'Application promoteur');

        if (in_array($action, ['arrivee', 'depart'], true)) {
            $arrival = $action === 'arrivee';
            $kind = $arrival ? 'a' : 'd';
            $t = self::serverTime($p, $arrival ? 'arrival' : 'departure');
            $meters = ($o['outside'] ?? false) ? 640 : 18 + $h % 120;
            $accuracy = 10 + $h % 20;
            $proofs = array_values(array_filter([
                ($o['photo'] ?? true) ? ['id' => "{$kind}1", 'type' => Calc::PHOTO, 'file' => ($arrival ? 'arrivee-' : 'depart-').mb_strtolower($p->worker->last_name)."-{$today}.jpg", 'at' => "{$today} {$t}", 'by' => $by, 'status' => 'Reçue', 'hash' => 'SHA-'.strtoupper(dechex($h % 99999)), 'version' => 1, 'comment' => null] : null,
                ($o['signature'] ?? true) ? ['id' => "{$kind}2", 'type' => Calc::SIGNATURE, 'file' => 'signature-'.($arrival ? 'arrivee-' : 'depart-')."{$today}.png", 'at' => "{$today} {$t}", 'by' => $by, 'status' => 'Reçue', 'hash' => 'SIG-'.strtoupper(dechex($h % 77777)), 'version' => 1, 'comment' => null] : null,
            ]));
            $event = Attendance::event('En attente de validation', [
                'time' => $t, 'by' => $by, 'at' => "{$today} {$t}", 'proofs' => $proofs, 'comment' => $o['comment'] ?? null,
                'location' => ['meters' => $meters, 'accuracy' => $accuracy, 'status' => Calc::locationStatus($meters, $accuracy), 'at' => $today, 'source' => "Position GPS du promoteur ({$by})"],
            ]);
            if ($arrival) {
                $p->update(['arrival' => $event]);
                if ($event['location']['status'] === 'Hors zone') {
                    $p->anomalies()->create(['type' => 'Pointage hors zone', 'level' => 'Élevée', 'status' => 'Nouvelle', 'detected_on' => $today, 'note' => "Arrivée relevée à {$meters} m (rayon {$cfg['radius']} m)."]);
                }
                $log('pointage', 'a enregistré l’arrivée', $t, $o['comment'] ?? null);
            } else {
                $p->update(['departure' => $event, 'pauses' => ! $p->night && ! $p->pauses ? [['type' => 'Pause déjeuner', 'start' => '12:00', 'end' => '13:00', 'minutes' => $cfg['pause'], 'validated' => true, 'by' => $by]] : $p->pauses]);
                $log('pointage', 'a enregistré le départ', $t, $o['comment'] ?? null);
            }

            return $t;
        }

        if ($action === 'validation-arrivee') {
            $p->update(['arrival' => [...$p->arrival, 'status' => 'Validée', 'validated_by' => $by, 'validated_at' => "{$today} {$now}"]]);
            if (Calc::minutes($p->arrival['time']) - Calc::minutes($p->planned_start) > $cfg['late_tolerance']) {
                $p->update(['lateness' => ['status' => 'Justification attendue', 'motif' => null]]);
            }
            $log('validation', 'a validé l’arrivée', $p->arrival['time']);

            return $p->arrival['time'];
        }

        if ($action === 'validation-depart') {
            $p->update(['departure' => [...$p->departure, 'status' => 'Validé', 'validated_by' => $by, 'validated_at' => "{$today} {$now}"]]);
            $c = Calc::calculate($p->load('anomalies', 'corrections'));
            if ($c['early']) {
                $p->update(['early_leave' => ['status' => $p->departure['comment'] ? 'Justification attendue' : 'Départ anticipé', 'motif' => $p->departure['comment']]]);
            }
            if ($c['overtime']) {
                $p->update(['overtime' => ['status' => 'Détectées', 'motif' => null, 'authorized_by' => null]]);
            }
            $log('validation', 'a validé le départ', $p->departure['time'], 'Durée nette '.Calc::duration($c['net']));

            return $p->departure['time'];
        }

        if ($action === 'absence') {
            $motif = $o['motif'] ?? 'Absence non signalée';
            $p->update(['absence' => ['motif' => $motif, 'comment' => $o['comment'] ?? null, 'status' => 'En attente de validation', 'by' => $by, 'at' => "{$today} {$now}", 'proof' => $o['proof'] ?? null]]);
            $log('statut', 'a déclaré l’ouvrier absent', $motif, $o['comment'] ?? null);

            return '';
        }

        $p->update(['absence' => [...$p->absence, 'status' => 'Validée']]);
        $log('validation', 'a validé l’absence', $p->absence['motif']);

        return '';
    }
}
