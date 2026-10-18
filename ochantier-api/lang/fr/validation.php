<?php

/*
|--------------------------------------------------------------------------
| Messages de validation
|--------------------------------------------------------------------------
|
| Messages par défaut des règles utilisées par l’API. Une requête peut les
| remplacer par des messages propres à son écran.
|
*/

return [
    'accepted' => 'Ce champ doit être accepté.',
    'after' => 'La date doit être postérieure au :date.',
    'after_or_equal' => 'La date doit être postérieure ou égale au :date.',
    'array' => 'Ce champ doit être une liste.',
    'before' => 'La date doit être antérieure au :date.',
    'before_or_equal' => 'La date doit être antérieure ou égale au :date.',
    'between' => [
        'array' => 'Ce champ doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier doit peser entre :min et :max Ko.',
        'numeric' => 'La valeur doit être comprise entre :min et :max.',
        'string' => 'Ce champ doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Ce champ doit valoir vrai ou faux.',
    'confirmed' => 'La confirmation ne correspond pas.',
    'date' => 'Saisissez une date valide.',
    'date_format' => 'La date doit être au format :format.',
    'different' => 'Ce champ doit être différent de :other.',
    'digits' => 'Saisissez :digits chiffres.',
    'digits_between' => 'Saisissez entre :min et :max chiffres.',
    'distinct' => 'Cette valeur est en double.',
    'email' => 'Saisissez une adresse e-mail valide.',
    'enum' => 'Valeur non autorisée.',
    'exists' => 'Cette valeur n’existe pas ou n’est plus disponible.',
    'file' => 'Ce champ doit être un fichier.',
    'filled' => 'Ce champ ne peut pas être vide.',
    'image' => 'Le fichier doit être une image.',
    'in' => 'Valeur non autorisée.',
    'integer' => 'Saisissez un nombre entier.',
    'list' => 'Ce champ doit être une liste.',
    'max' => [
        'array' => 'Ce champ ne peut pas contenir plus de :max éléments.',
        'file' => 'Le fichier ne doit pas dépasser :max Ko.',
        'numeric' => 'La valeur ne doit pas dépasser :max.',
        'string' => 'Ce champ ne doit pas dépasser :max caractères.',
    ],
    'mimes' => 'Formats acceptés : :values.',
    'mimetypes' => 'Formats acceptés : :values.',
    'min' => [
        'array' => 'Ce champ doit contenir au moins :min éléments.',
        'file' => 'Le fichier doit peser au moins :min Ko.',
        'numeric' => 'La valeur doit être au moins égale à :min.',
        'string' => 'Ce champ doit contenir au moins :min caractères.',
    ],
    'numeric' => 'Saisissez un nombre.',
    'present' => 'Ce champ doit être présent.',
    'prohibited' => 'Ce champ n’est pas autorisé.',
    'regex' => 'Le format est invalide.',
    'required' => 'Ce champ est obligatoire.',
    'required_if' => 'Ce champ est obligatoire.',
    'required_with' => 'Ce champ est obligatoire.',
    'required_without' => 'Ce champ est obligatoire.',
    'size' => [
        'array' => 'Ce champ doit contenir :size éléments.',
        'file' => 'Le fichier doit peser :size Ko.',
        'numeric' => 'La valeur doit être :size.',
        'string' => 'Ce champ doit contenir :size caractères.',
    ],
    'string' => 'Ce champ doit être un texte.',
    'unique' => 'Cette valeur est déjà utilisée.',
    'uploaded' => 'Le fichier n’a pas pu être envoyé.',
    'uuid' => 'Identifiant invalide.',

    'custom' => [],

    'attributes' => [],
];
