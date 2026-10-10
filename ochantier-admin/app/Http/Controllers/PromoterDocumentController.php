<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Requests\RejectPromoterDocumentRequest;
use App\Http\Requests\StorePromoterDocumentRequest;
use App\Models\Promoter;
use App\Models\PromoterDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dossier KYC : dépôt, validation, rejet et téléchargement des pièces.
 */
class PromoterDocumentController extends Controller
{
    /**
     * Dépose (ou remplace) une pièce ; elle passe « À vérifier ».
     */
    public function store(StorePromoterDocumentRequest $request, Promoter $promoter): RedirectResponse
    {
        $type = DocumentType::from($request->validated('type'));
        $document = $promoter->documents()->firstOrNew(['type' => $type]);

        if ($document->file_path) {
            Storage::delete($document->file_path);
        }

        $document->fill([
            'reference' => trim($request->validated('reference')),
            'added_at' => today(),
            'expires_at' => $request->validated('expires_at'),
            'status' => DocumentStatus::ToReview,
            'rejection_reason' => null,
            'file_path' => $request->file('file')->store("kyc/{$promoter->id}"),
        ])->save();

        $promoter->logActivity('a ajouté un document KYC', $type->label(), 'upload_file', 'info', "/promoteurs/{$promoter->slug}/kyc/{$document->id}");

        Inertia::flash('toast', 'Document ajouté · en attente de vérification.');

        return back();
    }

    public function approve(Promoter $promoter, PromoterDocument $document): RedirectResponse
    {
        abort_unless($document->canBeValidated(), 422, 'Ce document ne peut pas être validé.');

        $document->update(['status' => DocumentStatus::Verified, 'rejection_reason' => null]);
        $promoter->logActivity('a validé un document KYC', $document->type->label(), 'verified', 'success', "/promoteurs/{$promoter->slug}/kyc/{$document->id}");

        Inertia::flash('toast', 'Document validé.');

        return back();
    }

    public function reject(RejectPromoterDocumentRequest $request, Promoter $promoter, PromoterDocument $document): RedirectResponse
    {
        abort_unless($document->canBeRejected(), 422, 'Ce document ne peut pas être rejeté.');

        $document->update([
            'status' => DocumentStatus::Rejected,
            'rejection_reason' => $request->validated('category').' — '.trim($request->validated('reason')),
        ]);
        $promoter->logActivity('a rejeté un document KYC', $document->type->label(), 'cancel', 'danger', "/promoteurs/{$promoter->slug}/kyc/{$document->id}");

        Inertia::flash('toast', 'Document rejeté · le promoteur a été notifié.');

        return back();
    }

    /**
     * Fichier déposé, ou fiche texte pour les pièces de démonstration sans fichier.
     */
    public function download(Promoter $promoter, PromoterDocument $document): Response
    {
        abort_unless($document->hasFile(), 404);

        $name = Str::slug($document->type->label()).'-'.$promoter->slug;

        if ($document->file_path && Storage::exists($document->file_path)) {
            return Storage::download($document->file_path, $name.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION));
        }

        $content = "{$document->type->label()}\nRéférence : {$document->reference}\nPromoteur : {$promoter->name}\nStatut : {$document->status->label()}\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}.txt\"",
        ]);
    }
}
