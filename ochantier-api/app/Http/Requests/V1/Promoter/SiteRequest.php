<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\SiteWorkType;
use App\Models\Site;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\SiteManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * « Nouveau chantier » et « Modifier le chantier » : informations, localisation, calendrier et responsable.
 * À la création, seul le titre est obligatoire (brouillon) ; en modification, seuls les champs envoyés sont enregistrés.
 */
class SiteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $communeId = $this->input('commune_id', $this->site()?->commune_id);

        return [
            'client_uuid' => [$creating ? 'nullable' : 'prohibited', 'uuid'],
            'lock_version' => ['sometimes', 'integer'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'internal_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'work_type' => ['sometimes', 'nullable', Rule::enum(SiteWorkType::class)],
            'construction_type_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('construction_types', 'id')->where('is_active', true)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commune_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'neighborhood_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $communeId)],
            'access_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'location' => ['sometimes', 'nullable', 'array'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'planned_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'planned_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'responsible_user_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start = $this->input('planned_start_date', $this->site()?->planned_start_date?->format('Y-m-d'));
                $end = $this->input('planned_end_date', $this->site()?->planned_end_date?->format('Y-m-d'));

                if ($start && $end && ! $validator->errors()->hasAny(['planned_start_date', 'planned_end_date']) && CarbonImmutable::parse($end)->lessThan(CarbonImmutable::parse($start))) {
                    $validator->errors()->add('planned_end_date', 'La date de fin doit suivre la date de début.');
                }

                $responsible = $this->input('responsible_user_id');

                if ($responsible && ! app(SiteManager::class)->isAllowedResponsible(app(PromoterAccess::class)->profile($this->user()), $responsible)) {
                    $validator->errors()->add('responsible_user_id', 'Choisissez vous-même ou un utilisateur de votre équipe.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Donnez au moins un titre au brouillon.',
            'title.min' => 'Donnez un titre au chantier.',
            'planned_start_date.after_or_equal' => 'La date de début ne peut pas être passée.',
            'planned_start_date.date_format' => 'Date attendue au format AAAA-MM-JJ.',
            'planned_end_date.date_format' => 'Date attendue au format AAAA-MM-JJ.',
            'neighborhood_id.exists' => 'Ce quartier n’appartient pas à la commune choisie.',
            'location.latitude.between' => 'Position GPS invalide.',
            'location.longitude.between' => 'Position GPS invalide.',
            'lock_version.integer' => 'Version invalide.',
        ];
    }

    private function site(): ?Site
    {
        $site = $this->route('site');

        return $site instanceof Site ? $site : null;
    }
}
