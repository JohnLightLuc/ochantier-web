<?php

namespace App\Support\Documents;

use App\Enums\DocumentVerificationStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Media\MediaStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Pièces typées et versionnées d’un dossier (ouvrier, promoteur, entreprise…) : une nouvelle version remplace les
 * précédentes, qui restent consultables par le Back office avec le statut « remplacé ».
 */
class DocumentStore
{
    public function __construct(private MediaStore $media) {}

    /**
     * Pièces en vigueur du propriétaire (hors versions remplacées), avec leur type et leurs fichiers.
     *
     * @return Collection<int, Document>
     */
    public function active(Model $owner): Collection
    {
        /** @var Collection<int, Document> $documents */
        $documents = $owner->loadMissing(['documents.documentType', 'documents.files'])->getRelation('documents');

        return $documents->reject(fn (Document $document) => $document->verification_status === DocumentVerificationStatus::Remplace)
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Enregistre une pièce (fichiers par face : recto, verso ou page) et marque les pièces remplacées.
     *
     * @param  array<string, UploadedFile>  $files
     * @param  Collection<int, Document>  $replaced
     */
    public function attach(Model $owner, User $author, DocumentType $type, array $files, Collection $replaced, ?string $number = null, ?string $expiresAt = null, string $purpose = 'document'): Document
    {
        /** @var Document $document */
        $document = $owner->documents()->create([
            'document_type_id' => $type->getKey(),
            'label' => $type->label,
            'number_encrypted' => $number,
            'number_last4' => $number ? substr(preg_replace('/\s+/', '', $number), -4) : null,
            'expires_at' => $expiresAt,
            'version' => ($replaced->max('version') ?? 0) + 1,
            'replaces_document_id' => $replaced->sortByDesc('created_at')->first()?->getKey(),
            'verification_status' => DocumentVerificationStatus::EnAttente,
            'uploaded_by_type' => $author->getMorphClass(),
            'uploaded_by_id' => $author->getKey(),
        ]);

        foreach ($files as $side => $file) {
            $media = $this->media->store($file, $purpose, $author, $type->is_sensitive);
            $document->files()->attach($media->getKey(), ['side' => $side, 'page_number' => 1]);
        }

        foreach ($replaced as $previous) {
            $previous->forceFill(['verification_status' => DocumentVerificationStatus::Remplace])->save();
        }

        $owner->unsetRelation('documents');

        return $document->load(['documentType', 'files']);
    }

    /**
     * Pièce d’identité complète : numéro si exigé, et recto et verso pour une pièce recto-verso.
     */
    public function isCompleteIdentity(Document $document, bool $requiresNumber = true): bool
    {
        $type = $document->documentType;
        $sides = $document->files->pluck('pivot.side')->all();

        return $type->is_identity
            && (! $requiresNumber || filled($document->number_last4))
            && ($type->has_back_side ? ! array_diff(['recto', 'verso'], $sides) : $sides !== []);
    }
}
