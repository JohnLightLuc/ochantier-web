<?php

namespace App\Support;

use App\Enums\WorkerStatus;
use App\Models\Worker;
use Illuminate\Support\Str;

/**
 * Vivier de profils d’un métier : les ouvriers du module Ouvriers (hors archivés), complétés par des
 * profils déclarés de démonstration jusqu’à 26 profils, comme dans la maquette.
 *
 * Chaque profil a une clé stable : « w{id} » pour un ouvrier, « p-{nom} » pour un profil déclaré.
 */
class WorkerPool
{
    private const SIZE = 26;

    private const FIRST_NAMES = ['Adama', 'Yacouba', 'Kouadio', 'Brice', 'Moussa', 'Ange', 'Hervé', 'Souleymane', 'Arsène', 'Drissa', 'Cyrille', 'Lassina', 'Didier', 'Fabrice', 'Seydou', 'Aboubakar', 'Jean-Marc', 'Wilfried', 'Éric', 'Karim', 'Mariam', 'Rokia'];

    private const LAST_NAMES = ['Konan', 'Bamba', 'Coulibaly', 'N’Guessan', 'Kouassi', 'Diabaté', 'Yao', 'Touré', 'Koffi', 'Ouattara', 'Gnagne', 'Soro', 'Kacou', 'Diomandé', 'Aka', 'Tanoh', 'Assi', 'Fofana', 'Dosso'];

    private const COMMUNES = ['Abobo', 'Yopougon', 'Cocody', 'Adjamé', 'Koumassi', 'Marcory', 'Treichville', 'Port-Bouët', 'Bingerville', 'Anyama', 'Attécoubé', 'Plateau'];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $pools = [];

    /**
     * @return array<int, array{key: string, workerId: int|null, slug: string|null, code: string|null, name: string, trade: string, commune: string, experience: int, cost: int, rating: float, missions: int, disputes: int, availability: string, status: string, engagement: int, certified: bool}>
     */
    public function forTrade(string $trade): array
    {
        if (isset($this->pools[$trade])) {
            return $this->pools[$trade];
        }

        $pool = Worker::query()->where('trade', $trade)->where('status', '!=', WorkerStatus::Archived)->orderBy('id')->get()
            ->map(fn (Worker $w): array => [
                'key' => "w{$w->id}",
                'workerId' => $w->id,
                'slug' => $w->slug,
                'code' => $w->code,
                'name' => $w->name,
                'trade' => $trade,
                'commune' => $w->commune ?? 'Abidjan',
                'experience' => $w->experience_years,
                'cost' => $w->daily_rate,
                'rating' => (float) $w->rating,
                'missions' => (int) $w->reviews_count,
                'disputes' => 0,
                'availability' => $w->availability->label(),
                'status' => $w->status->label(),
                'engagement' => 80 + crc32($w->slug) % 20,
                'certified' => (bool) count($w->certifications ?? []),
            ])->all();

        $h = crc32($trade);
        for ($k = 0; count($pool) < self::SIZE && $k < 80; $k++) {
            $name = self::FIRST_NAMES[($k * 7 + $h) % count(self::FIRST_NAMES)].' '.self::LAST_NAMES[($k * 5 + ($h >> 3)) % count(self::LAST_NAMES)];
            if (in_array($name, array_column($pool, 'name'), true)) {
                continue;
            }
            $hh = crc32($name.$trade);
            $pool[] = [
                'key' => 'p-'.Str::slug($name),
                'workerId' => null,
                'slug' => null,
                'code' => null,
                'name' => $name,
                'trade' => $trade,
                'commune' => self::COMMUNES[$hh % count(self::COMMUNES)],
                'experience' => 1 + $hh % 12,
                'cost' => 7000 + ($hh % 12) * 500,
                'rating' => round(3.6 + ($hh % 14) / 10, 1),
                'missions' => 3 + $hh % 30,
                'disputes' => $hh % 13 === 0 ? 1 : 0,
                'availability' => ['Disponible', 'Disponible', 'Disponible', 'Préaffecté', 'En mission'][$hh % 5],
                'status' => $hh % 9 === 0 ? 'En vérification' : 'Validé',
                'engagement' => 70 + $hh % 31,
                'certified' => $hh % 3 === 0,
            ];
        }

        return $this->pools[$trade] = $pool;
    }
}
