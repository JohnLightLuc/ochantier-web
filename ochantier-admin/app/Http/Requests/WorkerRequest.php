<?php

namespace App\Http\Requests;

use App\Enums\WorkerDocumentType;
use App\Models\Worker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un ouvrier (formulaire en 6 étapes).
 */
class WorkerRequest extends FormRequest
{
    public const TRADES = ['Maçon', 'Électricien', 'Plombier', 'Peintre', 'Menuisier', 'Carreleur', 'Manœuvre', 'Coffreur', 'Ferrailleur', 'Soudeur'];

    public const COMMUNES = ['Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Treichville', 'Yopougon', 'Bingerville', 'Songon', 'Anyama', 'Grand-Bassam', 'Bouaké'];

    public const NATIONALITIES = ['Ivoirienne', 'Burkinabè', 'Malienne', 'Guinéenne', 'Ghanéenne', 'Togolaise', 'Béninoise', 'Autre'];

    public const QUALIFICATIONS = ['Ouvrier spécialisé', 'Ouvrier qualifié', 'Ouvrier hautement qualifié', 'Chef d’équipe'];

    public const MOBILITIES = ['Commune et communes limitrophes', 'Abidjan et environs (50 km)', 'Tout le district d’Abidjan', 'Toute la Côte d’Ivoire'];

    public const ID_TYPES = ['Carte nationale d’identité', 'Passeport', 'Carte consulaire', 'Attestation d’identité'];

    public const RECRUITERS = ['Adama Coulibaly', 'Mariam Koné', 'Jean-Marc Yao', 'Aïcha Traoré', 'Serge Koffi', 'Fatou Diarra'];

    public const SALES_ZONES = ['Abidjan Nord', 'Cocody', 'Yopougon', 'Bingerville', 'Bouaké', 'Grand-Bassam'];

    public const CHANNELS = ['Terrain', 'Recommandation', 'Application mobile', 'Agence', 'Réseaux sociaux'];

    public const AVAILABILITY_CHOICES = ['Disponible', 'Partiellement disponible', 'Indisponible'];

    /**
     * Champs fichier du formulaire → type de pièce.
     */
    public const FILE_FIELDS = [
        'id_front' => WorkerDocumentType::IdFront,
        'id_back' => WorkerDocumentType::IdBack,
        'proof_of_address' => WorkerDocumentType::ProofOfAddress,
        'cv' => WorkerDocumentType::Cv,
        'diploma' => WorkerDocumentType::Diploma,
        'professional_certificate' => WorkerDocumentType::ProfessionalCertificate,
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise les montants saisis avec espaces.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'daily_rate' => is_string($this->daily_rate) ? str_replace(' ', '', $this->daily_rate) : $this->daily_rate,
            'experiences' => array_values(array_filter(
                (array) $this->input('experiences', []),
                fn (mixed $e): bool => is_array($e) && collect($e)->only(['company', 'mission', 'site', 'started_at'])->filter()->isNotEmpty(),
            )),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'regex:/^\+225( \d{2}){5}$/'];
        $file = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
        $current = $this->route('worker');

        return [
            // Identité
            'photo' => ['nullable', 'image', 'max:5120'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['nullable', Rule::in(['Homme', 'Femme'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->format('Y-m-d')],
            'nationality' => ['nullable', Rule::in(self::NATIONALITIES)],
            'phone' => ['required', ...array_slice($phone, 1), $this->uniquePhone($current)],
            'phone_secondary' => $phone,
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'commune' => ['nullable', Rule::in(self::COMMUNES)],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => $phone,

            // Métier et compétences
            'trade' => ['required', Rule::in(config('admin.trades'))],
            'qualification' => ['nullable', Rule::in(self::QUALIFICATIONS)],
            'secondary_trades' => ['array'],
            'secondary_trades.*' => [Rule::in(config('admin.trades'))],
            'specialties' => ['nullable', 'string', 'max:500'],
            'skills' => ['nullable', 'string', 'max:500'],
            'experience_years' => ['nullable', 'integer', 'between:0,50'],
            'daily_rate' => ['nullable', 'integer', 'between:3000,100000'],
            'experience_description' => ['nullable', 'string', 'max:2000'],
            'availability_choice' => ['nullable', Rule::in(self::AVAILABILITY_CHOICES)],
            'mobility' => ['nullable', Rule::in(self::MOBILITIES)],
            'zones' => ['array'],
            'zones.*' => [Rule::in(self::COMMUNES)],
            'works_solo' => ['boolean'],
            'works_in_team' => ['boolean'],
            'accepts_contracts' => ['boolean'],

            // Expériences
            'experiences' => ['array'],
            'experiences.*.company' => ['required', 'string', 'max:160'],
            'experiences.*.mission' => ['required', 'string', 'max:160'],
            'experiences.*.trade' => ['nullable', Rule::in(config('admin.trades'))],
            'experiences.*.site' => ['nullable', 'string', 'max:160'],
            'experiences.*.location' => ['nullable', 'string', 'max:160'],
            'experiences.*.reference' => ['nullable', 'string', 'max:160'],
            'experiences.*.started_at' => ['required', 'date'],
            'experiences.*.ended_at' => ['nullable', 'date', 'after_or_equal:experiences.*.started_at'],
            'experiences.*.description' => ['nullable', 'string', 'max:1000'],

            // Pièces
            'id_type' => ['nullable', Rule::in(self::ID_TYPES)],
            'id_number' => ['nullable', 'string', 'max:40'],
            'id_issued_at' => ['nullable', 'date'],
            'id_expires_at' => ['nullable', 'date', 'after:id_issued_at', 'after:today'],
            ...array_fill_keys(array_keys(self::FILE_FIELDS), $file),

            // Commercial
            'recruiter' => ['nullable', Rule::in(self::RECRUITERS)],
            'sales_zone' => ['nullable', Rule::in(self::SALES_ZONES)],
            'registered_at' => ['nullable', 'date'],
            'acquisition_channel' => ['nullable', Rule::in(self::CHANNELS)],
            'sales_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Un même numéro principal ne peut servir qu’à un seul ouvrier (détection des doublons).
     */
    private function uniquePhone(?Worker $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            $digits = Worker::phoneDigits((string) $value);
            $duplicate = Worker::query()
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->get(['id', 'first_name', 'last_name', 'code', 'phone'])
                ->first(fn (Worker $w): bool => Worker::phoneDigits($w->phone) === $digits);

            if ($duplicate) {
                $fail("Doublon potentiel : numéro déjà utilisé par {$duplicate->name} ({$duplicate->code}).");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required' => 'Ce champ est obligatoire.',
            'experiences.*.*.required' => 'Ce champ est obligatoire.',
            'phone.regex' => 'Le numéro doit contenir 10 chiffres.',
            'phone_secondary.regex' => 'Le numéro doit contenir 10 chiffres.',
            'emergency_phone.regex' => 'Le numéro doit contenir 10 chiffres.',
            'email.email' => 'Adresse e-mail invalide.',
            'birth_date.before_or_equal' => 'L’ouvrier doit avoir au moins 18 ans.',
            'experience_years.*' => 'Nombre entier entre 0 et 50.',
            'daily_rate.*' => 'Montant entre 3 000 et 100 000 FCFA.',
            'experiences.*.ended_at.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'id_expires_at.after' => 'La date d’expiration doit suivre la date de délivrance et ne pas être passée.',
            '*.mimes' => 'Formats acceptés : PDF, JPG ou PNG.',
            '*.max' => 'Le fichier est trop volumineux.',
        ];
    }

    /**
     * Attributs du modèle Worker issus du formulaire.
     *
     * @return array<string, mixed>
     */
    public function workerData(): array
    {
        $list = fn (?string $text): array => array_values(array_filter(array_map('trim', explode(',', (string) $text))));
        $data = $this->safe()->only([
            'first_name', 'last_name', 'gender', 'birth_date', 'nationality', 'phone', 'phone_secondary', 'email', 'address', 'commune',
            'city', 'country', 'emergency_contact', 'emergency_phone', 'trade', 'qualification', 'experience_description', 'mobility',
            'id_type', 'id_number', 'id_issued_at', 'id_expires_at', 'recruiter', 'sales_zone', 'acquisition_channel', 'sales_note',
        ]);

        return [
            ...$data,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'secondary_trades' => $this->validated('secondary_trades', []),
            'specialties' => $list($this->validated('specialties')),
            'skills' => $list($this->validated('skills')),
            'zones' => $this->validated('zones', []),
            'experience_years' => (int) $this->validated('experience_years', 0),
            'daily_rate' => (int) $this->validated('daily_rate', 0),
            'works_solo' => $this->boolean('works_solo'),
            'works_in_team' => $this->boolean('works_in_team'),
            'accepts_contracts' => $this->boolean('accepts_contracts'),
        ];
    }
}
