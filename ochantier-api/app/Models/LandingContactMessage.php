<?php

namespace App\Models;

use App\Enums\LandingMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message du formulaire de contact du site public.
 */
class LandingContactMessage extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'profile', 'name', 'company', 'phone', 'email', 'subject', 'message', 'status', 'handled_by_admin_id',
        'handling_note', 'handled_at', 'consented_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LandingMessageStatus::class,
            'handled_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function handledByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }
}
