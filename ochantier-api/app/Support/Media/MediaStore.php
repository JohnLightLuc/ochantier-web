<?php

namespace App\Support\Media;

use App\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fichiers envoyés par les applications : stockage privé (disque « filesystems.media »), empreinte SHA-256
 * et lien de consultation signé à durée limitée.
 */
class MediaStore
{
    /**
     * Durée de validité d’un lien de consultation, en minutes.
     */
    public const LINK_MINUTES = 30;

    public function store(UploadedFile $file, string $purpose, Model $uploadedBy, bool $sensitive = true): MediaFile
    {
        $disk = (string) config('filesystems.media');
        $path = $file->storeAs(
            $purpose.'/'.now()->format('Y/m'),
            Str::uuid7().'.'.($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin'),
            $disk,
        );
        [$width, $height] = str_starts_with((string) $file->getMimeType(), 'image/') ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];

        return MediaFile::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'width' => $width,
            'height' => $height,
            'purpose' => $purpose,
            'is_sensitive' => $sensitive,
            'uploaded_by_type' => $uploadedBy->getMorphClass(),
            'uploaded_by_id' => $uploadedBy->getKey(),
        ]);
    }

    /**
     * Enregistre un fichier produit par l’API (reçu PDF…).
     */
    public function storeContent(string $content, string $name, string $mimeType, string $purpose, ?Model $createdBy = null, bool $sensitive = false): MediaFile
    {
        $disk = (string) config('filesystems.media');
        $path = $purpose.'/'.now()->format('Y/m').'/'.Str::uuid7().'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk($disk)->put($path, $content);

        return MediaFile::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'purpose' => $purpose,
            'is_sensitive' => $sensitive,
            'uploaded_by_type' => $createdBy?->getMorphClass() ?? 'systeme',
            'uploaded_by_id' => $createdBy?->getKey(),
        ]);
    }

    /**
     * Lien de consultation signé, valable LINK_MINUTES minutes.
     */
    public function temporaryUrl(MediaFile $media): string
    {
        return URL::temporarySignedRoute('v1.media.show', now()->addMinutes(self::LINK_MINUTES), ['media' => $media->getKey()]);
    }

    public function response(MediaFile $media): StreamedResponse
    {
        return Storage::disk($media->disk)->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age='.(self::LINK_MINUTES * 60),
        ]);
    }
}
