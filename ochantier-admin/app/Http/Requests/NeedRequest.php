<?php

namespace App\Http\Requests;

use App\Enums\NeedUrgency;
use App\Enums\PromoterStatus;
use App\Enums\SiteStatus;
use App\Models\Need;
use App\Models\Promoter;
use App\Models\Site;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un besoin (formulaire en 8 étapes).
 *
 * Les colonnes du besoin et ses lignes sont extraites de la saisie ; la saisie complète est conservée
 * dans `details` pour pré-remplir le formulaire de modification.
 */
class NeedRequest extends FormRequest
{
    public const TYPES = ['Besoin journalier', 'Besoin hebdomadaire', 'Besoin contractuel', 'Besoin ponctuel', 'Besoin récurrent', 'Besoin urgent', 'Besoin de remplacement', 'Renfort d’équipe'];

    public const EXPERIENCES = ['Débutant', 'Junior', 'Intermédiaire', 'Expérimenté', 'Expert', 'Chef d’équipe'];

    public const PAY_TYPES = ['Journalier', 'Hebdomadaire', 'Forfaitaire', 'Contractuel', 'Par étape', 'Par livrable', 'À négocier'];

    public const TRADE_GROUPS = ['Gros œuvre', 'Structure', 'Second œuvre', 'Réseaux', 'Électricité', 'Menuiserie', 'Métallerie', 'Finitions'];

    public const ADMINS = ['N. Yao', 'A. Koné', 'M. Diabaté', 'S. Kouamé'];

    public const SOURCES = ['Application Promoteur', 'Back-office', 'Commercial terrain', 'Service client'];

    public const VISIBILITIES = ['Publique', 'Ouvriers éligibles uniquement', 'Zone géographique ciblée', 'Métier ciblé', 'Invitation privée', 'Réseau de commerciaux'];

    public const DELAYS = ['48 heures', '3 jours', '5 jours', '7 jours', '14 jours'];

    public const FREQUENCIES = ['Chaque semaine', 'Toutes les deux semaines', 'Chaque mois', 'À chaque phase du chantier'];

    public const ZONES = ['Cocody', 'Yopougon', 'Abobo', 'Plateau', 'Marcory', 'Koumassi', 'Treichville', 'Port-Bouët', 'Bingerville', 'Anyama'];

    /**
     * Spécialités, compétences et certifications proposées par métier.
     *
     * @var array<string, array{specialties: array<int, string>, skills: array<int, string>, certifications: array<int, string>}>
     */
    public const TRADE_DETAILS = [
        'Maçon' => ['specialties' => ['Maçonnerie traditionnelle', 'Élévation de murs', 'Chapes et enduits', 'Maçonnerie générale', 'Chaînages'], 'skills' => ['Lecture de plans', 'Dosage béton', 'Pose de parpaings', 'Coffrage simple'], 'certifications' => ['CAP Maçonnerie', 'Sécurité sur chantier SST']],
        'Électricien' => ['specialties' => ['Courant faible', 'Courant fort', 'Tableaux électriques'], 'skills' => ['Câblage', 'Mise à la terre', 'Lecture de schémas', 'Norme NF C 15-100'], 'certifications' => ['BT Électrotechnique', 'Habilitation électrique B1V']],
        'Plombier' => ['specialties' => ['Sanitaires', 'Réseaux PVC'], 'skills' => ['Soudure cuivre', 'Pose de sanitaires', 'Évacuations', 'Recherche de fuites'], 'certifications' => ['CAP Plomberie']],
        'Peintre' => ['specialties' => ['Peinture intérieure', 'Peinture extérieure', 'Peinture décorative', 'Façades'], 'skills' => ['Préparation des supports', 'Enduits de lissage', 'Peinture au pistolet'], 'certifications' => ['Attestation de formation peinture']],
        'Menuisier' => ['specialties' => ['Menuiserie bois', 'Pose de portes'], 'skills' => ['Découpe', 'Assemblage', 'Pose de menuiseries', 'Finitions'], 'certifications' => ['CAP Menuiserie']],
        'Carreleur' => ['specialties' => ['Carrelage sol', 'Faïence murale'], 'skills' => ['Calepinage', 'Pose collée', 'Joints', 'Découpe à la meuleuse'], 'certifications' => ['Attestation carrelage']],
        'Manœuvre' => ['specialties' => ['Manutention', 'Préparation', 'Préparation de chantier'], 'skills' => ['Gâchage', 'Nettoyage de chantier', 'Aide au coffrage'], 'certifications' => []],
        'Coffreur' => ['specialties' => ['Coffrage bois', 'Coffrage métallique'], 'skills' => ['Lecture de plans', 'Étaiement', 'Décoffrage', 'Traçage'], 'certifications' => ['CQP Coffreur-bancheur']],
        'Ferrailleur' => ['specialties' => ['Armatures', 'Façonnage'], 'skills' => ['Lecture de plans de ferraillage', 'Ligature', 'Découpe et pliage'], 'certifications' => ['Attestation ferraillage']],
        'Soudeur' => ['specialties' => ['Soudure à l’arc', 'Charpente métallique'], 'skills' => ['Soudure MIG', 'Meulage', 'Lecture de plans'], 'certifications' => []],
    ];

    /**
     * Champs de fichier de l’étape « Documents et médias » → type de document.
     *
     * @var array<string, string>
     */
    public const FILE_FIELDS = [
        'doc_specs' => 'Cahier des charges', 'doc_mission' => 'Fiche de mission', 'doc_plan' => 'Plan du chantier', 'doc_photos' => 'Photos du chantier',
        'doc_safety' => 'Consignes de sécurité', 'doc_schedule' => 'Planning', 'doc_contract' => 'Contrat ou convention', 'doc_technical' => 'Documents techniques', 'doc_other' => 'Autres pièces',
    ];

    private const AMOUNTS = ['daily_cost', 'weekly_cost', 'contract_amount', 'bonus', 'transport', 'meals', 'max_applications', 'teams', 'team_size', 'weeks'];

    private const BOOLEANS = ['weekend', 'night', 'recurring', 'team_lead', 'id_card', 'verified_profile', 'no_dispute', 'confirmed_availability', 'medical', 'briefing', 'notify_workers', 'notify_sales', 'notify_promoter', 'auto_renew'];

    private const CONTRACT_PAY = ['Forfaitaire', 'Contractuel', 'Par étape', 'Par livrable'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $v): string => preg_replace('/\s+/u', '', (string) $v);
        $data = [];
        foreach (self::AMOUNTS as $key) {
            if ($this->filled($key)) {
                $data[$key] = $clean($this->input($key));
            }
        }
        foreach (self::BOOLEANS as $key) {
            $data[$key] = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN);
        }
        $data['contact_phone'] = preg_replace('/\D/', '', (string) $this->input('contact_phone'));
        $data['lines'] = array_map(fn ($line): array => [
            ...(array) $line,
            'quantity' => $clean($line['quantity'] ?? ''),
            'own_equipment' => filter_var($line['own_equipment'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], (array) $this->input('lines', []));

        $this->merge($data);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('need') instanceof Need;
        $today = Need::referenceDate()->format('Y-m-d');
        $amount = ['nullable', 'regex:/^\d+$/'];
        $file = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,zip', 'max:10240'];

        return [
            'promoter' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (Promoter::query()->where('name', $value)->where('status', PromoterStatus::Suspended)->exists()) {
                    $fail('Promoteur suspendu : aucun besoin ne peut être créé.');
                }
            }],
            'site' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $site = Site::query()->where('slug', $value)->first();
                if (! $site || $site->promoter_name !== $this->input('promoter')) {
                    $fail('Ce chantier n’appartient pas au promoteur sélectionné.');
                } elseif ($site->status->isFinal()) {
                    $fail('Ce chantier est clôturé, annulé ou archivé.');
                }
            }],
            'source' => ['required', Rule::in(self::SOURCES)],
            'district' => ['nullable', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'digits:10'],

            'lines' => ['required', 'array', 'min:1', 'max:12'],
            'lines.*.trade_group' => ['required', Rule::in(self::TRADE_GROUPS)],
            'lines.*.trade' => ['required', Rule::in(array_keys(self::TRADE_DETAILS)), function (string $attribute, mixed $value, Closure $fail): void {
                $index = (int) explode('.', $attribute)[1];
                $lines = (array) $this->input('lines');
                $specialty = $lines[$index]['specialty'] ?? null;
                foreach ($lines as $k => $line) {
                    if ($k !== $index && ($line['trade'] ?? null) === $value && ($line['specialty'] ?? null) === $specialty) {
                        $fail('Ce métier et cette spécialité figurent déjà sur une autre ligne.');

                        return;
                    }
                }
            }],
            'lines.*.specialty' => ['nullable', 'string', 'max:120'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'lines.*.experience' => ['required', Rule::in(self::EXPERIENCES)],
            'lines.*.tools' => ['nullable', 'string', 'max:255'],
            'lines.*.skills' => ['nullable', 'array'],
            'lines.*.certifications' => ['nullable', 'array'],
            'lines.*.own_equipment' => ['boolean'],
            'lines.*.constraints' => ['nullable', 'string', 'max:255'],
            'lines.*.comment' => ['nullable', 'string', 'max:1000'],

            'type' => ['required', Rule::in(self::TYPES)],
            'urgency' => ['required', Rule::enum(NeedUrgency::class)],
            'starts_on' => ['required', 'date', ...($editing ? [] : ['after_or_equal:'.$today])],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'working_days' => ['nullable', 'array'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'break_time' => ['nullable', 'string', 'max:40'],
            'application_delay' => ['required', Rule::in(self::DELAYS)],
            'frequency' => [Rule::requiredIf(fn (): bool => $this->boolean('recurring') || $this->input('type') === 'Besoin récurrent'), 'nullable', Rule::in(self::FREQUENCIES)],
            'weeks' => [Rule::requiredIf(fn (): bool => $this->input('type') === 'Besoin hebdomadaire'), 'nullable', 'integer', 'min:1'],
            'deliverables' => [Rule::requiredIf(fn (): bool => $this->input('type') === 'Besoin contractuel'), 'nullable', 'string', 'max:2000'],
            'justification' => [Rule::requiredIf(fn (): bool => (bool) NeedUrgency::tryFrom((string) $this->input('urgency'))?->isPressing()), 'nullable', 'string', 'max:1000'],
            'coverage_deadline' => [Rule::requiredIf(fn (): bool => $this->input('urgency') === NeedUrgency::Critical->value), 'nullable', 'date', 'before_or_equal:ends_on'],
            'follow_up' => [Rule::requiredIf(fn (): bool => $this->input('urgency') === NeedUrgency::Critical->value), 'nullable', Rule::in(self::ADMINS)],
            'teams' => $amount,
            'team_size' => $amount,

            'pay_type' => ['required', Rule::in(self::PAY_TYPES)],
            'daily_cost' => [Rule::requiredIf(fn (): bool => $this->input('pay_type') === 'Journalier'), ...$amount],
            'weekly_cost' => [Rule::requiredIf(fn (): bool => $this->input('pay_type') === 'Hebdomadaire'), ...$amount],
            'contract_amount' => [Rule::requiredIf(fn (): bool => in_array($this->input('pay_type'), self::CONTRACT_PAY, true)), ...$amount],
            'bonus' => $amount,
            'transport' => $amount,
            'meals' => $amount,

            'min_experience' => ['nullable', 'string', 'max:20'],
            'min_rating' => ['nullable', 'string', 'max:20'],
            'ppe' => ['nullable', 'array'],
            'professional_docs' => ['nullable', 'array'],

            ...array_fill_keys(array_keys(self::FILE_FIELDS), $file),

            'deadline' => ['required', 'date', 'before:starts_on', 'after_or_equal:'.$today],
            'visibility' => ['required', Rule::in(self::VISIBILITIES)],
            'zones' => ['nullable', 'array'],
            'target_trades' => ['nullable', 'array'],
            'target_levels' => ['nullable', 'array'],
            'favorites' => ['nullable', 'array'],
            'max_applications' => $amount,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'required_if' => 'Ce champ est obligatoire.',
            'contact_phone.digits' => 'Numéro à 10 chiffres attendu.',
            'lines.required' => 'Ajoutez au moins un métier.',
            'lines.*.quantity.*' => 'Nombre supérieur à zéro attendu.',
            'starts_on.after_or_equal' => 'La date de début ne peut pas être passée.',
            'ends_on.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'end_time.after' => 'L’heure de fin doit suivre l’heure de début.',
            'start_time.date_format' => 'Heure au format HH:MM.',
            'end_time.date_format' => 'Heure au format HH:MM.',
            'justification.required' => 'Justification obligatoire pour ce niveau d’urgence.',
            'coverage_deadline.before_or_equal' => 'Doit précéder la date de fin.',
            'deadline.before' => 'Doit précéder la date de début.',
            'deadline.after_or_equal' => 'Ne peut pas être dans le passé.',
            'regex' => 'Montant en FCFA, chiffres uniquement.',
            'integer' => 'Nombre attendu.',
            'mimes' => 'Formats acceptés : PDF, JPG, PNG ou ZIP.',
            'max' => 'Valeur trop longue.',
            'file' => 'Fichier invalide.',
        ];
    }

    /**
     * Coût journalier équivalent, selon le mode de rémunération.
     */
    public function dailyCost(): int
    {
        $n = fn (string $key): int => (int) $this->validated($key, 0);
        $days = max(1, (int) round(($this->date('starts_on')->diffInDays($this->date('ends_on')) + 1) * 6 / 7));

        return match ($this->validated('pay_type')) {
            'Journalier' => $n('daily_cost'),
            'Hebdomadaire' => $n('daily_cost') ?: (int) round($n('weekly_cost') / 6),
            'À négocier' => 0,
            default => (int) round($n('contract_amount') / $days),
        };
    }

    /**
     * Colonnes du besoin.
     *
     * @return array<string, mixed>
     */
    public function needData(Site $site): array
    {
        $v = $this->validated();
        $requirements = array_keys(array_filter([
            'Pièce d’identité' => $this->boolean('id_card'),
            'Profil vérifié' => $this->boolean('verified_profile'),
            'Absence de litige actif' => $this->boolean('no_dispute'),
            'Disponibilité confirmée' => $this->boolean('confirmed_availability'),
            'Visite médicale' => $this->boolean('medical'),
            'Briefing obligatoire' => $this->boolean('briefing'),
        ]));
        $conditions = array_filter([
            ($v['ppe'] ?? []) ? 'EPI : '.implode(', ', $v['ppe']) : null,
            $this->boolean('briefing') ? 'briefing obligatoire' : null,
            $this->input('access'),
        ]);

        return [
            'site_id' => $site->id,
            'urgency' => $v['urgency'],
            'type' => $v['type'],
            'source' => $v['source'],
            'admin' => ($v['follow_up'] ?? null) ?: auth()->user()?->name,
            'district' => $v['district'] ?? null,
            'contact' => $v['contact'],
            'contact_phone' => $v['contact_phone'],
            'starts_on' => $v['starts_on'],
            'ends_on' => $v['ends_on'],
            'working_hours' => "{$v['start_time']} – {$v['end_time']}".(($v['break_time'] ?? null) ? " · pause {$v['break_time']}" : ''),
            'working_days' => implode(', ', $v['working_days'] ?? []),
            'justification' => $v['justification'] ?? null,
            'coverage_deadline' => $v['coverage_deadline'] ?? null,
            'follow_up' => $v['follow_up'] ?? null,
            'conditions' => $conditions ? implode(' · ', $conditions) : null,
            'requirements' => $requirements,
            'details' => collect($this->except([...array_keys(self::FILE_FIELDS), '_method']))->all(),
        ];
    }

    /**
     * Lignes du besoin, dans l’ordre de saisie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linesData(): array
    {
        $cost = $this->dailyCost();

        return array_values(array_map(fn (array $line, int $i): array => [
            'position' => $i + 1,
            'trade_group' => $line['trade_group'],
            'trade' => $line['trade'],
            'specialty' => $line['specialty'] ?? null,
            'quantity' => (int) $line['quantity'],
            'experience' => $line['experience'],
            'daily_cost' => $cost,
            'pay_type' => $this->validated('pay_type'),
            'skills' => array_values($line['skills'] ?? []),
            'certifications' => array_values($line['certifications'] ?? []),
            'own_equipment' => (bool) ($line['own_equipment'] ?? false),
            'tools' => $line['tools'] ?? null,
            'constraints' => $line['constraints'] ?? null,
            'comment' => $line['comment'] ?? null,
        ], $this->validated('lines'), array_keys($this->validated('lines'))));
    }

    /**
     * Chantiers ouverts aux besoins, groupés par promoteur.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function sitesByPromoter(): array
    {
        return Site::query()->orderBy('name')->get()
            ->reject(fn (Site $s): bool => $s->status->isFinal() || $s->status === SiteStatus::Draft)
            ->groupBy('promoter_name')
            ->map(fn ($sites) => $sites->map(fn (Site $s): array => ['value' => $s->slug, 'label' => $s->name])->values()->all())
            ->sortKeys()
            ->all();
    }
}
