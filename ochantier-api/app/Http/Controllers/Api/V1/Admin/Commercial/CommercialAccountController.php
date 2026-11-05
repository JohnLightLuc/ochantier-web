<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Enums\CommercialProfileStatus;
use App\Enums\DocumentAudience;
use App\Enums\DocumentVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Commercial\CommercialDocumentRequest;
use App\Http\Requests\V1\Admin\Commercial\CommercialFormRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\CommercialDetailResource;
use App\Http\Resources\V1\AuthSessionResource;
use App\Models\AuthSession;
use App\Models\City;
use App\Models\CommercialProfile;
use App\Models\Document;
use App\Models\DocumentType;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\CommercialActivation;
use App\Support\Admin\CommercialAdministration;
use App\Support\ApiResponse;
use App\Support\Auth\AuthSessions;
use App\Support\Documents\DocumentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d’un commercial, pièces de son dossier, lien d’accès et sessions. Les décisions renvoient
 * la fiche à jour.
 */
class CommercialAccountController extends Controller
{
    public function __construct(private CommercialAdministration $administration, private CommercialActivation $activation, private AuthSessions $sessions) {}

    /**
     * Listes du formulaire : superviseurs possibles, villes et communes, types de pièces.
     */
    public function formOptions(): JsonResponse
    {
        return ApiResponse::success([
            'supervisors' => CommercialProfile::query()->where('status', CommercialProfileStatus::Actif)->with('user')->get()
                ->map(fn (CommercialProfile $commercial) => ['id' => $commercial->id, 'full_name' => trim($commercial->user->first_name.' '.$commercial->user->last_name)])
                ->sortBy('full_name')->values(),
            'cities' => City::query()->where('is_active', true)
                ->with(['communes' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderByRaw("name <> 'Abidjan'")->orderBy('name')->get()
                ->map(fn (City $city) => ['id' => $city->id, 'name' => $city->name, 'communes' => $city->communes->map->only(['id', 'name'])->values()]),
            'document_types' => DocumentType::query()->where('audience', DocumentAudience::Commercial)->where('is_active', true)->orderBy('created_at')->get()
                ->map->only(['code', 'label', 'is_identity', 'is_required', 'has_back_side', 'allowed_mime', 'max_size_kb'])->values(),
        ]);
    }

    public function store(CommercialFormRequest $request): JsonResponse
    {
        $commercial = $this->administration->create($request->validated(), $request->phoneNumber(), $request->user(), $request);

        if ($request->boolean('send_link', true)) {
            $this->activation->send($commercial, 'en_attente_activation', $request->user(), $request);
        }

        return ApiResponse::success($this->fiche($request, $commercial->id), 'Commercial créé.', 201);
    }

    public function update(CommercialFormRequest $request, string $commercial): JsonResponse
    {
        $model = $this->editable($commercial);
        $this->administration->update($model, $request->validated(), $request->phoneNumber(), $request->user(), $request);

        return ApiResponse::success($this->fiche($request, $commercial), 'Modifications enregistrées.');
    }

    public function storeDocument(CommercialDocumentRequest $request, string $commercial): JsonResponse
    {
        $document = $this->administration->attachDocument($this->editable($commercial), $request->documentType(), $request->sides(), $request->input('number'), $request->input('expires_at'), $request->user(), $request);

        return ApiResponse::success($this->fiche($request, $commercial), $document->documentType->label.' ajoutée au dossier.', 201);
    }

    public function approveDocument(Request $request, string $commercial, string $document): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->administration->decideDocument($model, $this->document($model, $document), $request->user(), DocumentVerificationStatus::Valide, null, $request);

        return ApiResponse::success($this->fiche($request, $commercial), 'Pièce vérifiée.');
    }

    public function rejectDocument(PromoterReasonRequest $request, string $commercial, string $document): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->administration->decideDocument($model, $this->document($model, $document), $request->user(), DocumentVerificationStatus::Rejete, $request->reason(), $request);

        return ApiResponse::success($this->fiche($request, $commercial), 'Pièce rejetée.');
    }

    /**
     * Envoie un nouveau lien : activation, ou réinitialisation pour un compte actif (ses sessions sont fermées).
     */
    public function sendLink(Request $request, string $commercial): JsonResponse
    {
        $model = CommercialController::find($commercial);
        $this->activation->send($model, $model->account_status, $request->user(), $request);

        return ApiResponse::success($this->fiche($request, $commercial), 'Lien envoyé, valable '.CommercialActivation::VALID_HOURS.' heures.');
    }

    public function revokeLink(Request $request, string $commercial): JsonResponse
    {
        $this->activation->revoke(CommercialController::find($commercial), $request->user(), $request);

        return ApiResponse::success($this->fiche($request, $commercial), 'Lien révoqué.');
    }

    /**
     * Onglet Accès et sécurité : sessions ouvertes et état de la connexion.
     */
    public function access(Request $request, string $commercial): JsonResponse
    {
        $user = CommercialController::find($commercial)->user;

        return ApiResponse::success([
            'password_set' => $user->password_hash !== null && ! $user->must_change_password,
            'must_change_password' => $user->must_change_password,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'failed_login_count' => (int) $user->failed_login_count,
            'locked_until' => $user->isLocked() ? $user->locked_until->toIso8601String() : null,
            'sessions' => AuthSessionResource::collection($this->openSessions($user->id)->latest('last_activity_at')->get())->resolve($request),
        ]);
    }

    /**
     * Ferme une session, ou toutes sans identifiant.
     */
    public function revokeSessions(Request $request, string $commercial, ?string $session = null): JsonResponse
    {
        $model = CommercialController::find($commercial);

        if ($session) {
            $this->sessions->revoke($this->openSessions($model->user_id)->whereKey($session)->firstOrFail(), 'revoked_by_admin');
        } else {
            $this->sessions->revokeAll($model->user, 'revoked_by_admin');
        }

        AdminAudit::record($request->user(), 'commercials', 'commercial.sessions_revoked', $request, $model, null, array_filter(['session_id' => $session]));

        return ApiResponse::success(message: $session ? 'Session fermée.' : 'Toutes les sessions sont fermées.');
    }

    /**
     * @return Builder<AuthSession>
     */
    private function openSessions(string $userId)
    {
        return AuthSession::query()->where('authenticatable_type', 'user')->where('authenticatable_id', $userId)->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * Un contrat terminé ne se modifie plus (la fiche reste consultable).
     */
    private function editable(string $id): CommercialProfile
    {
        $commercial = CommercialController::find($id);

        if ($commercial->account_status === 'termine') {
            throw ValidationException::withMessages(['commercial' => 'Ce contrat est terminé : la fiche ne peut plus être modifiée.']);
        }

        return $commercial;
    }

    private function document(CommercialProfile $commercial, string $id): Document
    {
        $document = app(DocumentStore::class)->active($commercial)->firstWhere('id', $id);
        abort_unless($document, 404);

        if ($document->verification_status !== DocumentVerificationStatus::EnAttente) {
            throw ValidationException::withMessages(['commercial' => 'Cette pièce a déjà été contrôlée.']);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function fiche(Request $request, string $id): array
    {
        return CommercialDetailResource::make(CommercialController::find($id))->resolve($request);
    }
}
