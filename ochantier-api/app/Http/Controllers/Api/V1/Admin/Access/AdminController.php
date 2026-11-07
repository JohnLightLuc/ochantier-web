<?php

namespace App\Http\Controllers\Api\V1\Admin\Access;

use App\Enums\AdminStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Access\InviteAdminRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\Admin;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Support\Admin\AdminAccounts;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administrateurs du Back Office et consultation des rôles.
 */
class AdminController extends Controller
{
    public function __construct(private AdminAccounts $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(AdminStatus::class)],
            'role' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $rows = Admin::query()
            ->with('roles')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->whereHas('roles', fn (Builder $query) => $query->where('code', $role)))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])
                ->orWhereRaw('email::text ilike ?', [$search])))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (Admin $admin) => $this->row($admin))->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'roles' => AdminRole::query()->orderBy('label')->get()->map(fn (AdminRole $role) => ['code' => $role->code, 'label' => $role->label])->all(),
        ]);
    }

    public function roles(): JsonResponse
    {
        return ApiResponse::success(AdminRole::query()->with('permissions')->orderBy('label')->get()->map(fn (AdminRole $role) => [
            'code' => $role->code,
            'label' => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions->sortBy('code')->map(fn (AdminPermission $permission) => ['code' => $permission->code, 'label' => $permission->label])->values()->all(),
        ])->all());
    }

    public function store(InviteAdminRequest $request): JsonResponse
    {
        $admin = $this->accounts->invite($request->validated(), $request->user(), $request);

        return $this->fiche($request, $admin->id, 'Invitation envoyée à '.$admin->email.'.', 201);
    }

    public function show(Request $request, string $adminId): JsonResponse
    {
        return $this->fiche($request, $adminId);
    }

    public function resend(Request $request, string $adminId): JsonResponse
    {
        $this->accounts->resend($this->find($adminId), $request->user(), $request);

        return $this->fiche($request, $adminId, 'Nouveau lien envoyé.');
    }

    public function revoke(Request $request, string $adminId): JsonResponse
    {
        $this->accounts->revokeLink($this->find($adminId), $request->user(), $request);

        return $this->fiche($request, $adminId, 'Invitation révoquée.');
    }

    public function updateRoles(Request $request, string $adminId): JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::exists(AdminRole::class, 'code')]], ['roles.required' => 'Choisissez au moins un rôle.', 'roles.min' => 'Choisissez au moins un rôle.']);
        $this->accounts->updateRoles($this->find($adminId), $data['roles'], $request->user(), $request);

        return $this->fiche($request, $adminId, 'Rôles mis à jour : ses sessions sont fermées.');
    }

    public function suspend(PromoterReasonRequest $request, string $adminId): JsonResponse
    {
        $this->accounts->suspend($this->find($adminId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $adminId, 'Compte suspendu.');
    }

    public function reactivate(Request $request, string $adminId): JsonResponse
    {
        $this->accounts->reactivate($this->find($adminId), $request->user(), $request);

        return $this->fiche($request, $adminId, 'Compte réactivé.');
    }

    public function deactivate(PromoterReasonRequest $request, string $adminId): JsonResponse
    {
        $this->accounts->deactivate($this->find($adminId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $adminId, 'Compte désactivé.');
    }

    private function find(string $id): Admin
    {
        return Admin::query()->with('roles')->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'full_name' => $admin->fullName(),
            'email' => $admin->email,
            'department' => $admin->department,
            'status' => ['value' => $admin->status->value, 'label' => $admin->status->label()],
            'roles' => $admin->roles->map(fn (AdminRole $role) => ['code' => $role->code, 'label' => $role->label])->values()->all(),
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        $admin = $this->find($id);

        return ApiResponse::success([...$this->row($admin), 'phone' => $admin->phone, 'suspended_reason' => $admin->suspended_reason, 'invited_by' => $admin->invitedByAdmin?->fullName(), 'created_at' => $admin->created_at?->toIso8601String(), 'sessions' => $admin->authSessions()->whereNull('revoked_at')->where('expires_at', '>', now())->count(), ...$this->accounts->state($admin, $request->user())], $message, $status);
    }
}
