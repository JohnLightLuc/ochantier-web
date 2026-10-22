<?php

namespace App\Models;

use App\Enums\AppName;
use App\Enums\AppPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Versions minimale et courante d’une application mobile par plateforme.
 */
class AppVersion extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'app_name', 'platform', 'min_version', 'latest_version', 'force_update', 'message', 'store_url',
    ];

    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_name' => AppName::class,
            'platform' => AppPlatform::class,
            'force_update' => 'boolean',
        ];
    }
}
