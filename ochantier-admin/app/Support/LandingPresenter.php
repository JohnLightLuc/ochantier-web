<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Demandes du site public (api/v1/admin/landing) mises en forme pour la page Landing Page : candidatures
 * « Devenir ouvrier », messages de contact et inscrits à la lettre d’information.
 */
class LandingPresenter
{
    private const APPLICATION_TONES = ['nouvelle' => 'b', 'en_verification' => 'a', 'convertie' => 'g', 'refusee' => 'n'];

    private const MESSAGE_TONES = ['nouveau' => 'b', 'en_cours' => 'a', 'traite' => 'g'];

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public static function applicationRow(array $a): array
    {
        return [
            'id' => $a['id'],
            'reference' => $a['reference'],
            'name' => $a['full_name'],
            'phone' => $a['phone'],
            'trade' => $a['trade']['label'],
            'tradeMatched' => $a['trade']['matched'] !== null,
            'commune' => $a['commune']['label'],
            'communeMatched' => $a['commune']['matched'] !== null,
            'experience' => $a['experience_years'].' an'.($a['experience_years'] > 1 ? 's' : '').' · '.$a['level'],
            'availability' => $a['availability'],
            'targetSite' => $a['target_site'],
            'status' => ['value' => $a['status']['value'], 'label' => $a['status']['label'], 'tone' => self::APPLICATION_TONES[$a['status']['value']] ?? 'n'],
            'duplicates' => (int) $a['duplicates_count'],
            'worker' => $a['worker'],
            'receivedAt' => self::moment($a['created_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public static function application(array $a): array
    {
        return [
            ...self::applicationRow($a),
            'identity' => [
                ['Prénom', $a['first_name']],
                ['Nom', $a['last_name']],
                ['Téléphone', $a['phone']],
                ['E-mail', $a['email']],
                ['Date de naissance', $a['birth_date'] ? Carbon::parse($a['birth_date'])->translatedFormat('d M Y') : null],
                ['Genre', $a['gender']],
            ],
            'profile' => [
                ['Métier', $a['trade']['label'].($a['trade']['matched'] ? '' : ' (non reconnu dans le référentiel)')],
                ['Expérience', $a['experience_years'].' an(s) · '.$a['level']],
                ['Tarif journalier souhaité', $a['daily_rate'] ? number_format((int) $a['daily_rate'], 0, ',', ' ').' FCFA' : null],
                ['Compétences', implode(', ', $a['skills']) ?: null],
                ['Certifications', implode(', ', $a['certifications']) ?: null],
                ['Présentation', $a['bio']],
            ],
            'work' => [
                ['Commune', $a['commune']['label'].($a['commune']['matched'] ? '' : ' (non reconnue dans le référentiel)')],
                ['Quartier', $a['neighborhood']],
                ['Rayon de déplacement', $a['mobility']],
                ['Disponibilité', $a['availability']],
                ['Jours disponibles', implode(', ', $a['available_days']) ?: null],
                ['Paiement souhaité', trim($a['payout']['method'].' '.($a['payout']['number'] ?? ''))],
                ['Chantier visé', $a['target_site'] ? $a['target_site'].($a['target_site_commune'] ? ' · '.$a['target_site_commune'] : '') : null],
            ],
            'files' => $a['files'],
            'duplicateApplications' => array_map(fn (array $d) => ['id' => $d['id'], 'reference' => $d['reference'], 'status' => $d['status']['label'], 'date' => self::moment($d['created_at'])], $a['duplicates']['applications']),
            'duplicateWorkers' => array_map(fn (array $w) => ['id' => $w['id'], 'reference' => $w['reference'], 'name' => $w['full_name'], 'status' => $w['status']['label']], $a['duplicates']['workers']),
            'refusalReason' => $a['refusal_reason'],
            'handledBy' => $a['handled_by'],
            'can' => ['review' => (bool) $a['actions']['review'], 'refuse' => (bool) $a['actions']['refuse'], 'convert' => (bool) $a['actions']['convert']],
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function messageRow(array $m): array
    {
        return [
            'id' => $m['id'],
            'reference' => $m['reference'],
            'profile' => $m['profile'],
            'name' => $m['name'],
            'company' => $m['company'],
            'subject' => $m['subject'],
            'status' => ['value' => $m['status']['value'], 'label' => $m['status']['label'], 'tone' => self::MESSAGE_TONES[$m['status']['value']] ?? 'n'],
            'late' => (bool) $m['late'],
            'handledBy' => $m['handled_by'],
            'receivedAt' => self::moment($m['created_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function message(array $m): array
    {
        return [
            ...self::messageRow($m),
            'phone' => $m['phone'],
            'email' => $m['email'],
            'message' => $m['message'],
            'note' => $m['handling_note'],
            'handledAt' => self::moment($m['handled_at']),
            'dueAt' => self::moment($m['due_at']),
            'can' => ['start' => (bool) $m['actions']['start'], 'close' => (bool) $m['actions']['close']],
        ];
    }

    public static function moment(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->translatedFormat('d M Y · H:i') : null;
    }
}
