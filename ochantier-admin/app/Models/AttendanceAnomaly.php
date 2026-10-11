<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anomalie d’une journée de pointage (détectée automatiquement ou signalée) : jamais supprimée, elle est traitée par statut.
 */
#[Fillable(['type', 'level', 'status', 'detected_on', 'note', 'decision'])]
class AttendanceAnomaly extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_on' => 'date',
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
