<?php

namespace App\Http\Middleware;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use App\Support\DashboardPresenter;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    private const SHELL_KEY = 'dashboard.shell';

    private const SHELL_SECONDS = 60;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $this->initials($user->name),
                    'role' => $user instanceof AdminUser ? $user->role : 'Super administrateur',
                ] : null,
            ],
            'navigation' => fn () => $user ? $this->navigation($request) : [],
            'notifications' => fn () => $user ? $this->notifications($request) : [],
        ];
    }

    /**
     * Modules de la sidebar avec leurs compteurs.
     *
     * @return array<int, array{key: string, label: string, icon: string, path: string, title: string, subtitle: string, badge: int|null}>
     */
    private function navigation(Request $request): array
    {
        $badges = $this->shell($request)['badges'] ?? [];

        return array_map(
            fn (array $module): array => [...$module, 'badge' => $badges[$module['key']] ?? null],
            config('admin.modules'),
        );
    }

    /**
     * Files d’attente de l’administrateur, affichées dans la cloche.
     *
     * @return list<array<string, mixed>>
     */
    private function notifications(Request $request): array
    {
        return app(DashboardPresenter::class)->notifications($this->shell($request)['priorities'] ?? []);
    }

    /**
     * Compteurs de l’en-tête, gardés une minute en session : ils ne doivent ni ralentir ni bloquer une page.
     *
     * @return array<string, mixed>
     */
    private function shell(Request $request): array
    {
        if (! $request->user() instanceof AdminUser || ! $request->hasSession()) {
            return [];
        }

        $cached = $request->session()->get(self::SHELL_KEY);

        if (is_array($cached) && ($cached['until'] ?? 0) > time()) {
            return $cached['data'];
        }

        try {
            $data = app(AdminApi::class)->get('dashboard/badges')->json('data');
        } catch (Throwable) {
            return [];
        }

        $request->session()->put(self::SHELL_KEY, ['until' => time() + self::SHELL_SECONDS, 'data' => $data]);

        return $data;
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/[\s’\'.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(-2)
            ->implode('');
    }
}
