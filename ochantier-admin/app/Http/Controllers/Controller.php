<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Badge d’un libellé de statut, avec sa couleur (config/admin.php).
     *
     * @return array{label: string, tone: string}
     */
    protected function badge(string $label): array
    {
        return ['label' => $label, 'tone' => config("admin.status_tones.{$label}", 'n')];
    }

    /**
     * En-tête de page : titre, sous-titre et fil d’Ariane (libellé, lien ou null pour la page courante).
     *
     * @param  array<int, array{0: string, 1?: string|null}>  $crumbs
     * @return array{title: string, subtitle: string, crumbs: array<int, array{label: string, href: string|null}>}
     */
    protected function header(string $title, string $subtitle = '', array $crumbs = []): array
    {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'crumbs' => array_map(fn (array $crumb): array => ['label' => $crumb[0], 'href' => $crumb[1] ?? null], $crumbs),
        ];
    }
}
