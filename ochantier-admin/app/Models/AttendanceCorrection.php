<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de correction d’un pointage validé : l’ancienne valeur est conservée, la nouvelle n’est appliquée qu’après approbation.
 */
#[Fillable(['field', 'old_value', 'new_value', 'motif', 'comment', 'proof', 'status', 'requested_by', 'requested_at', 'decision', 'impact'])]
class AttendanceCorrection extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
