<?php

namespace App\Models;

use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Model;

class WorkerApplication extends Model
{
    use HasReference;

    public const REFERENCE_PREFIX = 'OUV';

    protected $fillable = [
        'reference', 'prenom', 'nom', 'telephone', 'email', 'date_naissance', 'genre', 'photo_path', 'cni_path',
        'metier', 'tarif_journalier', 'experience_annees', 'niveau', 'competences', 'certifications', 'presentation',
        'commune', 'quartier', 'rayon_deplacement', 'disponibilite', 'jours_disponibles', 'moyen_paiement',
        'numero_paiement', 'chantier_cible', 'chantier_commune', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'competences' => 'array',
            'certifications' => 'array',
            'jours_disponibles' => 'array',
        ];
    }
}
