<?php

namespace App\Models;

use App\Enums\NeedDocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document ou média joint à un besoin (cahier des charges, plan, consignes…).
 */
#[Fillable(['type', 'file_name', 'file_path', 'added_on', 'author', 'status'])]
class NeedDocument extends Model
{
    /**
     * @var array<int, string>
     */
    public const TYPES = ['Cahier des charges', 'Fiche de mission', 'Plan du chantier', 'Photos du chantier', 'Consignes de sécurité', 'Planning', 'Contrat ou convention', 'Documents techniques', 'Autres pièces'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NeedDocumentStatus::class,
            'added_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }
}
