<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Blocs d’affichage des pages décrites par le serveur (tuiles, alertes, étapes, barres empilées, histogrammes,
 * cartes, tableaux, onglets, en-tête de fiche) et actions déclaratives exécutées par la page : lien,
 * téléchargement, envoi, formulaire, confirmation, fiche d’indicateur, message d’erreur.
 */
class PageBlocks
{
    /**
     * Ton court (g, a, o, r, b, p, n) → ton des tuiles et alertes.
     *
     * @var array<string, string>
     */
    public const TONES = ['g' => 'success', 'a' => 'warning', 'o' => 'orange', 'r' => 'danger', 'b' => 'info', 'p' => 'purple', 'n' => 'neutral'];

    public const PALETTE = ['var(--c-primary-500)', 'var(--c-accent-600)', 'var(--c-success)', 'var(--c-warning)', 'var(--c-danger)', 'var(--c-info)', 'var(--c-purple)'];

    /**
     * @return array<string, string>
     */
    public static function link(string $href): array
    {
        return ['type' => 'link', 'href' => $href];
    }

    /**
     * @return array<string, string>
     */
    public static function download(string $href): array
    {
        return ['type' => 'download', 'href' => $href];
    }

    /**
     * Formulaire en modale (champs validés par le serveur).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $extra  données envoyées en plus des champs
     * @param  array<string, string>  $initial  valeurs pré-remplies
     * @return array<string, mixed>
     */
    public static function form(string $title, string $body, string $icon, string $ok, string $url, array $fields, string $tone = 'primary', array $extra = [], array $initial = []): array
    {
        return ['type' => 'form', 'title' => $title, 'body' => $body, 'icon' => $icon, 'ok' => $ok, 'url' => $url, 'fields' => $fields, 'tone' => $tone, 'extra' => (object) $extra, 'initial' => (object) $initial];
    }

    /**
     * Formulaire à motif unique (longueur minimale vérifiée par le serveur).
     *
     * @return array<string, mixed>
     */
    public static function reason(string $title, string $body, string $icon, string $ok, string $url, string $label, string $tone = 'primary'): array
    {
        return self::form($title, $body, $icon, $ok, $url, [['name' => 'reason', 'label' => $label, 'type' => 'textarea', 'required' => true]], $tone);
    }

    /**
     * @return array<string, string>
     */
    public static function confirm(string $title, string $body, string $icon, string $ok, string $url, string $tone = 'primary'): array
    {
        return ['type' => 'confirm', 'title' => $title, 'body' => $body, 'icon' => $icon, 'ok' => $ok, 'url' => $url, 'tone' => $tone];
    }

    /**
     * @return array<string, string>
     */
    public static function kpi(string $id): array
    {
        return ['type' => 'kpi', 'id' => $id];
    }

    /**
     * @return array<string, string>
     */
    public static function rates(): array
    {
        return ['type' => 'rates'];
    }

    /**
     * Envoi direct, sans confirmation.
     *
     * @return array<string, string>
     */
    public static function post(string $url): array
    {
        return ['type' => 'post', 'url' => $url];
    }

    /**
     * Message d’erreur affiché sans appel au serveur (action bloquée).
     *
     * @return array<string, string>
     */
    public static function error(string $message): array
    {
        return ['type' => 'error', 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function button(string $label, string $icon, array $action, string $variant = 'secondary'): array
    {
        return ['label' => $label, 'icon' => $icon, 'variant' => $variant, 'action' => $action];
    }

    /**
     * @param  array{bar?: float|int|null, barColor?: string, sub?: string}  $options
     * @return array<string, mixed>
     */
    public static function tile(string $label, string|int $value, string $icon, string $tone, array $options = []): array
    {
        return array_filter([
            'label' => $label, 'value' => (string) $value, 'icon' => $icon, 'tone' => self::TONES[$tone] ?? $tone,
            'bar' => isset($options['bar']) ? round(min(100, max(0, (float) $options['bar'])), 1) : null, 'barColor' => $options['barColor'] ?? null, 'sub' => $options['sub'] ?? null,
        ], fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>
     */
    public static function alert(string $tone, string $icon, string $title, string $text, ?string $cta = null, ?array $action = null): array
    {
        return ['tone' => $tone, 'icon' => $icon, 'title' => $title, 'text' => $text, 'cta' => $action ? $cta : null, 'action' => $action];
    }

    /**
     * Histogramme : [libellé, valeur, valeur affichée].
     *
     * @param  array<int, array{0: string, 1: int|float, 2?: string}>  $bars
     * @return array<string, mixed>
     */
    public static function chart(string $title, string $sub, array $bars, ?string $color = null): array
    {
        return ['title' => $title, 'sub' => $sub, 'color' => $color, 'bars' => array_map(fn (array $b): array => ['label' => $b[0], 'value' => round((float) $b[1], 2), 'text' => $b[2] ?? FinanceInsights::money($b[1])], $bars)];
    }

    /**
     * Étape d’un parcours (done, cur, warn, fail, todo).
     *
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>
     */
    public static function step(string $label, string $sub, string $state, string $icon, ?array $action = null): array
    {
        return ['label' => $label, 'sub' => $sub, 'state' => $state, 'icon' => $icon, 'action' => $action];
    }

    /**
     * Barre empilée : [libellé, valeur, couleur ?, valeur affichée ?] ; montants en FCFA ou nombres.
     *
     * @param  array<int, array{0: string, 1: int|float, 2?: string|null, 3?: string}>  $segments
     * @return array<string, mixed>
     */
    public static function stack(string $title, string $sub, array $segments, bool $money = true): array
    {
        $total = array_sum(array_map(fn (array $s): float => max(0, (float) $s[1]), $segments));
        $format = fn (int|float $v): string => $money ? FinanceInsights::money($v) : number_format($v, 0, ',', ' ');

        return ['title' => $title, 'sub' => $sub, 'total' => $format($total), 'segments' => array_map(fn (array $s, int $i): array => [
            'label' => $s[0], 'value' => max(0, (float) $s[1]), 'color' => $s[2] ?? self::PALETTE[$i % count(self::PALETTE)],
            'text' => $s[3] ?? $format($s[1]), 'percent' => $total && ! isset($s[3]) ? FinanceInsights::percent($s[1] / $total * 100) : '',
        ], $segments, array_keys($segments))];
    }

    /**
     * Carte cliquable d’un panneau.
     *
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>
     */
    public static function card(string $icon, string $kicker, string $title, string $sub, string $badge, string $tone, ?array $action): array
    {
        return ['icon' => $icon, 'kicker' => $kicker, 'title' => $title, 'sub' => $sub, 'badge' => $badge, 'tone' => $tone, 'action' => $action];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<string, mixed>|null  $button
     * @return array<string, mixed>
     */
    public static function cards(string $icon, string $title, array $cards, ?array $button = null): array
    {
        return ['icon' => $icon, 'title' => $title, 'kind' => 'cards', 'cards' => $cards, 'button' => $button];
    }

    /**
     * @param  array<int, array{0: string, 1: mixed}>  $items
     * @param  array<string, mixed>|null  $button
     * @return array<string, mixed>
     */
    public static function keyValues(string $icon, string $title, array $items, ?array $button = null): array
    {
        return ['icon' => $icon, 'title' => $title, 'kind' => 'kv', 'items' => array_map(fn (array $i): array => [$i[0], $i[1] === null || $i[1] === '' ? '—' : (string) $i[1]], $items), 'button' => $button];
    }

    /**
     * @param  array<int, array{icon: string, title: string, text: string, when: string}>  $feed
     * @return array<string, mixed>
     */
    public static function feed(string $icon, string $title, array $feed): array
    {
        return ['icon' => $icon, 'title' => $title, 'kind' => 'feed', 'feed' => $feed, 'button' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function text(string $v, bool $strong = false, bool $muted = false): array
    {
        return ['k' => 'txt', 'v' => $v, 'b' => $strong, 'm' => $muted];
    }

    /**
     * @return array<string, mixed>
     */
    public static function money(int|float|null $v, bool $strong = false, bool $dashIfZero = false): array
    {
        return $dashIfZero && ! $v ? self::text('—', false, true) : self::text(FinanceInsights::money($v), $strong);
    }

    /**
     * @return array<string, mixed>
     */
    public static function stacked(string $v, ?string $sub = null): array
    {
        return ['k' => 'stk', 'v' => $v, 'sub' => $sub];
    }

    /**
     * @return array<string, mixed>
     */
    public static function badge(string $v, string $tone): array
    {
        return ['k' => 'bdg', 'v' => $v, 'tn' => $tone];
    }

    /**
     * @return array<string, mixed>
     */
    public static function progress(int|float|null $v): array
    {
        return ['k' => 'prog', 'v' => (int) round(min(100, max(0, (float) $v)))];
    }

    /**
     * @return array<string, mixed>
     */
    public static function person(string $v, ?string $sub = null): array
    {
        return ['k' => 'per', 'v' => $v, 'sub' => $sub];
    }

    /**
     * @return array<string, mixed>
     */
    public static function place(string $v): array
    {
        return ['k' => 'loc', 'v' => $v];
    }

    /**
     * Ligne de tableau : cellules, action à l’ouverture, actions de ligne et menu « Plus d’actions »
     * ([icône, libellé, action]).
     *
     * @param  array<int, array<string, mixed>>  $cells
     * @param  array<string, mixed>|null  $open
     * @param  array<int, array{0: string, 1: string, 2: array<string, mixed>}>  $acts
     * @param  array<int, array{0: string, 1: string, 2: array<string, mixed>}>  $menu
     * @return array<string, mixed>
     */
    public static function row(string $key, array $cells, ?array $open = null, array $acts = [], array $menu = []): array
    {
        $map = fn (array $a): array => ['icon' => $a[0], 'label' => $a[1], 'action' => $a[2]];

        return ['key' => $key, 'cells' => $cells, 'open' => $open, 'acts' => array_map($map, $acts), 'menu' => array_map($map, $menu)];
    }

    /**
     * Onglet de fiche ou de vue : libellé, lien, compteur, actif.
     *
     * @return array<string, mixed>
     */
    public static function tab(string $label, string $href, int $count = 0, bool $active = false): array
    {
        return ['label' => $label, 'href' => $href, 'count' => $count, 'active' => $active];
    }

    /**
     * En-tête de fiche : titre, statut, sous-titre, informations clés, actions principales et menu.
     *
     * @param  array{0: string, 1: string}  $status  libellé, ton
     * @param  array{0: string, 1: string}|null  $tag  libellé, ton
     * @param  array<int, array{0: string, 1: string, 2: string}>  $meta  icône, libellé, valeur
     * @param  array<int, array<string, mixed>>  $buttons
     * @param  array<int, array{0: string, 1: string, 2: array<string, mixed>}>  $menu
     * @return array<string, mixed>
     */
    public static function profile(string $title, array $status, ?array $tag, string $sub, array $meta, array $buttons = [], array $menu = []): array
    {
        return [
            'title' => $title, 'status' => ['label' => $status[0], 'tone' => $status[1]], 'tag' => $tag ? ['label' => $tag[0], 'tone' => $tag[1]] : null, 'sub' => $sub,
            'meta' => array_map(fn (array $m): array => ['icon' => $m[0], 'label' => $m[1], 'value' => $m[2]], $meta),
            'buttons' => $buttons, 'menu' => array_map(fn (array $a): array => ['icon' => $a[0], 'label' => $a[1], 'action' => $a[2]], $menu),
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{0: string, 1: string, 2?: string}  $empty  icône, titre, texte
     * @param  array<string, int>|null  $pagination
     * @return array<string, mixed>
     */
    public static function table(string $icon, string $title, string $count, array $columns, array $rows, int $minWidth = 1000, array $empty = ['search_off', 'Aucun résultat'], ?array $pagination = null): array
    {
        return ['icon' => $icon, 'title' => $title, 'count' => $count, 'columns' => $columns, 'rows' => $rows, 'minWidth' => $minWidth, 'empty' => ['icon' => $empty[0], 'title' => $empty[1], 'text' => $empty[2] ?? ''], 'pagination' => $pagination];
    }

    /**
     * Page d’une liste (10 lignes) : lignes affichées, pagination et libellé de comptage.
     *
     * @param  Collection<int, mixed>  $items
     * @return array{rows: array<int, mixed>, pagination: array<string, int>, label: string}
     */
    public static function paginate(Collection $items, int $page, callable $map, int $size = 10): array
    {
        $p = PaymentInsights::paginate($items, $page, $size, $map);

        return ['rows' => $p['data'], 'pagination' => array_diff_key($p, ['data' => true]), 'label' => $p['total'] ? "{$p['from']}–{$p['to']} sur {$p['total']}" : '0 résultat'];
    }
}
