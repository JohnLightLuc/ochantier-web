<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = ['email', 'desinscrit_le'];

    protected function casts(): array
    {
        return ['desinscrit_le' => 'datetime'];
    }
}
