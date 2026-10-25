<?php

namespace App\Http\Requests\V1\Promoter;

use App\Models\Rating;
use App\Support\Promoter\SiteRatings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Feuille « Noter… » : note de 1 à 5, satisfaction proposée pour cette note et commentaire facultatif. À la création,
 * scope (individuel avec assignment_id, ou equipe) ; à la modification, le scope est celui de l’avis.
 */
class RatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rating = $this->route('rating');
        $scope = $rating instanceof Rating ? $rating->rating_scope->value : $this->input('scope');
        $tags = SiteRatings::TAGS[$scope][(int) $this->input('score')] ?? [];

        return [
            ...($rating instanceof Rating ? [] : [
                'scope' => ['required', Rule::in(array_keys(SiteRatings::TAGS))],
                'assignment_id' => ['exclude_unless:scope,individuel', 'required', 'uuid'],
            ]),
            'score' => ['required', 'integer', 'between:1,5'],
            'satisfaction_tag' => ['required', 'string', Rule::in($tags)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.required' => 'Précisez si vous notez un ouvrier ou l’équipe.',
            'scope.in' => 'Précisez si vous notez un ouvrier ou l’équipe.',
            'assignment_id.required' => 'Choisissez l’ouvrier à noter.',
            'assignment_id.uuid' => 'Ouvrier introuvable.',
            'score.required' => 'Choisissez une note.',
            'score.integer' => 'Choisissez une note de 1 à 5.',
            'score.between' => 'Choisissez une note de 1 à 5.',
            'satisfaction_tag.required' => 'Sélectionnez une satisfaction.',
            'satisfaction_tag.in' => 'Sélectionnez une satisfaction proposée pour cette note.',
            'comment.max' => 'Le commentaire ne doit pas dépasser 1000 caractères.',
        ];
    }
}
