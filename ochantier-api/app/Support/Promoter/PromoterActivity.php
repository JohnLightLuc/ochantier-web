<?php

namespace App\Support\Promoter;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\PromoterProfile;
use App\Models\Proposal;
use App\Models\Replacement;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Activité des utilisateurs » : connexions et actions d’écriture des utilisateurs d’un compte promoteur, inscrites
 * dans audit_logs (module promoteur, sujet : le profil du compte). Réservée aux comptes abonnés.
 */
class PromoterActivity
{
    public const MODULE = 'promoteur';

    /**
     * Route (sans le préfixe v1.promoter.) => libellé de l’action. Les routes absentes ne sont pas journalisées.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'sites.store' => 'a créé un chantier',
        'sites.update' => 'a modifié le chantier',
        'sites.destroy' => 'a supprimé un chantier',
        'sites.photo.store' => 'a changé la photo du chantier',
        'sites.photo.destroy' => 'a retiré la photo du chantier',
        'sites.documents.store' => 'a ajouté un document au chantier',
        'sites.documents.destroy' => 'a retiré un document du chantier',
        'sites.offer.update' => 'a modifié les besoins du chantier',
        'sites.publish' => 'a publié le chantier',
        'sites.reservations.store' => 'a demandé des ouvriers pour le chantier',
        'proposals.accept' => 'a accepté une proposition',
        'proposals.refuse' => 'a refusé une proposition',
        'payments.store' => 'a effectué un paiement',
        'sites.attendance.events.store' => 'a pointé un ouvrier',
        'sites.attendance.absences.store' => 'a marqué un ouvrier absent',
        'sites.attendance.validate' => 'a validé une journée de pointage',
        'sites.steps.store' => 'a ajouté une étape',
        'sites.steps.start' => 'a démarré une étape',
        'sites.steps.complete' => 'a validé une étape',
        'sites.steps.destroy' => 'a retiré une étape',
        'assignments.replacement.store' => 'a demandé le remplacement d’un ouvrier',
        'replacements.accept' => 'a accepté un remplaçant',
        'replacements.refuse' => 'a refusé un remplaçant',
        'sites.ratings.store' => 'a publié un avis',
        'ratings.update' => 'a modifié un avis',
        'subscription.cancel' => 'a résilié l’abonnement',
        'team.invitations.store' => 'a invité un collaborateur',
        'team.members.update' => 'a changé le rôle d’un collaborateur',
        'team.members.destroy' => 'a retiré un collaborateur',
        'profile.update' => 'a modifié le profil du compte',
        'profile.photo.store' => 'a changé la photo du profil',
        'profile.photo.destroy' => 'a retiré la photo du profil',
        'profile.documents.store' => 'a ajouté un document au profil',
        'profile.documents.destroy' => 'a retiré un document du profil',
        'referrals.store' => 'a invité un proche sur O’CHANTIER',
    ];

    public function __construct(private PromoterAccess $access) {}

    /**
     * Action d’écriture réussie de l’espace promoteur (appelée par le middleware promoter.activity).
     */
    public function recordRequest(Request $request, Response $response): void
    {
        $action = str((string) $request->route()?->getName())->after('v1.promoter.')->toString();

        if (! isset(self::LABELS[$action])) {
            return;
        }

        $payload = json_decode((string) $response->getContent(), true);
        $message = $payload['message'] ?? null;
        $site = $this->siteOf($request, $payload['data'] ?? null);

        $this->record($request->user(), $action, [
            'site' => $site instanceof Site ? ['id' => $site->id, 'title' => $site->label] : null,
            'detail' => is_string($message) ? $message : null,
        ], $request);
    }

    /**
     * Connexion à l’application Promoteur d’un utilisateur rattaché à un compte.
     */
    public function recordLogin(User $user, Request $request): void
    {
        $this->record($user, 'login', [], $request);
    }

    /**
     * Dernières actions des utilisateurs du compte, les plus récentes d’abord.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(PromoterProfile $profile, int $limit = 50): array
    {
        $logs = AuditLog::query()
            ->where('module', self::MODULE)
            ->where('subject_type', $profile->getMorphClass())
            ->where('subject_id', $profile->getKey())
            ->latest('created_at')
            ->limit($limit)
            ->get();
        $users = User::query()->whereKey($logs->pluck('actor_id')->unique()->filter())->get()->keyBy('id');

        return $logs->map(function (AuditLog $log) use ($users): array {
            $user = $users->get($log->actor_id);
            $values = $log->after_values ?? [];
            $at = CarbonImmutable::parse($log->created_at)->setTimezone(config('app.timezone'));

            return [
                'id' => $log->id,
                'action' => $log->action,
                'who' => [
                    'id' => $user?->id,
                    'name' => $user ? trim($user->first_name.' '.$user->last_name) : 'Utilisateur supprimé',
                    'role' => $values['role'] ?? null,
                ],
                'what' => $log->action === 'login' ? 's’est connecté(e)' : (self::LABELS[$log->action] ?? $log->action),
                'site' => $values['site'] ?? null,
                'detail' => $values['detail'] ?? null,
                'at' => $at->toIso8601String(),
                'when' => $at->isToday()
                    ? 'Aujourd’hui · '.$at->format('H:i')
                    : ($at->isYesterday() ? 'Hier · '.$at->format('H:i') : $at->locale('fr')->translatedFormat('j M Y · H:i')),
            ];
        })->values()->all();
    }

    /**
     * Chantier concerné : paramètre de route, ou chantier de la proposition, de la réservation, de l’affectation ou du
     * remplacement, ou encore site_id de la réponse (paiement).
     */
    private function siteOf(Request $request, mixed $data): ?Site
    {
        $route = $request->route();
        $model = $route?->parameter('site') ?? $route?->parameter('proposal') ?? $route?->parameter('reservation')
            ?? $route?->parameter('assignment') ?? $route?->parameter('replacement');

        return match (true) {
            $model instanceof Site => $model,
            $model instanceof Proposal, $model instanceof Offer => $model->site,
            $model instanceof Assignment, $model instanceof Replacement => $model->mission?->site,
            is_array($data) && is_string($data['site_id'] ?? null) => Site::query()->find($data['site_id']),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(User $user, string $action, array $values, Request $request): void
    {
        $profile = $this->access->profile($user);

        if (! $profile) {
            return;
        }

        $role = (string) $this->access->role($user);

        AuditLog::query()->create([
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->getKey(),
            'action' => $action,
            'module' => self::MODULE,
            'subject_type' => $profile->getMorphClass(),
            'subject_id' => $profile->getKey(),
            'after_values' => [...array_filter($values), 'role' => PromoterAccess::ROLE_LABELS[$role] ?? $role],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
