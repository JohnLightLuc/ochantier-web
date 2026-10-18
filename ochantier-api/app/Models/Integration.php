<?php

namespace App\Models;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationServiceType;
use App\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connexions aux prestataires ; le secret est chiffré par l'application, jamais stocké en clair.
 */
#[Fillable([
    'code', 'provider', 'service_type', 'environment', 'endpoint', 'client_id', 'secret_encrypted',
    'credentials_expire_at', 'status', 'last_tested_at', 'last_test_ok', 'last_test_message', 'latency_ms',
    'error_rate', 'updated_by_admin_id',
])]
#[Hidden(['secret_encrypted'])]
class Integration extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_type' => IntegrationServiceType::class,
            'environment' => IntegrationEnvironment::class,
            'secret_encrypted' => 'encrypted',
            'credentials_expire_at' => 'date',
            'status' => IntegrationStatus::class,
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'error_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
