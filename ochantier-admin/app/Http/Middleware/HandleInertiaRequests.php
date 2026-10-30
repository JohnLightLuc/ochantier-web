<?php

namespace App\Http\Middleware;

use App\Support\Api\AdminUser;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
            'navigation' => fn () => $user ? $this->navigation() : [],
            'notifications' => fn () => $user ? $this->notifications() : [],
        ];
    }

    /**
     * Modules de la sidebar avec leurs compteurs.
     *
     * @return array<int, array{key: string, label: string, icon: string, path: string, title: string, subtitle: string, badge: int|null}>
     */
    private function navigation(): array
    {
        $badges = config('admin.demo_badges');

        return array_map(
            fn (array $module): array => [...$module, 'badge' => $badges[$module['key']] ?? null],
            config('admin.modules'),
        );
    }

    /**
     * Notifications de l’administrateur (données de démonstration).
     *
     * @return array<int, array{id: string, icon: string, tone: string, title: string, text: string, priority: string, when: string, read: bool, href: string}>
     */
    private function notifications(): array
    {
        $notification = fn (string $id, string $icon, string $tone, string $title, string $text, string $priority, string $when, bool $read, string $href): array => compact('id', 'icon', 'tone', 'title', 'text', 'priority', 'when', 'read', 'href');

        return [
            $notification('n1', 'person_check', 'warning', '8 profils ouvriers à valider', 'Pièces d’identité et certificats en attente', 'Urgent', 'Il y a 10 min', false, '/ouvriers?filtre=À valider'),
            $notification('n2', 'payments', 'info', 'Paiement PAY-260918-1702 à autoriser', '2 400 000 FCFA · Équipe Menuiserie CI', 'Élevé', 'Il y a 45 min', false, '/promoteurs/sicogi/paiements/PAY-260918-1702'),
            $notification('n3', 'gavel', 'danger', 'Litige LIT-0102 escaladé', 'Priorité haute · Logements sociaux Yopougon', 'Urgent', 'Il y a 2 h', false, '/promoteurs/sicogi/litiges'),
            $notification('n4', 'swap_horiz', 'orange', 'Remplacement urgent demandé', 'Tour Horizon · 2 électriciens absents', 'Élevé', 'Il y a 3 h', false, '/affectations?filtre=Remplacements'),
            $notification('n5', 'event_busy', 'danger', 'Attestation fiscale expirée', 'Bâtir Afrique · document KYC', 'Moyen', 'Hier · 16:20', true, '/promoteurs/batir-afrique/kyc'),
            $notification('n6', 'construction', 'success', 'Nouveau chantier créé', 'Extension Cité Verte · SICOGI', 'Moyen', 'Hier · 11:05', true, '/promoteurs/sicogi/chantiers/ch6'),
            $notification('n7', 'task_alt', 'purple', 'Affectation confirmée', 'Koffi Adjoumani · Résidence Les Oliviers', 'Moyen', '21 sept. · 14:30', true, '/affectations?filtre=Confirmées'),
        ];
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/[\s’\'.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(-2)
            ->implode('');
    }
}
