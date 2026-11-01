<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Message libre envoyé au promoteur (centre de notifications et push).
 */
class PromoterMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Indiquez l’objet du message.',
            'body.required' => 'Saisissez le message.',
        ];
    }
}
