<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use App\Models\WorkerDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pièces justificatives : dépôt, validation, rejet, commentaire interne et téléchargement.
 */
class WorkerDocumentController extends Controller
{
    public const REJECTION_REASONS = ['Document illisible', 'Document expiré', 'Incohérence d’identité', 'Mauvais document', 'Doublon potentiel', 'Autre'];

    public const REJECTION_REQUESTS = ['Déposer une nouvelle photo nette', 'Fournir un document à jour', 'Contacter le commercial référent', 'Se présenter en agence'];

    /**
     * Dépose ou remplace une pièce ; elle passe « À vérifier ».
     */
    public function store(Request $request, Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $data = $request->validate([
            'type' => ['required', Rule::enum(WorkerDocumentType::class)],
            'reference' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'reference.required' => 'La référence est obligatoire.',
            'file.required' => 'Ajoutez un fichier (PDF, JPG ou PNG).',
            'file.mimes' => 'Formats acceptés : PDF, JPG ou PNG.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $type = WorkerDocumentType::from($data['type']);
        $document = $worker->documents()->firstOrNew(['type' => $type]);
        $old = $document->exists ? $document->status->label() : null;

        if ($document->file_path) {
            Storage::delete($document->file_path);
        }

        $file = $request->file('file');
        $document->fill([
            'reference' => trim($data['reference']),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store("workers/{$worker->id}/documents"),
            'added_at' => today(),
            'expires_at' => $data['expires_at'] ?? $document->expires_at,
            'status' => DocumentStatus::ToReview,
            'rejection_reason' => null,
        ])->save();

        $worker->logActivity(WorkerActivityCategory::Update, 'a déposé un document', $old, DocumentStatus::ToReview->label(), $type->label());
        Inertia::flash('toast', 'Document enregistré · en attente de vérification.');

        return back();
    }

    public function approve(Worker $worker, WorkerDocument $document): RedirectResponse
    {
        abort_unless($document->status === DocumentStatus::ToReview, 422, 'Ce document ne peut pas être validé.');

        $document->update(['status' => DocumentStatus::Verified, 'rejection_reason' => null]);
        if ($worker->status === WorkerStatus::ToReview) {
            $worker->update(['status' => WorkerStatus::InReview]);
        }
        $worker->logActivity(WorkerActivityCategory::Validation, 'a validé un document', DocumentStatus::ToReview->label(), DocumentStatus::Verified->label(), $document->type->label());

        Inertia::flash('toast', 'Document validé.');

        return back();
    }

    public function reject(Request $request, Worker $worker, WorkerDocument $document): RedirectResponse
    {
        abort_unless(in_array($document->status, [DocumentStatus::ToReview, DocumentStatus::Verified], true), 422, 'Ce document ne peut pas être rejeté.');

        $data = $request->validate([
            'reason' => ['required', Rule::in(self::REJECTION_REASONS)],
            'request' => ['required', Rule::in(self::REJECTION_REQUESTS)],
            'comment' => ['required', 'string', 'min:10', 'max:500'],
        ], ['comment.*' => 'Ce champ est obligatoire (10 caractères minimum).']);

        $old = $document->status->label();
        $document->update(['status' => DocumentStatus::Rejected, 'rejection_reason' => "{$data['reason']} — ".trim($data['comment'])." (action : {$data['request']})"]);
        $worker->logActivity(WorkerActivityCategory::Rejection, 'a rejeté un document', $old, DocumentStatus::Rejected->label(), "{$document->type->label()} · {$data['reason']}");

        Inertia::flash('toast', 'Document rejeté · notification envoyée.');

        return back();
    }

    /**
     * Commentaire interne, visible uniquement par l’administration.
     */
    public function comment(Request $request, Worker $worker, WorkerDocument $document): RedirectResponse
    {
        $comment = trim($request->validate(['comment' => ['required', 'string', 'min:3', 'max:500']], ['comment.*' => 'Ce champ est obligatoire (3 caractères minimum).'])['comment']);

        $document->update(['internal_comment' => $comment]);
        $worker->logActivity(WorkerActivityCategory::Update, 'a commenté un document', null, null, "{$document->type->label()} : {$comment}");

        Inertia::flash('toast', 'Commentaire enregistré.');

        return back();
    }

    /**
     * Fichier déposé, ou fiche texte pour les pièces de démonstration sans fichier.
     */
    public function download(Worker $worker, WorkerDocument $document): Response
    {
        abort_unless($document->hasFile(), 404);

        if ($document->file_path && Storage::exists($document->file_path)) {
            return Storage::download($document->file_path, $document->file_name ?? basename($document->file_path));
        }

        $name = Str::slug($document->type->label()).'-'.$worker->slug.'.txt';
        $content = "{$document->type->label()}\nRéférence : {$document->reference}\nOuvrier : {$worker->name} ({$worker->code})\nStatut : {$document->status->label()}\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }
}
