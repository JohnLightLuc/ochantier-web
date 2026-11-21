<?php

namespace App\Support;

/**
 * État de l’éditeur de contenu du site (api/v1/admin/landing/content) mis en forme pour la Landing Page : bandeau des
 * modifications non publiées, bloc « Site public » de la vue d’ensemble, éditeur d’une section et publications.
 */
class LandingContentPresenter
{
    /**
     * Bandeau des modifications non publiées, affiché sur chaque onglet.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    public static function draft(array $e, bool $canEdit): array
    {
        $errors = array_values(array_filter($e['checks'], fn (array $c) => $c['level'] === 'error'));

        return [
            'changes' => $e['changes'],
            'errors' => count($errors),
            'firstError' => $errors[0]['section'] ?? null,
            'liveNumber' => $e['live']['number'],
            'restoredFrom' => $e['restored_from'],
            'updatedAt' => LandingPresenter::moment($e['draft_updated_at']),
            'updatedBy' => $e['draft_updated_by'],
            'canEdit' => $canEdit,
        ];
    }

    /**
     * Bloc « Site public » de la vue d’ensemble : version en ligne, contrôles et structure de la page.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    public static function site(array $e, string $url): array
    {
        $draft = $e['draft'];
        $schema = collect($e['schema'])->keyBy('key');
        $changed = array_column($e['changes'], 'key');
        $errors = collect($e['checks'])->where('level', 'error')->groupBy('section');
        $keys = ['cover', 'header', ...$draft['order'], 'newsletter', 'footer', 'seo'];

        return [
            'url' => $url,
            'live' => ['number' => $e['live']['number'], 'publishedAt' => LandingPresenter::moment($e['live']['published_at']), 'publishedBy' => $e['live']['published_by']],
            'visible' => count(array_diff($draft['order'], $draft['hidden'])),
            'movable' => count($draft['order']),
            'checks' => self::checks($e),
            'order' => $draft['order'],
            'hidden' => $draft['hidden'],
            'sections' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => $schema[$key]['label'],
                'icon' => $schema[$key]['icon'],
                'description' => $schema[$key]['description'],
                'hideable' => $schema[$key]['hideable'],
                'movable' => $schema[$key]['movable'],
                'hidden' => in_array($key, $draft['hidden'], true),
                'changed' => in_array($key, $changed, true),
                'errors' => count($errors[$key] ?? []),
                'number' => $key === 'cover' ? '00' : (($i = array_search($key, $draft['order'], true)) !== false ? str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) : '—'),
            ], $keys),
        ];
    }

    /**
     * Éditeur d’une section : son schéma, ses valeurs du brouillon et en ligne, et la navigation entre sections.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    public static function editor(array $e, string $section): array
    {
        $draft = $e['draft'];
        $schema = collect($e['schema'])->keyBy('key');
        $changed = array_column($e['changes'], 'key');
        $errors = collect($e['checks'])->where('level', 'error')->groupBy('section');
        $s = $schema[$section];

        return [
            'section' => [
                'key' => $section,
                'label' => $s['label'],
                'icon' => $s['icon'],
                'description' => $s['description'],
                'hideable' => $s['hideable'],
                'hidden' => in_array($section, $draft['hidden'], true),
                'changed' => in_array($section, $changed, true),
                'fields' => $s['fields'],
                'values' => $draft['sections'][$section],
            ],
            'nav' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => $schema[$key]['label'],
                'icon' => $schema[$key]['icon'],
                'hidden' => in_array($key, $draft['hidden'], true),
                'changed' => in_array($key, $changed, true),
                'error' => isset($errors[$key]) && ! in_array($key, $draft['hidden'], true),
            ], ['cover', 'header', ...$draft['order'], 'newsletter', 'footer', 'seo']),
            'checks' => array_values(array_filter(self::checks($e), fn (array $c) => $c['section'] === $section)),
            'layout' => ['order' => $draft['order'], 'hidden' => $draft['hidden']],
            'files' => array_map(fn (array $f) => ['id' => $f['id'], 'name' => $f['name'], 'mimeType' => $f['mime_type'], 'url' => $f['url']], $e['files']),
        ];
    }

    /**
     * Ligne de l’historique des publications.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    public static function version(array $v): array
    {
        return [
            'number' => $v['number'],
            'note' => $v['note'],
            'sections' => $v['sections'],
            'restoredFrom' => $v['restored_from'],
            'publishedBy' => $v['published_by'] ?? 'Mise en service',
            'publishedAt' => LandingPresenter::moment($v['published_at']),
            'live' => (bool) $v['live'],
        ];
    }

    /**
     * Contrôles avant publication, avec le libellé de leur section.
     *
     * @param  array<string, mixed>  $e
     * @return list<array{level: string, section: string, sectionLabel: string, message: string}>
     */
    private static function checks(array $e): array
    {
        $labels = array_column($e['schema'], 'label', 'key');

        return array_map(fn (array $c) => [...$c, 'sectionLabel' => $labels[$c['section']] ?? $c['section']], $e['checks']);
    }
}
