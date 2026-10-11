<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;

/**
 * Calculs d’une journée de pointage : durées (brute, pauses, nette arrondie), retard, départ anticipé,
 * heures supplémentaires, statut de la journée, motifs d’incomplétude, points bloquants de clôture
 * et estimation financière (sans aucun déclenchement de paiement).
 *
 * Les règles viennent de config/admin.php › attendance (reprises de Paramètres).
 */
class AttendanceCalculator
{
    /**
     * Couleur des libellés du module (statuts, niveaux, localisation, transmission).
     *
     * @var array<string, string>
     */
    public const TONES = [
        'À venir' => 'n', 'Ouvrier attendu' => 'b', 'Arrivée en attente' => 'a', 'Présent' => 'g', 'Départ en attente' => 'a', 'Journée complète' => 'g', 'Journée incomplète' => 'o',
        'Absent' => 'r', 'Absence en attente' => 'a', 'Retard' => 'o', 'Départ anticipé' => 'o', 'Heures supplémentaires' => 'p', 'En anomalie' => 'r', 'Correction demandée' => 'a',
        'Corrigée' => 'p', 'Clôturée' => 'n', 'Annulée' => 'n', 'Non enregistrée' => 'n', 'Non enregistré' => 'n', 'Enregistrée' => 'a', 'Enregistré' => 'a', 'En attente de validation' => 'a',
        'Validée' => 'g', 'Validé' => 'g', 'Rejetée' => 'r', 'Rejeté' => 'r', 'Corrigé' => 'p', 'Nouvelle' => 'r', 'À vérifier' => 'o', 'En cours d’analyse' => 'a', 'Justification demandée' => 'a',
        'Confirmée' => 'r', 'Fausse alerte' => 'n', 'Soumise' => 'a', 'En attente' => 'a', 'Approuvée' => 'g', 'Appliquée' => 'g', 'Brouillon' => 'n', 'Reçue' => 'b', 'Vérifiée' => 'g',
        'Remplacement demandé' => 'o', 'Prête à clôturer' => 'g', 'Non clôturable' => 'o', 'Clôturée automatiquement' => 'n', 'Clôturée manuellement' => 'n', 'Rouverte avec autorisation' => 'a',
        'Justifié' => 'g', 'Justifiée' => 'g', 'Non justifié' => 'r', 'Non justifiée' => 'r', 'Justification attendue' => 'a', 'Autorisé' => 'g', 'Contesté' => 'r', 'Contestée' => 'r', 'Régularisé' => 'p',
        'Régularisée' => 'p', 'Détectées' => 'p', 'Autorisées' => 'g', 'Refusées' => 'r', 'Validées' => 'g', 'Transmises au calcul financier' => 'b', 'Dans la zone' => 'g', 'Près de la zone' => 'a',
        'Hors zone' => 'r', 'Localisation imprécise' => 'o', 'Localisation indisponible' => 'n', 'Information' => 'b', 'Faible' => 'n', 'Modérée' => 'a', 'Élevée' => 'o', 'Critique' => 'r',
        Attendance::TRANSMITTED => 'b', Attendance::NOT_TRANSMITTED => 'n',
    ];

    /**
     * Statuts d’une anomalie traitée (elle ne bloque plus la clôture).
     *
     * @var array<int, string>
     */
    public const ANOMALY_DONE = ['Fausse alerte', 'Corrigée', 'Clôturée'];

    /**
     * Statuts d’une correction en attente de décision.
     *
     * @var array<int, string>
     */
    public const CORRECTION_PENDING = ['Soumise', 'En attente', 'Brouillon'];

    public const PHOTO = 'Photo de l’ouvrier sur le chantier';

    public const SIGNATURE = 'Signature électronique';

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return config('admin.attendance');
    }

    public static function tone(string $label): string
    {
        return self::TONES[$label] ?? 'n';
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function badge(string $label): array
    {
        return ['label' => $label, 'tone' => self::tone($label)];
    }

    /**
     * Position du promoteur par rapport au chantier (rayon autorisé, tolérance, précision du GPS).
     */
    public static function locationStatus(?int $meters, int $accuracy): string
    {
        $cfg = self::rules();

        return match (true) {
            $meters === null => 'Localisation indisponible',
            $accuracy > $cfg['precision'] => 'Localisation imprécise',
            $meters <= $cfg['radius'] => 'Dans la zone',
            $meters <= $cfg['zone_tolerance'] => 'Près de la zone',
            default => 'Hors zone',
        };
    }

    /**
     * « 07:30 » → 450.
     */
    public static function minutes(?string $time): ?int
    {
        if (! $time) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    /**
     * 450 → « 07:30 » (modulo 24 h).
     */
    public static function time(int|float $minutes): string
    {
        $m = (((int) round($minutes) % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    /**
     * 510 → « 8 h 30 » ; « — » si inconnu.
     */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $sign = $minutes < 0 ? '−' : '';
        $m = abs($minutes);

        return $sign.intdiv($m, 60).' h '.sprintf('%02d', $m % 60);
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculate(Attendance $p): array
    {
        $cfg = self::rules();
        $today = Attendance::referenceDate();
        $a = $p->arrival;
        $d = $p->departure;
        $arrivalValid = in_array($a['status'], Attendance::VALIDATED['arrival'], true);
        $departureValid = in_array($d['status'], Attendance::VALIDATED['departure'], true);
        $arrivalPending = in_array($a['status'], Attendance::PENDING['arrival'], true);
        $departurePending = in_array($d['status'], Attendance::PENDING['departure'], true);
        $start = self::minutes($p->planned_start);
        $end = self::minutes($p->planned_end);

        $plan = (($end - $start + 1440) % 1440) - ($p->night ? 0 : $cfg['pause']);
        $late = $arrivalValid ? self::minutes($a['time']) - $start : 0;
        $lateFlag = $late > $cfg['late_tolerance'];
        $gross = null;
        $pause = 0;
        $net = null;
        $overtime = 0;
        $early = 0;
        if ($arrivalValid && $departureValid) {
            $gross = (self::minutes($d['time']) - self::minutes($a['time']) + 1440) % 1440;
            $pause = $cfg['pause_excluded'] ? (int) collect($p->pauses)->where('validated', true)->sum('minutes') : 0;
            $net = max(0, (int) round(($gross - $pause) / $cfg['rounding']) * $cfg['rounding']);
            $overtime = $net - $plan > $cfg['overtime_threshold'] ? $net - $plan : 0;
            $gap = ($end - self::minutes($d['time']) + 1440) % 1440;
            $early = $gap > $cfg['early_tolerance'] && $gap < 600 ? $gap : 0;
        }

        $anomalies = $p->anomalies;
        $open = $anomalies->reject(fn (AttendanceAnomaly $x): bool => in_array($x->status, self::ANOMALY_DONE, true))->values();
        $blocking = $open->filter(fn (AttendanceAnomaly $x): bool => in_array($x->level, ['Élevée', 'Critique'], true))->values();
        $pendingCorrections = $p->corrections->filter(fn (AttendanceCorrection $c): bool => in_array($c->status, self::CORRECTION_PENDING, true))->values();
        $closed = $p->isClosed();
        $absence = $p->absence;
        $absenceValid = $absence && ! in_array($absence['status'], ['Annulée', 'En attente de validation', 'Absence déclarée', 'Absence présumée'], true);
        $proofs = collect($a['proofs'] ?? []);
        $hasProof = fn (string $type): bool => $proofs->contains(fn (array $x): bool => $x['type'] === $type && $x['status'] !== 'Rejetée');
        $proofMissing = $a['time'] && (($cfg['photo'] && ! $hasProof(self::PHOTO)) || ($cfg['signature'] && ! $hasProof(self::SIGNATURE)));
        $locationMissing = $cfg['location'] && $a['time'] && ! $a['location'];
        $past = $p->date->lessThan($today);
        $future = $p->date->greaterThan($today);

        $blockers = array_values(array_filter([
            ! $arrivalValid && ! $absence ? 'Arrivée non validée' : null,
            $arrivalValid && ! $departureValid ? 'Départ non validé' : null,
            $proofMissing ? 'Preuve obligatoire manquante' : null,
            ...$blocking->map(fn (AttendanceAnomaly $x): string => 'Anomalie '.mb_strtolower($x->level).' : '.$x->type)->all(),
            $pendingCorrections->isNotEmpty() ? 'Correction en attente' : null,
        ]));
        $closable = ! $closed && ($absence ? (bool) $absenceValid : ! $blockers);

        $status = match (true) {
            ($p->closure['status'] ?? '') === 'Annulée' => 'Annulée',
            $closed => 'Clôturée',
            (bool) $absence => $absenceValid ? 'Absent' : 'Absence en attente',
            $pendingCorrections->isNotEmpty() => 'Correction demandée',
            $blocking->isNotEmpty() => 'En anomalie',
            $arrivalValid && $departureValid => $p->corrections->contains('status', 'Appliquée') ? 'Corrigée' : 'Journée complète',
            $departurePending => 'Départ en attente',
            $arrivalValid => $past ? 'Journée incomplète' : 'Présent',
            $arrivalPending => 'Arrivée en attente',
            $future => 'À venir',
            default => $past ? 'Journée incomplète' : 'Ouvrier attendu',
        };

        $indicators = array_values(array_filter([
            $lateFlag ? 'Retard' : null,
            $early || ($p->early_leave && ! $departureValid) ? 'Départ anticipé' : null,
            $overtime ? 'Heures supplémentaires' : null,
            $arrivalValid && ! $departureValid && $past ? 'Départ manquant' : null,
            $open->isNotEmpty() ? $open->count().' anomalie'.($open->count() > 1 ? 's' : '') : null,
        ]));
        $incomplete = array_values(array_filter([
            $arrivalPending ? 'Arrivée enregistrée non validée' : null,
            $arrivalValid && ! $departureValid && ! $departurePending && $past ? 'Arrivée validée sans départ' : null,
            $departurePending ? 'Départ enregistré non validé' : null,
            $proofMissing ? 'Preuve manquante' : null,
            $locationMissing ? 'Localisation manquante' : null,
            $net !== null && ($net < $cfg['min_duration'] || $net > $cfg['max_duration']) ? 'Durée incohérente' : null,
            $arrivalValid && $departureValid && ! $closed && $past && $pendingCorrections->isEmpty() ? 'Journée non clôturée' : null,
            ! $a['time'] && ! $absence && $past ? 'Aucun pointage ni absence' : null,
        ]));
        $closureStatus = $closed ? $p->closure['status'] : (($p->closure['status'] ?? '') === Attendance::REOPENED ? Attendance::REOPENED : ($closable ? 'Prête à clôturer' : 'Non clôturable'));

        $cost = (int) ($p->worker?->daily_rate ?? 0);
        $amount = $absence ? 0 : ($net === null ? null : (int) round($cost * min(1, $net / max(1, $plan))));
        $prime = $overtime && $p->overtime && preg_match('/Validées|Transmises|Autorisées/', $p->overtime['status'])
            ? (int) round($cost / ($plan / 60) * ($overtime / 60) * (1 + $cfg['overtime_rate'] / 100)) : 0;

        return [
            'arrivalValid' => $arrivalValid, 'departureValid' => $departureValid, 'arrivalPending' => $arrivalPending, 'departurePending' => $departurePending,
            'plan' => $plan, 'late' => $late, 'lateFlag' => $lateFlag, 'gross' => $gross, 'pause' => $pause, 'net' => $net, 'overtime' => $overtime, 'early' => $early,
            'openAnomalies' => $open, 'blocking' => $blocking, 'pendingCorrections' => $pendingCorrections, 'closed' => $closed, 'closable' => $closable,
            'blockers' => $blockers, 'status' => $status, 'indicators' => $indicators, 'incomplete' => $incomplete, 'closureStatus' => $closureStatus,
            'cost' => $cost, 'amount' => $amount, 'prime' => $prime, 'proofMissing' => $proofMissing, 'locationMissing' => (bool) $locationMissing, 'past' => $past,
        ];
    }
}
