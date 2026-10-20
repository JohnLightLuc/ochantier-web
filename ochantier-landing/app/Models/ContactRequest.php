<?php

namespace App\Models;

use App\Models\Concerns\HasReference;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    use HasReference;

    public const REFERENCE_PREFIX = 'OC';

    protected $fillable = [
        'reference', 'profil', 'nom', 'entreprise', 'telephone', 'email', 'objet', 'message', 'traite_le',
    ];

    protected function casts(): array
    {
        return ['traite_le' => 'datetime'];
    }
}
