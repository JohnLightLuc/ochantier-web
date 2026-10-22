<?php

namespace App\Models;

use App\Enums\DocumentVerificationStatus;
use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documents typés et versionnés (KYC, plans, devis, preuves…).
 */
class Document extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'owner_type', 'owner_id', 'document_type_id', 'label', 'number_encrypted', 'number_last4', 'issuing_authority',
        'issued_at', 'expires_at', 'version', 'replaces_document_id', 'verification_status', 'verified_by_admin_id',
        'verified_at', 'rejection_reason', 'visibility', 'uploaded_by_type', 'uploaded_by_id', 'client_uuid',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['number_encrypted'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number_encrypted' => 'encrypted',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verification_status' => DocumentVerificationStatus::class,
            'verified_at' => 'datetime',
            'visibility' => DocumentVisibility::class,
        ];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function replacesDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<VerificationCorrection, $this>
     */
    public function verificationCorrections(): HasMany
    {
        return $this->hasMany(VerificationCorrection::class);
    }

    /**
     * @return HasMany<WorkerCertification, $this>
     */
    public function workerCertifications(): HasMany
    {
        return $this->hasMany(WorkerCertification::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function uploadedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsToMany<MediaFile, $this>
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class, 'document_files', 'document_id', 'media_file_id')
            ->withPivot('side', 'page_number');
    }
}
