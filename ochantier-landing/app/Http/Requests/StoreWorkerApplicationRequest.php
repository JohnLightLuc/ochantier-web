<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'tel' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'naissance' => ['nullable', 'date', 'before:today'],
            'genre' => ['nullable', 'in:Homme,Femme'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'cni' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
            'metier' => ['required', 'string', 'max:60'],
            'tarif' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'exp' => ['required', 'integer', 'min:0', 'max:30'],
            'level' => ['required', 'in:Apprenti,Confirmé,Expert,Chef d’équipe'],
            'sec' => ['nullable', 'array'],
            'sec.*' => ['string', 'max:60'],
            'cert' => ['nullable', 'array'],
            'cert.*' => ['string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'commune' => ['required', 'string', 'max:80'],
            'quartier' => ['nullable', 'string', 'max:120'],
            'radius' => ['required', 'string', 'max:40'],
            'dispo' => ['required', 'in:Immédiate,Sous 1 semaine,Sous 1 mois'],
            'days' => ['nullable', 'array'],
            'days.*' => ['in:Lun,Mar,Mer,Jeu,Ven,Sam,Dim'],
            'pay' => ['required', 'in:Orange Money,MTN MoMo,Moov Money,Wave,Virement'],
            'paynum' => ['nullable', 'string', 'max:30'],
            'chantier' => ['nullable', 'string', 'max:120'],
            'chantier_commune' => ['nullable', 'string', 'max:80'],
            'consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'prenom.required' => 'Renseignez votre prénom.',
            'nom.required' => 'Renseignez votre nom.',
            'tel.required' => 'Renseignez votre téléphone.',
            'cni.required' => 'Ajoutez une photo de votre pièce d’identité.',
            'cni.mimes' => 'La pièce d’identité doit être une image ou un PDF.',
            'cni.max' => 'La pièce d’identité ne doit pas dépasser 8 Mo.',
            'photo.image' => 'La photo de profil doit être une image.',
            'photo.max' => 'La photo de profil ne doit pas dépasser 5 Mo.',
            'metier.required' => 'Choisissez votre spécialité principale.',
            'commune.required' => 'Indiquez votre commune ou ville.',
            'consent.accepted' => 'Acceptez les conditions pour envoyer votre profil.',
        ];
    }
}
