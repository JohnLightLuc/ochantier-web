<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateWorkerProfileRequest;
use App\Http\Resources\V1\WorkerResource;
use App\Models\Accounts\WorkerAccount;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Profil de l’ouvrier connecté.
 */
class ProfileController extends Controller
{
    public function show(Request $request): WorkerResource
    {
        return WorkerResource::make($request->user()->load('documents', 'experiences'));
    }

    public function update(UpdateWorkerProfileRequest $request): WorkerResource
    {
        /** @var WorkerAccount $worker */
        $worker = $request->user();
        $data = $request->safe()->except('photo');

        foreach (['phone_secondary', 'emergency_phone'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Phone::format($data[$field]);
            }
        }

        if ($request->hasFile('photo')) {
            if ($worker->photo_path) {
                Storage::delete($worker->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store("workers/{$worker->id}");
        }

        $worker->update($data);
        $worker->logActivity(WorkerActivityCategory::Update, 'a modifié son profil', null, null, implode(', ', array_keys($data)), $worker->origin(), $worker->name);

        return WorkerResource::make($worker->load('documents', 'experiences'));
    }

    /**
     * Soumet un profil incomplet ou rejeté à la vérification du Back-office.
     */
    public function submit(Request $request): WorkerResource
    {
        /** @var WorkerAccount $worker */
        $worker = $request->user()->load('documents');

        if (! in_array($worker->status, [WorkerStatus::Incomplete, WorkerStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'Profil déjà soumis à la vérification.']);
        }

        $missing = $worker->documents
            ->filter(fn ($d): bool => in_array($d->type, [WorkerDocumentType::IdFront, WorkerDocumentType::IdBack], true) && $d->status === DocumentStatus::Missing)
            ->map(fn ($d): string => $d->type->label());

        if ($missing->isNotEmpty() || ! $worker->id_number) {
            throw ValidationException::withMessages(['documents' => 'Ajoutez votre pièce d’identité (recto, verso et numéro) avant de soumettre le profil.']);
        }

        $old = $worker->status;
        $worker->update(['status' => WorkerStatus::ToReview, 'rejection' => null]);
        $worker->logActivity(WorkerActivityCategory::Status, 'a soumis son profil pour vérification', $old->label(), WorkerStatus::ToReview->label(), null, $worker->origin(), $worker->name);

        return WorkerResource::make($worker->load('experiences'));
    }

    /**
     * Disponibilité déclarée par l’ouvrier (impossible pendant une affectation ou une mission).
     */
    public function availability(Request $request): WorkerResource
    {
        /** @var WorkerAccount $worker */
        $worker = $request->user();
        $data = $request->validate(['availability' => ['required', Rule::in([WorkerAvailability::Available->value, WorkerAvailability::Unavailable->value])]]);

        if (! in_array($worker->availability, [WorkerAvailability::Available, WorkerAvailability::Unavailable], true)) {
            throw ValidationException::withMessages(['availability' => 'Vous êtes affecté à un chantier : contactez le support pour changer votre disponibilité.']);
        }

        $new = WorkerAvailability::from($data['availability']);
        if ($new !== $worker->availability) {
            $old = $worker->availability;
            $worker->update(['availability' => $new, 'previous_availability' => $old]);
            $worker->logActivity(WorkerActivityCategory::Availability, 'a changé sa disponibilité', $old->label(), $new->label(), null, $worker->origin(), $worker->name);
        }

        return WorkerResource::make($worker);
    }
}
