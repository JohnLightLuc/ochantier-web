<?php

namespace App\Support\Api;

/**
 * Description lisible du navigateur de l’administrateur (« Chrome · macOS »), affichée dans la liste des sessions.
 */
class BrowserDevice
{
    /**
     * Motifs reconnus, du plus spécifique au plus général (Edge et Opera s’annoncent aussi comme Chrome).
     *
     * @var array<string, string>
     */
    private const BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];

    /**
     * @var array<string, string>
     */
    private const SYSTEMS = ['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'];

    public static function describe(?string $userAgent): string
    {
        $browser = self::match((string) $userAgent, self::BROWSERS);
        $system = self::match((string) $userAgent, self::SYSTEMS);

        return $browser || $system ? implode(' · ', array_filter([$browser ?? 'Navigateur', $system])) : 'Navigateur';
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function match(string $userAgent, array $patterns): ?string
    {
        foreach ($patterns as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
