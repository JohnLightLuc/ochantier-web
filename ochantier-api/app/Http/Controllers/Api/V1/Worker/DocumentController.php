<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Accounts\WorkerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

/**
 * Pièces justificatives de l’ouvrier : chaque envoi repasse la pièce « à vérifier » par le Back-office.
 */
class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DocumentResource::collection($request->user()->documents()->orderBy('id')->get());
    }

    public function store(Request $request, string $type): DocumentResource
    {
        $type = WorkerDocumentType::tryFrom($type) ?? abort(404, 'Type de pièce inconnu.');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'reference' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        /** @var WorkerAccount $worker */
        $worker = $request->user();
        $file = $request->file('file');
        $document = $worker->documents()->firstOrNew(['type' => $type]);

        if ($document->file_path) {
            Storage::delete($document->file_path);
        }

        $document->fill([
            'reference' => $data['reference'] ?? $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store("workers/{$worker->id}/documents"),
            'added_at' => today(),
            'expires_at' => $data['expires_at'] ?? null,
            'status' => DocumentStatus::ToReview,
            'rejection_reason' => null,
        ])->save();

        $worker->logActivity(WorkerActivityCategory::Update, 'a ajouté une pièce', null, $type->label(), null, $worker->origin(), $worker->name);

        return DocumentResource::make($document);
    }
}
