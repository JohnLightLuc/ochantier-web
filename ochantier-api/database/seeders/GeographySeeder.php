<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Commune;
use App\Models\Country;
use App\Models\Neighborhood;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Hiérarchie géographique : Côte d’Ivoire, villes, communes du district d’Abidjan et principaux quartiers.
 * Coordonnées approximatives (centre de la localité), en degrés décimaux.
 */
class GeographySeeder extends Seeder
{
    /**
     * Villes : code, nom, latitude, longitude, communes [nom => [latitude, longitude, quartiers]].
     *
     * @var array<int, array{0: string, 1: string, 2: float, 3: float, 4: array<string, array{0: float, 1: float, 2: list<string>}>}>
     */
    private const CITIES = [
        ['ABJ', 'Abidjan', 5.3600, -4.0083, [
            'Abobo' => [5.4180, -4.0200, ['Abobo Gare', 'PK 18', 'Avocatier', 'Sagbé']],
            'Adjamé' => [5.3660, -4.0220, ['Williamsville', 'Adjamé Liberté', '220 Logements']],
            'Anyama' => [5.4940, -4.0520, []],
            'Attécoubé' => [5.3330, -4.0420, ['Locodjro', 'Santé']],
            'Bingerville' => [5.3550, -3.8850, ['Bingerville Centre', 'Akandjé']],
            'Cocody' => [5.3480, -3.9870, ['Angré', 'Riviera 2', 'Riviera Palmeraie', 'II Plateaux', 'Blockhaus', 'Danga']],
            'Koumassi' => [5.2950, -3.9520, ['Remblais', 'Koumassi Campement']],
            'Marcory' => [5.3030, -3.9830, ['Zone 4', 'Biétry', 'Anoumabo']],
            'Plateau' => [5.3230, -4.0190, ['Cité administrative', 'Plateau Indénié']],
            'Port-Bouët' => [5.2560, -3.9260, ['Vridi', 'Gonzagueville']],
            'Songon' => [5.3200, -4.2500, []],
            'Treichville' => [5.2920, -4.0130, ['Arras', 'Biafra']],
            'Yopougon' => [5.3450, -4.0800, ['Niangon', 'Sicogi', 'Sideci', 'Selmer', 'Toits Rouges']],
        ]],
        ['YAM', 'Yamoussoukro', 6.8276, -5.2893, ['Yamoussoukro' => [6.8276, -5.2893, []]]],
        ['BKE', 'Bouaké', 7.6900, -5.0300, ['Bouaké' => [7.6900, -5.0300, []]]],
        ['SPY', 'San-Pédro', 4.7485, -6.6363, ['San-Pédro' => [4.7485, -6.6363, []]]],
        ['GBA', 'Grand-Bassam', 5.2118, -3.7388, ['Grand-Bassam' => [5.2118, -3.7388, []]]],
        ['DAL', 'Daloa', 6.8774, -6.4502, ['Daloa' => [6.8774, -6.4502, []]]],
        ['KHO', 'Korhogo', 9.4580, -5.6296, ['Korhogo' => [9.4580, -5.6296, []]]],
    ];

    public function run(): void
    {
        $country = Country::query()->updateOrCreate(['iso_code' => 'CI'], ['name' => 'Côte d’Ivoire', 'phone_prefix' => '+225', 'is_active' => true]);

        foreach (self::CITIES as [$code, $name, $latitude, $longitude, $communes]) {
            $city = City::query()->updateOrCreate(
                ['country_id' => $country->getKey(), 'name' => $name],
                ['code' => $code, 'location' => $this->point($latitude, $longitude), 'is_active' => true],
            );

            foreach ($communes as $communeName => [$communeLatitude, $communeLongitude, $neighborhoods]) {
                $commune = Commune::query()->updateOrCreate(
                    ['city_id' => $city->getKey(), 'name' => $communeName],
                    ['location' => $this->point($communeLatitude, $communeLongitude), 'is_active' => true],
                );

                foreach ($neighborhoods as $neighborhood) {
                    Neighborhood::query()->updateOrCreate(['commune_id' => $commune->getKey(), 'name' => $neighborhood], ['is_active' => true]);
                }
            }
        }
    }

    private function point(float $latitude, float $longitude): Expression
    {
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude));
    }
}
