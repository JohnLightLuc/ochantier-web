<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Enums\AvailabilityType;
use App\Enums\WorkerActivityCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PeriodResource;
use App\Models\Accounts\WorkerAccount;
use App\Models\WorkerPeriod;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Calendrier de l’ouvrier : périodes de disponibilité, de repos ou d’absence déclarées à l’avance.
 */
class PeriodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return PeriodResource::collection($request->user()->periods()
            ->when($request->date('from'), fn ($q, $from) => $q->where('ends_on', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('starts_on', '<=', $to))
            ->get());
    }

    public function store(Request $request): PeriodResource
    {
        /** @var WorkerAccount $worker */
        $worker = $request->user();
        $period = $worker->periods()->create($this->validated($request));
        $worker->logActivity(WorkerActivityCategory::Unavailability, 'a déclaré une période', null, $period->type->label(), $period->starts_on->format('d/m/Y').' → '.$period->ends_on->format('d/m/Y'), $worker->origin(), $worker->name);

        return PeriodResource::make($period);
    }

    public function update(Request $request, WorkerPeriod $period): PeriodResource
    {
        $this->authorizeOwner($request, $period);
        $period->update($this->validated($request, $period));

        return PeriodResource::make($period);
    }

    public function destroy(Request $request, WorkerPeriod $period): Response
    {
        $this->authorizeOwner($request, $period);
        $period->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WorkerPeriod $current = null): array
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'type' => ['required', Rule::enum(AvailabilityType::class)],
            'reason' => ['nullable', 'string', 'max:160'],
            'zone' => ['nullable', Rule::in(Catalog::COMMUNES)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $overlaps = $request->user()->periods()
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->where('starts_on', '<=', $data['ends_on'])
            ->where('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => 'Cette période chevauche une période déjà déclarée.']);
        }

        return $data;
    }

    private function authorizeOwner(Request $request, WorkerPeriod $period): void
    {
        abort_unless($period->worker_id === $request->user()->id, 404);
    }
}
