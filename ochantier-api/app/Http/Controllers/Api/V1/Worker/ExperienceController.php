<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ExperienceResource;
use App\Models\WorkerExperience;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Expériences professionnelles déclarées par l’ouvrier.
 */
class ExperienceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ExperienceResource::collection($request->user()->experiences()->get());
    }

    public function store(Request $request): ExperienceResource
    {
        $experience = $request->user()->experiences()->create($this->validated($request));

        return ExperienceResource::make($experience);
    }

    public function update(Request $request, WorkerExperience $experience): ExperienceResource
    {
        $this->authorizeOwner($request, $experience);
        $experience->update($this->validated($request));

        return ExperienceResource::make($experience);
    }

    public function destroy(Request $request, WorkerExperience $experience): Response
    {
        $this->authorizeOwner($request, $experience);
        $experience->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:160'],
            'mission' => ['required', 'string', 'max:160'],
            'trade' => ['nullable', Rule::in(Catalog::trades())],
            'site' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'started_at' => ['required', 'date', 'before_or_equal:today'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'description' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:160'],
        ]);

        return [...$data, 'trade' => $data['trade'] ?? $request->user()->trade];
    }

    private function authorizeOwner(Request $request, WorkerExperience $experience): void
    {
        abort_unless($experience->worker_id === $request->user()->id, 404);
    }
}
