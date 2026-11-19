<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Envoyer » le devis : signature électronique au nom de l’entrepreneur.
 */
class SendQuoteVersionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['signed' => ['accepted']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['signed.accepted' => 'Signez le devis avant de l’envoyer.'];
    }
}
