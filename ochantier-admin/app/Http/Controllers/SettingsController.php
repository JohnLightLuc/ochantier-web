<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\Payment;
use App\Models\SettingChange;
use App\Models\User;
use App\Support\AdminRoles;
use App\Support\PageBlocks as UI;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres de la plateforme : sections de réglages (général, métiers, commissions & frais, paiements, Matching &
 * affectations, utilisateurs & rôles, notifications, sécurité), intégrations et journal d’audit. Chaque
 * enregistrement est validé (bornes et règles croisées) puis tracé dans le journal.
 */
class SettingsController extends Controller
{
    /** Sections sans formulaire de réglages. */
    private const READ_ONLY = ['integrations', 'audit'];

    public function index(Request $request): Response
    {
        return $this->show($request, 'commissions');
    }

    public function show(Request $request, string $section): Response
    {
        $definition = Settings::definition($section);
        $editable = ! in_array($section, self::READ_ONLY, true);

        return Inertia::render('Settings/Page', [
            'section' => $section,
            'nav' => array_map(fn (string $k, array $s): array => ['key' => $k, 'label' => $s[0], 'icon' => $s[1], 'href' => "/parametres/{$k}", 'active' => $k === $section], array_keys(Settings::SECTIONS), Settings::SECTIONS),
            'definition' => $definition,
            'values' => $editable ? (object) Settings::values($section) : (object) [],
            'defaults' => $editable ? (object) Settings::defaults($section) : (object) [],
            'lastChange' => SettingChange::query()->where('section', Settings::SECTIONS[$section][0])->latest('occurred_at')->latest('id')->first()?->only(['author', 'occurred_at']),
            'profiles' => $section === 'matching' ? array_map(fn (array $p): array => ['label' => $p[0], 'notes' => $p[1]], Settings::MATCHING_PROFILES) : [],
            'operators' => $section === 'paiements' ? array_keys(config('admin.payments.fees')) : [],
            'tables' => match ($section) {
                'utilisateurs' => $this->userTables($request),
                'integrations' => [$this->integrationTable()],
                'audit' => [$this->auditTable($request)],
                default => [],
            },
            'toolbar' => $section === 'utilisateurs' ? [UI::button('Inviter un utilisateur', 'person_add', $this->inviteForm(), 'primary')] : [],
            'query' => array_filter($request->only(['q', 'rubrique', 'role']), fn ($v): bool => is_string($v) && $v !== ''),
            'rubriques' => $section === 'audit' ? array_values(array_diff(array_column(Settings::SECTIONS, 0), ['Journal d’audit'])) : [],
            'header' => $this->header('Paramètres de la plateforme', 'Configurez les règles métier, les accès et les services O’CHANTIER', [['Paramètres', '/parametres'], [Settings::SECTIONS[$section][0]]]),
        ]);
    }

    public function save(Request $request, string $section): RedirectResponse
    {
        abort_if(in_array($section, self::READ_ONLY, true), 404);
        $submitted = (array) $request->input('values', []);
        $values = [...Settings::values($section), ...array_intersect_key($submitted, Settings::values($section))];
        $errors = Settings::validate($section, $values);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $changes = Settings::save($section, $values);
        Inertia::flash('toast', match (count($changes)) {
            0 => 'Aucune modification à enregistrer.',
            1 => 'Paramètre enregistré : '.$changes[0].'.',
            default => count($changes).' paramètres enregistrés.',
        });

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function inviteForm(): array
    {
        return UI::form('Inviter un utilisateur', 'Un lien d’activation est envoyé par e-mail. Aucun mot de passe n’est défini par l’administration.', 'person_add', 'Envoyer l’invitation', '/parametres/utilisateurs/invitations', [
            ['name' => 'name', 'label' => 'Nom complet', 'required' => true],
            ['name' => 'email', 'label' => 'E-mail professionnel', 'required' => true, 'placeholder' => 'prenom.nom@ochantier.ci'],
            ['name' => 'phone', 'label' => 'Téléphone', 'placeholder' => '+225 07 00 00 00 00'],
            ['name' => 'role', 'label' => 'Rôle', 'type' => 'select', 'required' => true, 'options' => array_keys(AdminRoles::ROLES)],
            ['name' => 'modules', 'label' => 'Périmètre', 'full' => true, 'placeholder' => 'Ex. Chantiers, Affectations', 'hint' => 'Modules principalement suivis par l’utilisateur'],
        ]);
    }

    /**
     * Comptes administratifs, rôles et matrice des droits du rôle sélectionné.
     *
     * @return array<int, array<string, mixed>>
     */
    private function userTables(Request $request): array
    {
        $me = $request->user();
        $users = User::query()->orderByRaw("case status when 'Actif' then 0 when 'Invitation envoyée' then 1 else 2 end")->orderBy('name')->get();
        $roleForm = fn (User $u): array => UI::form('Changer le rôle', "Rôle de {$u->name}. Le changement s’applique à la prochaine action de l’utilisateur.", 'admin_panel_settings', 'Enregistrer', "/parametres/utilisateurs/{$u->id}/role", [['name' => 'role', 'label' => 'Rôle', 'type' => 'select', 'required' => true, 'options' => array_keys(AdminRoles::ROLES)]], 'primary', [], ['role' => $u->role]);
        $rows = $users->map(fn (User $u): array => UI::row((string) $u->id, [
            UI::person($u->name, $u->email),
            UI::stacked($u->role, $u->modules ?: '—'),
            UI::badge($u->status, match ($u->status) {
                'Actif' => 'g', 'Suspendu' => 'r', default => 'a'
            }),
            UI::badge($u->two_factor ? 'Activée' : 'Non activée', $u->two_factor ? 'g' : 'a'),
            UI::text($u->status === 'Invitation envoyée' ? 'Expire le '.$u->invitation_expires_on?->format('d/m/Y') : ($u->last_login_at?->format('d/m/Y H:i') ?? 'Jamais'), muted: ! $u->last_login_at),
        ], null, [], array_values(array_filter([
            ['admin_panel_settings', 'Changer le rôle', $u->is($me) ? UI::error('Vous ne pouvez pas modifier votre propre rôle.') : $roleForm($u)],
            $u->status === 'Invitation envoyée' ? ['send', 'Renvoyer l’invitation', UI::post("/parametres/utilisateurs/{$u->id}/invitation")] : null,
            $u->status === 'Suspendu'
                ? ['lock_open', 'Réactiver le compte', UI::confirm('Réactiver le compte', "{$u->name} pourra de nouveau se connecter au Back-office.", 'lock_open', 'Réactiver', "/parametres/utilisateurs/{$u->id}/statut")]
                : ['block', 'Suspendre le compte', $u->is($me) ? UI::error('Vous ne pouvez pas suspendre votre propre compte.') : UI::confirm('Suspendre le compte', "{$u->name} ne pourra plus se connecter. Ses actions passées restent tracées.", 'block', 'Suspendre', "/parametres/utilisateurs/{$u->id}/statut", 'danger')],
        ]))))->all();
        $role = array_key_exists((string) $request->query('role'), AdminRoles::ROLES) ? (string) $request->query('role') : 'Responsable opérations';
        $counts = $users->where('status', '!=', 'Suspendu')->countBy('role');

        return [
            UI::table('manage_accounts', 'Utilisateurs administratifs', "{$users->count()} compte(s) · {$users->where('status', 'Actif')->count()} actif(s)", ['Utilisateur', 'Rôle et périmètre', 'Statut', 'Double authentification', 'Dernière connexion'], $rows, 900),
            UI::table('admin_panel_settings', 'Rôles', count(AdminRoles::ROLES).' rôles', ['Rôle', 'Description', 'Comptes', 'Droits accordés'], array_map(fn (string $r, string $d): array => UI::row($r, [
                UI::text($r, true), UI::text($d, muted: true), UI::text((string) ($counts[$r] ?? 0)), UI::text(AdminRoles::count($r).' / '.(count(AdminRoles::MODULES) * count(AdminRoles::ACTIONS))),
            ], UI::link('/parametres/utilisateurs?role='.rawurlencode($r))), array_keys(AdminRoles::ROLES), AdminRoles::ROLES), 800),
            UI::table('grid_on', "Matrice des droits · {$role}", 'Cliquez sur un rôle pour afficher ses droits', ['Module', ...AdminRoles::ACTIONS], array_map(fn (string $m): array => UI::row($m, [
                UI::text($m, true), ...array_map(fn (string $a): array => AdminRoles::allows($role, $m, $a) ? UI::badge('Oui', 'g') : UI::text('—', muted: true), AdminRoles::ACTIONS),
            ]), AdminRoles::MODULES), 1100),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationTable(): array
    {
        $today = Payment::referenceDate();
        $items = Integration::query()->orderBy('type')->orderBy('name')->get();
        $rows = $items->map(fn (Integration $i): array => UI::row((string) $i->id, [
            UI::stacked($i->name, $i->type),
            UI::stacked($i->environment, $i->endpoint),
            UI::stacked('ID '.$i->maskedClientId(), 'Secret '.$i->maskedSecret()),
            UI::badge($i->status, match ($i->status) {
                'Actif' => 'g', 'Erreur' => 'r', 'Suspendu' => 'n', default => 'b'
            }),
            $i->expires_on->lt($today) ? UI::badge('Expiré le '.$i->expires_on->format('d/m/Y'), 'r') : UI::text($i->expires_on->format('d/m/Y'), muted: $i->expires_on->gt($today->addDays(30))),
            UI::stacked($i->latency ? "{$i->latency} ms" : '—', 'Erreurs '.str_replace('.', ',', (string) $i->error_rate).' %'),
            UI::stacked($i->tested_at?->format('d/m/Y H:i') ?? 'Jamais', $i->last_result),
        ], null, [], [
            ['wifi_tethering', 'Tester la connexion', $i->status === 'Suspendu' ? UI::error('Réactivez l’intégration avant de la tester.') : UI::post("/parametres/integrations/{$i->id}/test")],
            $i->status === 'Suspendu'
                ? ['play_arrow', 'Réactiver', UI::confirm('Réactiver l’intégration', "{$i->name} sera de nouveau utilisée par la plateforme.", 'play_arrow', 'Réactiver', "/parametres/integrations/{$i->id}/statut")]
                : ['pause', 'Suspendre', UI::confirm('Suspendre l’intégration', "{$i->name} ne sera plus appelée. Les opérations en cours peuvent échouer.", 'pause', 'Suspendre', "/parametres/integrations/{$i->id}/statut", 'danger')],
        ]))->all();

        return UI::table('power', 'Services connectés', "{$items->count()} intégration(s) · {$items->where('status', 'Erreur')->count()} en erreur", ['Service', 'Environnement', 'Identifiants', 'Statut', 'Expiration', 'Performance', 'Dernier test'], $rows, 1100);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditTable(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $items = SettingChange::query()
            ->when($request->query('rubrique'), fn ($query, $r) => $query->where('section', $r))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('action', 'like', "%{$q}%")->orWhere('author', 'like', "%{$q}%")))
            ->orderByDesc('occurred_at')->orderByDesc('id')->get();
        $page = UI::paginate($items, max(1, (int) $request->query('page', 1)), fn (SettingChange $c): array => UI::row((string) $c->id, [
            UI::stacked($c->occurred_at->format('d/m/Y'), $c->occurred_at->format('H:i')),
            UI::person($c->author),
            UI::text($c->section),
            UI::text($c->action),
            UI::stacked($c->old_value ?? '—', $c->new_value ? "→ {$c->new_value}" : null),
        ]));

        return UI::table('description', 'Journal d’audit', $page['label'], ['Date', 'Auteur', 'Rubrique', 'Modification', 'Ancienne → nouvelle valeur'], $page['rows'], 1000, ['history', 'Aucune modification', 'Aucune modification ne correspond aux filtres.'], $page['pagination']);
    }
}
