<?php

namespace App\Http\Requests\V1\Admin\Worker;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspension d’un ouvrier : motif obligatoire, date de fin facultative (réactivation automatique).
 */
class WorkerSuspensionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'until' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif.',
            'reason.min' => 'Le motif est trop court.',
            'until.after' => 'La date de fin doit être postérieure à aujourd’hui.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }

    /**
     * Fin de la suspension : début de la journée choisie.
     */
    public function until(): ?CarbonImmutable
    {
        return $this->filled('until') ? CarbonImmutable::parse($this->input('until'), config('app.timezone'))->startOfDay() : null;
    }
}
