<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Inscrit à la lettre d’information du site public.
 */
class NewsletterSubscriber extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = ['email', 'subscribed_at', 'unsubscribed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subscribed_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }
}
