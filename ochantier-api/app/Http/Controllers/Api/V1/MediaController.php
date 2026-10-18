<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Support\Media\MediaStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation d’un fichier par lien signé (middleware « signed ») : l’URL fournie par l’API suffit, sans jeton,
 * pour l’afficher dans une image ou un lecteur PDF.
 */
class MediaController extends Controller
{
    public function __invoke(MediaFile $media, MediaStore $store): StreamedResponse
    {
        return $store->response($media);
    }
}
