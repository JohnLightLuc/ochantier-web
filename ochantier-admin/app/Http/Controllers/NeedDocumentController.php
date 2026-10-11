<?php

namespace App\Http\Controllers;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedDocumentStatus;
use App\Models\Need;
use App\Models\NeedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents et médias d’un besoin : dépôt, validation, suppression et téléchargement.
 */
class NeedDocumentController extends Controller
{
    /**
     * Dépose un document ; un document manquant du même type est complété. Le plan du chantier lève l’anomalie correspondante.
     */
    public function store(Request $request, Need $need): RedirectResponse
    {
        abort_if($need->isReadOnly(), 403, 'Besoin en lecture seule.');

        $data = $request->validate([
            'type' => ['required', Rule::in(NeedDocument::TYPES)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,zip', 'max:10240'],
        ], [
            'file.required' => 'Ajoutez un fichier (PDF, JPG, PNG ou ZIP).',
            'file.mimes' => 'Formats acceptés : PDF, JPG, PNG ou ZIP.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $file = $request->file('file');
        $document = $need->documents()->where('type', $data['type'])->where('status', NeedDocumentStatus::Missing)->first() ?? $need->documents()->make(['type' => $data['type']]);
        if ($document->file_path) {
            Storage::delete($document->file_path);
        }
        $document->fill([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store("needs/{$need->id}/documents"),
            'added_on' => Need::referenceDate(),
            'author' => $request->user()?->name ?? 'Système',
            'status' => NeedDocumentStatus::ToReview,
        ])->save();

        if ($data['type'] === 'Plan du chantier') {
            $need->update(['anomalies' => array_values(array_filter($need->anomalies, fn (string $a): bool => ! str_contains($a, 'Plan')))]);
        }
        $need->logActivity(NeedActivityCategory::Document, 'a ajouté un document', null, $data['type'], $document->file_name);
        Inertia::flash('toast', 'Document ajouté.');

        return back();
    }

    public function approve(Need $need, NeedDocument $document): RedirectResponse
    {
        abort_unless($document->status === NeedDocumentStatus::ToReview, 422, 'Ce document ne peut pas être validé.');

        $document->update(['status' => NeedDocumentStatus::Validated]);
        $need->logActivity(NeedActivityCategory::Document, 'a validé un document', NeedDocumentStatus::ToReview->label(), NeedDocumentStatus::Validated->label(), $document->file_name);
        Inertia::flash('toast', 'Document validé.');

        return back();
    }

    /**
     * Seul l’auteur d’un document peut le supprimer.
     */
    public function destroy(Request $request, Need $need, NeedDocument $document): RedirectResponse
    {
        abort_if($need->isReadOnly() || $document->status === NeedDocumentStatus::Missing, 422, 'Ce document ne peut pas être supprimé.');
        abort_unless($document->author === $request->user()?->name, 403, 'Seul l’auteur du document peut le supprimer.');

        if ($document->file_path) {
            Storage::delete($document->file_path);
        }
        $document->delete();
        $need->logActivity(NeedActivityCategory::Document, 'a supprimé un document', $document->type, null, $document->file_name);
        Inertia::flash('toast', 'Document supprimé.');

        return back();
    }

    /**
     * Fichier déposé, ou fiche texte pour les documents de démonstration.
     */
    public function download(Need $need, NeedDocument $document): Response
    {
        abort_if($document->status === NeedDocumentStatus::Missing, 404);

        if ($document->file_path && Storage::exists($document->file_path)) {
            return Storage::download($document->file_path, $document->file_name ?? basename($document->file_path));
        }

        $name = Str::slug($document->type).'-'.$need->slug.'.txt';

        return response("{$document->type} · {$need->reference}\nFichier : {$document->file_name}\nAuteur : {$document->author}\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }
}
