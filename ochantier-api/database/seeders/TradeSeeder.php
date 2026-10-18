<?php

namespace Database\Seeders;

use App\Models\ConstructionType;
use App\Models\Skill;
use App\Models\Specialty;
use App\Models\Trade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Métiers du bâtiment avec leurs spécialités et compétences (référentiel de la maquette du Back Office),
 * et types de construction.
 */
class TradeSeeder extends Seeder
{
    /**
     * Métier => [description, spécialités, compétences].
     *
     * @var array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    private const TRADES = [
        'Maçon' => ['Gros œuvre : élévation, chapes, enduits.', ['Maçonnerie traditionnelle', 'Élévation de murs', 'Chapes et enduits'], ['Lecture de plans', 'Dosage béton', 'Pose de parpaings', 'Coffrage simple']],
        'Électricien' => ['Installations électriques du bâtiment.', ['Courant faible', 'Tableaux électriques'], ['Câblage', 'Mise à la terre', 'Lecture de schémas', 'Norme NF C 15-100']],
        'Plombier' => ['Réseaux d’eau, sanitaires et évacuations.', ['Sanitaires', 'Réseaux PVC'], ['Soudure cuivre', 'Pose de sanitaires', 'Évacuations', 'Recherche de fuites']],
        'Peintre' => ['Peinture intérieure et façades.', ['Peinture intérieure', 'Façades'], ['Préparation des supports', 'Enduits de lissage', 'Peinture au pistolet']],
        'Menuisier' => ['Menuiseries bois intérieures et extérieures.', ['Menuiserie bois', 'Pose de portes'], ['Découpe', 'Assemblage', 'Pose de menuiseries', 'Finitions']],
        'Carreleur' => ['Revêtements de sols et murs.', ['Carrelage sol', 'Faïence murale'], ['Calepinage', 'Pose collée', 'Joints', 'Découpe à la meuleuse']],
        'Manœuvre' => ['Aide sur chantier et manutention.', ['Manutention', 'Préparation de chantier'], ['Gâchage', 'Nettoyage de chantier', 'Aide au coffrage']],
        'Coffreur' => ['Coffrages bois et métalliques.', ['Coffrage bois', 'Coffrage métallique'], ['Lecture de plans', 'Étaiement', 'Décoffrage', 'Traçage']],
        'Ferrailleur' => ['Armatures pour béton armé.', ['Armatures', 'Façonnage'], ['Lecture de plans de ferraillage', 'Ligature', 'Découpe et pliage']],
        'Soudeur' => ['Soudure et charpente métallique.', ['Soudure à l’arc', 'Charpente métallique'], ['Soudure MIG', 'Meulage', 'Lecture de plans']],
    ];

    /**
     * @var array<string, string>
     */
    private const CONSTRUCTION_TYPES = [
        'maison_individuelle' => 'Maison individuelle',
        'immeuble_residentiel' => 'Immeuble résidentiel',
        'batiment_commercial' => 'Bâtiment commercial ou bureaux',
        'equipement_public' => 'Équipement public (école, centre de santé…)',
        'batiment_industriel' => 'Bâtiment industriel ou entrepôt',
        'renovation' => 'Rénovation ou réhabilitation',
        'voirie_reseaux' => 'Voirie et réseaux divers',
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::TRADES as $label => [$description, $specialties, $skills]) {
            $trade = Trade::query()->updateOrCreate(['code' => $this->code($label)], [
                'label' => $label,
                'description' => $description,
                'sort_order' => $order += 10,
                'is_active' => true,
            ]);

            foreach ($specialties as $specialty) {
                Specialty::query()->updateOrCreate(['trade_id' => $trade->getKey(), 'code' => $this->code($specialty)], ['label' => $specialty, 'is_active' => true]);
            }

            foreach ($skills as $index => $skill) {
                Skill::query()->updateOrCreate(['trade_id' => $trade->getKey(), 'code' => $this->code($skill)], ['label' => $skill, 'sort_order' => ($index + 1) * 10, 'is_active' => true]);
            }
        }

        foreach (self::CONSTRUCTION_TYPES as $code => $label) {
            ConstructionType::query()->updateOrCreate(['code' => $code], ['label' => $label, 'is_active' => true]);
        }
    }

    private function code(string $label): string
    {
        return Str::limit(Str::slug(str_replace('œ', 'oe', $label), '_'), 40, '');
    }
}
