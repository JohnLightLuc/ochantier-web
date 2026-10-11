<?php

namespace App\Http\Requests;

use App\Enums\SalesAgentDocumentType;
use App\Models\SalesAgent;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un commercial : les 5 étapes de saisie du formulaire.
 */
class SalesAgentRequest extends FormRequest
{
    public const TYPES = ['Agent terrain', 'Commercial salarié', 'Commercial indépendant', 'Superviseur commercial', 'Responsable de zone'];

    public const NO_AGENCY = 'Aucune agence (indépendant)';

    public const AGENCIES = ['Agence Abobo', 'Agence Cocody', 'Agence Yopougon', 'Agence Marcory', 'Antenne Bingerville', 'Antenne Bouaké', 'Antenne Grand-Bassam'];

    public const SUPERVISORS = ['Adama Coulibaly', 'Fatou Diarra', 'Direction commerciale'];

    public const CONTRACTS = ['CDI', 'CDD', 'Convention', 'Stage'];

    public const NATIONALITIES = ['Ivoirienne', 'Burkinabè', 'Malienne', 'Guinéenne', 'Ghanéenne', 'Autre'];

    public const ROLES = ['Commercial terrain', 'Superviseur', 'Responsable de zone'];

    public const LEVELS = ['Standard', 'Étendu', 'Complet'];

    public const MODULES = ['Ouvriers', 'Documents', 'Affectations', 'Gains', 'Exports'];

    public const DURATIONS = ['Illimitée', '6 mois', '12 mois', 'Jusqu’à la fin du contrat'];

    public const PAY_MODES = ['Fixe', 'Commission', 'Fixe et commission', 'Prime sur objectif'];

    public const CALC_FREQUENCIES = ['Hebdomadaire', 'Mensuelle'];

    public const PAY_FREQUENCIES = ['Hebdomadaire', 'Bimensuelle', 'Mensuelle'];

    public const PAY_METHODS = ['Orange Money', 'Wave', 'MTN MoMo', 'Virement bancaire'];

    /**
     * Autorisations de l’Application Commercial : clé → libellé.
     */
    public const PERMISSIONS = [
        'create' => 'Créer un ouvrier',
        'edit_own' => 'Modifier les ouvriers qu’il a enregistrés',
        'documents' => 'Ajouter des documents',
        'submit' => 'Soumettre un profil pour validation',
        'assignments' => 'Consulter les affectations autorisées',
        'earnings' => 'Consulter ses gains',
        'export' => 'Exporter les données autorisées',
    ];

    /**
     * Champs fichier du formulaire → type de pièce.
     */
    public const FILE_FIELDS = [
        'id_front' => SalesAgentDocumentType::IdFront,
        'id_back' => SalesAgentDocumentType::IdBack,
        'photo_id' => SalesAgentDocumentType::Photo,
        'proof_of_address' => SalesAgentDocumentType::ProofOfAddress,
        'contract_file' => SalesAgentDocumentType::Contract,
        'cv' => SalesAgentDocumentType::Cv,
    ];

    private const AMOUNTS = ['fixed', 'per_registered', 'per_validated', 'bonus_objective', 'bonus_cotm', 'cap', 'weekly_target', 'monthly_target'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise les montants saisis avec espaces et l’adresse e-mail (unicité insensible à la casse).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            ...collect(self::AMOUNTS)
                ->filter(fn (string $k): bool => is_string($this->input($k)))
                ->mapWithKeys(fn (string $k): array => [$k => str_replace([' ', ' '], '', $this->input($k))])
                ->all(),
            ...(is_string($this->input('email')) ? ['email' => mb_strtolower(trim($this->input('email')))] : []),
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
        $amount = ['nullable', 'integer', 'min:0', 'max:100000000'];
        $current = $this->route('salesAgent');
        $communes = WorkerRequest::COMMUNES;

        return [
            // Informations personnelles
            'photo' => ['nullable', 'image', 'max:5120'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['nullable', Rule::in(['Homme', 'Femme'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->format('Y-m-d')],
            'nationality' => ['nullable', Rule::in(self::NATIONALITIES)],
            'phone' => ['required', ...array_slice($phone, 1), $this->uniquePhone($current)],
            'phone_secondary' => $phone,
            'email' => ['required', 'email', Rule::unique('sales_agents', 'email')->ignore($current?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'commune' => ['nullable', Rule::in($communes)],
            'city' => ['nullable', 'string', 'max:80'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => $phone,

            // Informations professionnelles
            'type' => ['required', Rule::in(self::TYPES)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'agency' => ['nullable', Rule::in([self::NO_AGENCY, ...self::AGENCIES])],
            'zone' => ['required', Rule::in($communes)],
            'secondary_zones' => ['array'],
            'secondary_zones.*' => [Rule::in($communes)],
            'supervisor' => ['nullable', Rule::in(self::SUPERVISORS)],
            'started_at' => ['nullable', 'date'],
            'contract' => ['nullable', Rule::in(self::CONTRACTS)],
            'weekly_target' => ['nullable', 'integer', 'between:0,500'],
            'monthly_target' => ['nullable', 'integer', 'between:0,2000'],
            'specialties' => ['array'],
            'specialties.*' => [Rule::in(config('admin.trades'))],
            'internal_note' => ['nullable', 'string', 'max:2000'],

            // Documents
            'id_type' => ['nullable', Rule::in(WorkerRequest::ID_TYPES)],
            'id_number' => ['nullable', 'string', 'max:40'],
            'id_issued_at' => ['nullable', 'date'],
            'id_expires_at' => ['nullable', 'date', 'after:id_issued_at'],
            ...array_fill_keys(array_keys(self::FILE_FIELDS), $file),

            // Accès et autorisations
            'role' => ['required', Rule::in(self::ROLES)],
            'level' => ['required', Rule::in(self::LEVELS)],
            'modules' => ['array'],
            'modules.*' => [Rule::in(self::MODULES)],
            'data_zones' => ['array'],
            'data_zones.*' => [Rule::in($communes)],
            'permissions' => ['array'],
            'permissions.*' => ['boolean'],
            'two_factor' => ['boolean'],
            'duration' => ['required', Rule::in(self::DURATIONS)],

            // Gains
            'pay_mode' => ['required', Rule::in(self::PAY_MODES)],
            'fixed' => $amount,
            'per_registered' => $amount,
            'per_validated' => $amount,
            'bonus_objective' => $amount,
            'bonus_cotm' => $amount,
            'calc_frequency' => ['required', Rule::in(self::CALC_FREQUENCIES)],
            'pay_frequency' => ['required', Rule::in(self::PAY_FREQUENCIES)],
            'pay_method' => ['nullable', Rule::in(self::PAY_METHODS)],
            'effective_from' => ['nullable', 'date'],
            'cap' => $amount,
            'eligibility' => ['nullable', 'string', 'max:500'],
            'pay_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Le téléphone principal est l’identifiant de connexion : il doit être unique parmi les commerciaux.
     */
    private function uniquePhone(?SalesAgent $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            $digits = preg_replace('/\D/', '', (string) $value);
            $duplicate = SalesAgent::query()
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->get(['id', 'first_name', 'last_name', 'code', 'phone'])
                ->first(fn (SalesAgent $a): bool => preg_replace('/\D/', '', $a->phone) === $digits);

            if ($duplicate) {
                $fail("Numéro déjà utilisé comme identifiant par {$duplicate->name} ({$duplicate->code}).");
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
            '*.regex' => 'Le numéro doit contenir 10 chiffres.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Adresse déjà utilisée par un autre commercial.',
            'birth_date.before_or_equal' => 'Le commercial doit avoir au moins 18 ans.',
            '*.integer' => 'Nombre entier attendu.',
            'id_expires_at.after' => 'La date d’expiration doit suivre la date de délivrance.',
            '*.mimes' => 'Formats acceptés : PDF, JPG ou PNG.',
            '*.max' => 'Valeur ou fichier trop volumineux.',
        ];
    }

    /**
     * Attributs du modèle SalesAgent issus du formulaire (hors rémunération et accès).
     *
     * @return array<string, mixed>
     */
    public function agentData(): array
    {
        $data = $this->safe()->only([
            'first_name', 'last_name', 'gender', 'birth_date', 'nationality', 'phone', 'phone_secondary', 'email', 'address', 'commune',
            'city', 'emergency_contact', 'emergency_phone', 'type', 'job_title', 'zone', 'supervisor', 'contract', 'internal_note',
            'id_type', 'id_number', 'id_issued_at', 'id_expires_at',
        ]);
        $independent = $data['type'] === 'Commercial indépendant';
        $agency = $this->validated('agency');

        return [
            ...$data,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => mb_strtolower(trim($data['email'])),
            'agency' => $independent || ! $agency || $agency === self::NO_AGENCY ? null : $agency,
            'supervisor' => $independent ? 'Direction commerciale' : ($data['supervisor'] ?? null),
            'secondary_zones' => array_values(array_diff($this->validated('secondary_zones', []), [$data['zone']])),
            'specialties' => $this->validated('specialties', []),
            'weekly_target' => (int) $this->validated('weekly_target', 0),
            'monthly_target' => (int) $this->validated('monthly_target', 0),
        ];
    }

    /**
     * Paramétrage des gains.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function payData(array $current): array
    {
        $int = fn (string $key, int $default = 0): int => (int) ($this->validated($key) ?? $default);

        return [
            ...$current,
            'mode' => $this->validated('pay_mode'),
            'fixed' => $int('fixed'),
            'per_registered' => $int('per_registered'),
            'per_validated' => $int('per_validated'),
            'bonus_objective' => $int('bonus_objective'),
            'bonus_cotm' => $int('bonus_cotm'),
            'calc_frequency' => $this->validated('calc_frequency'),
            'pay_frequency' => $this->validated('pay_frequency'),
            'effective_from' => $this->validated('effective_from') ?: today()->format('Y-m-d'),
            'cap' => $int('cap') ?: 600000,
            'eligibility' => $this->validated('eligibility') ?: 'Profils validés uniquement, hors doublons et rejets',
            'method' => $this->validated('pay_method') ?: ($current['method'] ?? 'Orange Money'),
            'note' => $this->validated('pay_note'),
        ];
    }

    /**
     * Rôle, modules, zones de données et autorisations.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function accessData(array $current): array
    {
        $zones = $this->validated('data_zones', []);
        $permissions = (array) $this->validated('permissions', []);

        return [
            ...$current,
            'role' => $this->validated('role'),
            'level' => $this->validated('level'),
            'modules' => $this->validated('modules', []),
            'data_zones' => $zones ?: [$this->validated('zone'), ...$this->validated('secondary_zones', [])],
            'permissions' => collect(self::PERMISSIONS)->mapWithKeys(fn (string $l, string $k): array => [$k => (bool) ($permissions[$k] ?? false)])->all(),
            'two_factor' => $this->boolean('two_factor'),
            'duration' => $this->validated('duration'),
        ];
    }
}
