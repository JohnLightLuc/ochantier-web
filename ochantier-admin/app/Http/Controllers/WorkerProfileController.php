<?php

namespace App\Http\Controllers;

use App\Enums\WorkerActivityCategory;
use App\Models\Worker;
use App\Models\WorkerExperience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Métiers secondaires, spécialités, compétences, certifications et expériences.
 */
class WorkerProfileController extends Controller
{
    /**
     * Listes modifiables depuis la fiche : clé d’URL → [attribut, libellé].
     */
    private const LISTS = [
        'metiers-secondaires' => ['secondary_trades', 'Métiers secondaires'],
        'specialites' => ['specialties', 'Spécialités'],
        'competences' => ['skills', 'Compétences'],
        'certifications' => ['certifications', 'Certifications'],
    ];

    public function addValue(Request $request, Worker $worker, string $list): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);
        [$attribute, $label] = self::LISTS[$list] ?? abort(404);

        $rules = $attribute === 'secondary_trades'
            ? ['required', Rule::in(array_diff(config('admin.trades'), [$worker->trade]))]
            : ['required', 'string', 'max:120'];
        $value = trim($request->validate(['value' => $rules], ['value.required' => 'Ce champ est obligatoire.'])['value']);

        if (collect($worker->{$attribute})->contains(fn (string $v): bool => mb_strtolower($v) === mb_strtolower($value))) {
            throw ValidationException::withMessages(['value' => 'Cette valeur existe déjà.']);
        }

        $worker->update([$attribute => [...$worker->{$attribute}, $value]]);
        $worker->logActivity(WorkerActivityCategory::Update, 'a ajouté une valeur', null, $value, $label);
        Inertia::flash('toast', "« {$value} » ajouté.");

        return back();
    }

    public function removeValue(Request $request, Worker $worker, string $list): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);
        [$attribute, $label] = self::LISTS[$list] ?? abort(404);
        $value = $request->validate(['value' => ['required', 'string']])['value'];

        $worker->update([$attribute => array_values(array_filter($worker->{$attribute}, fn (string $v): bool => $v !== $value))]);
        $worker->logActivity(WorkerActivityCategory::Update, 'a supprimé une valeur', $value, null, $label);
        Inertia::flash('toast', "« {$value} » supprimé.");

        return back();
    }

    public function storeExperience(Request $request, Worker $worker): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $data = $this->validateExperience($request);
        $worker->experiences()->create($data);
        $worker->logActivity(WorkerActivityCategory::Update, 'a ajouté une expérience', null, $data['mission'], $data['company']);
        Inertia::flash('toast', 'Expérience enregistrée.');

        return back();
    }

    public function updateExperience(Request $request, Worker $worker, WorkerExperience $experience): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $data = $this->validateExperience($request);
        $experience->update($data);
        $worker->logActivity(WorkerActivityCategory::Update, 'a modifié une expérience', null, $data['mission'], $data['company']);
        Inertia::flash('toast', 'Expérience enregistrée.');

        return back();
    }

    public function destroyExperience(Worker $worker, WorkerExperience $experience): RedirectResponse
    {
        abort_if($worker->isArchived(), 403);

        $experience->delete();
        $worker->logActivity(WorkerActivityCategory::Update, 'a supprimé une expérience', $experience->mission, null, $experience->company);
        Inertia::flash('toast', 'Expérience supprimée.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateExperience(Request $request): array
    {
        return $request->validate([
            'company' => ['required', 'string', 'max:160'],
            'mission' => ['required', 'string', 'max:160'],
            'trade' => ['required', Rule::in(config('admin.trades'))],
            'site' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:160'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            '*.required' => 'Ce champ est obligatoire.',
            'ended_at.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ]);
    }
}
