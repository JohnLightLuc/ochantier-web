<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Utilisateurs du Back Office et journal d’audit, lus dans l’API (api/v1/admin/admins et audit).
 */
class AdminAccountController extends Controller
{
    private const PER_PAGE = 20;

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'role' => 'role', 'page' => 'page'];

    private const AUDIT_QUERY = ['q' => 'search', 'module' => 'module', 'du' => 'from', 'au' => 'to', 'page' => 'page'];

    private const STATUS_TONES = ['invite' => 'a', 'actif' => 'g', 'suspendu' => 'o', 'desactive' => 'n'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, self::QUERY);
        $response = $this->api->get('admins', [...$this->query($filters, self::QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));

        return Inertia::render('Settings/Admins', [
            'admins' => $this->page($rows, $meta),
            'filters' => $filters,
            'roles' => $this->options($meta['roles']),
            'can' => $this->can($request),
            'header' => $this->header('Utilisateurs & rôles', '', [['Paramètres', '/parametres'], ['Utilisateurs & rôles']]),
        ]);
    }

    public function roles(): Response
    {
        return Inertia::render('Settings/Roles', [
            'roles' => $this->api->get('admins/roles')->json('data'),
            'header' => $this->header('Rôles et droits', '', [['Paramètres', '/parametres'], ['Utilisateurs & rôles', '/parametres/utilisateurs'], ['Rôles']]),
        ]);
    }

    public function show(Request $request, string $admin): Response
    {
        $a = $this->api->get("admins/{$admin}")->json('data');
        $roles = $this->api->get('admins', ['per_page' => 1])->json('meta.roles');

        return Inertia::render('Settings/Admin', [
            'admin' => [
                ...$this->row($a),
                'phone' => $a['phone'],
                'suspendedReason' => $a['suspended_reason'],
                'invitedBy' => $a['invited_by'],
                'createdAt' => $this->moment($a['created_at']),
                'sessions' => $a['sessions'],
                'invite' => ['pending' => $a['invite']['pending'], 'expiresAt' => $this->moment($a['invite']['expires_at'])],
                'actions' => $a['can'],
            ],
            'roles' => $this->options($roles),
            'can' => $this->can($request),
            'header' => $this->header($a['full_name'], '', [['Paramètres', '/parametres'], ['Utilisateurs & rôles', '/parametres/utilisateurs'], [$a['full_name']]]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->act(fn () => $this->api->post('admins', [...$request->only('first_name', 'last_name', 'email', 'phone', 'department'), 'roles' => $this->roleList($request)]));
    }

    public function resend(string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("admins/{$admin}/invitation"));
    }

    public function revoke(string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->delete("admins/{$admin}/invitation"));
    }

    public function updateRoles(Request $request, string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->put("admins/{$admin}/roles", ['roles' => $this->roleList($request)]));
    }

    public function suspend(Request $request, string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("admins/{$admin}/suspension", $request->only('reason')));
    }

    public function reactivate(string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("admins/{$admin}/reactivation"));
    }

    public function deactivate(Request $request, string $admin): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("admins/{$admin}/deactivation", $request->only('reason')));
    }

    public function audit(Request $request): Response
    {
        $filters = $this->filters($request, self::AUDIT_QUERY);
        $response = $this->api->get('audit', [...$this->query($filters, self::AUDIT_QUERY), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $user = $request->user();

        return Inertia::render('Settings/Audit', [
            'logs' => $this->page(array_map(fn (array $l): array => [...$l, 'date' => $this->moment($l['date'])], $response->json('data')), $meta),
            'filters' => $filters,
            'modules' => array_map(fn (string $module): array => ['value' => $module, 'label' => ucfirst($module)], $meta['modules']),
            'can' => ['export' => $user instanceof AdminUser && $user->hasPermission('audit.export')],
            'header' => $this->header('Journal d’audit', '', [['Paramètres', '/parametres'], ['Journal d’audit']]),
        ]);
    }

    public function auditExport(Request $request): HttpResponse
    {
        $response = $this->api->get('audit/export', $this->query($this->filters($request, self::AUDIT_QUERY), self::AUDIT_QUERY));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="journal-audit.csv"']);
    }

    /**
     * Le formulaire propose un rôle principal ; la fiche permet d’en cocher plusieurs.
     *
     * @return list<string>
     */
    private function roleList(Request $request): array
    {
        return array_values(array_filter(array_merge((array) $request->input('roles', []), [$request->input('role')])));
    }

    /**
     * Un refus métier s’affiche en message ; une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        try {
            $response = $call();
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['admin', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function row(array $a): array
    {
        return [
            'id' => $a['id'],
            'name' => $a['full_name'],
            'email' => $a['email'],
            'department' => $a['department'],
            'status' => [...$a['status'], 'tone' => self::STATUS_TONES[$a['status']['value']] ?? 'n'],
            'roles' => $a['roles'],
            'lastLoginAt' => $this->moment($a['last_login_at']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function page(array $rows, array $meta): array
    {
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0];
    }

    /**
     * @param  list<array{code: string, label: string}>  $roles
     * @return list<array{value: string, label: string}>
     */
    private function options(array $roles): array
    {
        return array_map(fn (array $role): array => ['value' => $role['code'], 'label' => $role['label']], $roles);
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function filters(Request $request, array $keys): array
    {
        return array_filter($request->only(array_keys($keys)), fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, string>  $keys
     * @return array<string, string>
     */
    private function query(array $filters, array $keys): array
    {
        $query = [];

        foreach ($filters as $key => $value) {
            $query[$keys[$key]] = $value;
        }

        return $query;
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['create' => $allowed('admins.create'), 'update' => $allowed('admins.update'), 'delete' => $allowed('admins.delete')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }
}
