<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use Illuminate\Http\Client\Response as ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Création et modification d’un ouvrier, et ajout d’une pièce à son dossier, par l’API (api/v1/admin/workers).
 *
 * Le formulaire envoie des champs à plat (phone « +225 07… », emergency_phone…) ; ils sont traduits pour l’API, et
 * les erreurs de l’API sont renvoyées sur les champs du formulaire.
 */
class WorkerFormController extends Controller
{
    /**
     * Champ de l’API => champ du formulaire.
     */
    private const ERROR_FIELDS = [
        'dial_code' => 'phone',
        'phone_number' => 'phone',
        'emergency_contact_dial_code' => 'emergency_phone',
        'emergency_contact_phone_number' => 'emergency_phone',
        'daily_rate' => 'daily_rate',
        'document_type' => 'document_type',
        'file' => 'recto',
    ];

    private const TRANSPORTS = ['a_pied' => 'À pied', 'transport_commun' => 'Transports en commun', 'moto' => 'Moto', 'velo' => 'Vélo', 'voiture' => 'Voiture'];

    public function __construct(private AdminApi $api) {}

    public function create(): Response
    {
        return $this->form('create', null, [
            'commercial_id' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'secondary_phone' => '',
            'gender' => '', 'birth_date' => '', 'birth_place' => '',
            'residence_commune_id' => '', 'residence_neighborhood_id' => '', 'residence_address' => '', 'residence_landmark' => '',
            'emergency_contact_name' => '', 'emergency_contact_relation' => '', 'emergency_phone' => '',
            'trade_id' => '', 'experience_years' => '', 'secondary_trade_id' => '', 'secondary_experience_years' => '',
            'specialty_ids' => [], 'skill_ids' => [], 'daily_rate' => '',
            'mobility_radius_km' => '', 'transport_mode' => '', 'accepts_long_travel' => false, 'needs_accommodation' => false,
            'works_solo' => true, 'works_in_team' => true,
            'accepted_offer_types' => ['ponctuelle', 'temporaire', 'contractuelle'], 'accepted_payment_frequencies' => ['journalier', 'hebdomadaire', 'mensuel', 'jalon'],
            'references' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->post('workers', $this->payload($request)));
        Inertia::flash('toast', $response->json('message'));

        return to_route('workers.show', [$response->json('data.id'), 'identite-documents']);
    }

    public function edit(string $worker): Response
    {
        $w = $this->api->get("workers/{$worker}")->json('data');
        $primary = collect($w['trades'])->firstWhere('is_primary', true);
        $secondary = collect($w['trades'])->firstWhere('is_primary', false);
        $phone = fn (?array $p) => ($p['phone_number'] ?? null) ? '+'.$p['dial_code'].' '.trim(chunk_split((string) $p['phone_number'], 2, ' ')) : '';
        $string = fn (mixed $v) => $v === null ? '' : (string) $v;

        return $this->form('edit', ['id' => $w['id'], 'name' => $w['full_name'], 'status' => $w['status']['value']], [
            'commercial_id' => $string($w['recruitment']['current_commercial']['id'] ?? null),
            'first_name' => $string($w['first_name']),
            'last_name' => $string($w['last_name']),
            'phone' => $phone($w['contact']),
            'secondary_phone' => $string($w['contact']['secondary_phone']),
            'gender' => $string($w['identity']['gender']['value'] ?? null),
            'birth_date' => $string($w['identity']['birth_date']),
            'birth_place' => $string($w['identity']['birth_place']),
            'residence_commune_id' => $string($w['residence']['commune']['id'] ?? null),
            'residence_neighborhood_id' => $string($w['residence']['neighborhood']['id'] ?? null),
            'residence_address' => $string($w['residence']['address']),
            'residence_landmark' => $string($w['residence']['landmark']),
            'emergency_contact_name' => $string($w['contact']['emergency_contact']['name']),
            'emergency_contact_relation' => $string($w['contact']['emergency_contact']['relation']),
            'emergency_phone' => $phone($w['contact']['emergency_contact']),
            'trade_id' => $string($primary['trade']['id'] ?? null),
            'experience_years' => $string($primary['experience_years'] ?? null),
            'secondary_trade_id' => $string($secondary['trade']['id'] ?? null),
            'secondary_experience_years' => $string($secondary['experience_years'] ?? null),
            'specialty_ids' => array_column($w['specialties'], 'id'),
            'skill_ids' => array_column($w['skills'], 'id'),
            'daily_rate' => $string($w['daily_rate']),
            'mobility_radius_km' => $string($w['work']['mobility_radius_km']),
            'transport_mode' => $string($w['work']['transport_mode']),
            'accepts_long_travel' => (bool) $w['work']['accepts_long_travel'],
            'needs_accommodation' => (bool) $w['work']['needs_accommodation'],
            'works_solo' => (bool) $w['work']['works_solo'],
            'works_in_team' => (bool) $w['work']['works_in_team'],
            'accepted_offer_types' => $w['work']['accepted_offer_types'],
            'accepted_payment_frequencies' => $w['work']['accepted_payment_frequencies'],
            'references' => array_map(fn (array $r) => ['project' => $string($r['project']), 'role' => $string($r['role']), 'employer' => $string($r['employer']), 'start_date' => $string($r['start_date']), 'end_date' => $string($r['end_date'])], $w['references']),
        ]);
    }

    public function update(Request $request, string $worker): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->patch("workers/{$worker}", $this->payload($request)));
        Inertia::flash('toast', $response->json('message'));

        return to_route('workers.show', $worker);
    }

    /**
     * Pièce ajoutée au dossier (recto et verso, ou un seul fichier selon le type).
     */
    public function storeDocument(Request $request, string $worker): RedirectResponse
    {
        $request->validate([
            'document_type' => ['required', 'string'],
            'recto' => ['required', 'file'],
            'verso' => ['nullable', 'file'],
            'number' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date'],
        ], ['recto.required' => 'Ajoutez le fichier de la pièce.', 'document_type.required' => 'Choisissez le type de pièce.']);

        /** @var UploadedFile $recto */
        $recto = $request->file('recto');
        $verso = $request->file('verso');
        $files = $verso ? ['recto' => $recto, 'verso' => $verso] : ['file' => $recto];

        $response = $this->send(fn () => $this->api->upload("workers/{$worker}/documents", array_filter($request->only(['document_type', 'number', 'expires_at'])), $files));
        Inertia::flash('toast', $response->json('message'));

        return to_route('workers.show', [$worker, 'identite-documents']);
    }

    /**
     * Listes du formulaire, communes aux administrateurs : gardées 10 minutes.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return Cache::remember('workers.form-options', 600, fn () => $this->api->get('workers/form-options')->json('data'));
    }

    /**
     * Types de pièces que l’administrateur peut ajouter au dossier d’un ouvrier.
     *
     * @return list<array<string, mixed>>
     */
    public function documentTypes(): array
    {
        return array_map(fn (array $type) => [
            'value' => $type['code'],
            'label' => $type['label'],
            'hasBackSide' => $type['has_back_side'],
            'isIdentity' => $type['is_identity'],
            'accept' => implode(',', $type['allowed_mime'] ?? []),
        ], $this->formOptions()['document_types']);
    }

    /**
     * @param  array<string, mixed>|null  $worker
     * @param  array<string, mixed>  $values
     */
    private function form(string $mode, ?array $worker, array $values): Response
    {
        $options = $this->formOptions();
        $communes = collect($options['cities'])->flatMap(fn (array $city) => array_map(fn (array $commune) => [...$commune, 'city' => $city['name']], $city['communes']));
        $option = fn (array $item, string $label = 'label', string $value = 'value'): array => ['value' => $item[$value], 'label' => $item[$label]];

        return Inertia::render('Workers/Form', [
            'mode' => $mode,
            'worker' => $worker,
            'values' => $values,
            'options' => [
                'commercial' => array_map(fn (array $c) => $option($c, 'full_name', 'id'), $options['commercials']),
                'trade' => array_map(fn (array $t) => $option($t, 'label', 'id'), $options['trades']),
                'commune' => $communes->map(fn (array $c) => ['value' => $c['id'], 'label' => $c['city'] === $c['name'] ? $c['name'] : "{$c['name']} · {$c['city']}"])->values()->all(),
                'gender' => array_map($option, $options['genders']),
                'offer_type' => array_map($option, $options['offer_types']),
                'payment_frequency' => array_map($option, $options['payment_frequencies']),
                'transport' => array_map(fn (string $value, string $label) => compact('value', 'label'), array_keys(self::TRANSPORTS), self::TRANSPORTS),
            ],
            'neighborhoods' => $communes->mapWithKeys(fn (array $c) => [$c['id'] => array_map(fn (array $n) => ['value' => $n['id'], 'label' => $n['name']], $c['neighborhoods'])])->all(),
            'tradeLists' => collect($options['trades'])->mapWithKeys(fn (array $t) => [$t['id'] => [
                'specialties' => array_map(fn (array $s) => $option($s, 'label', 'id'), $t['specialties']),
                'skills' => array_map(fn (array $s) => $option($s, 'label', 'id'), $t['skills']),
            ]])->all(),
            'header' => $this->header($mode === 'create' ? 'Nouvel ouvrier' : 'Modifier l’ouvrier', '', array_values(array_filter([
                ['Ouvriers', '/ouvriers'],
                $worker ? [$worker['name'], "/ouvriers/{$worker['id']}"] : null,
                [$mode === 'create' ? 'Nouvel ouvrier' : 'Modifier'],
            ]))),
        ]);
    }

    /**
     * Champs à plat du formulaire => corps attendu par l’API.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $value = fn (string $key): ?string => ($v = trim((string) $request->input($key))) === '' ? null : $v;
        $integer = fn (string $key): ?int => ($v = $value($key)) === null ? null : (int) preg_replace('/\D/', '', $v);
        $phone = function (string $key) use ($request): array {
            $digits = preg_replace('/\D/', '', (string) $request->input($key));
            $number = str_starts_with($digits, '225') && strlen($digits) > 10 ? substr($digits, 3) : $digits;

            return $number === '' ? [null, null] : ['225', $number];
        };
        [$dial, $number] = $phone('phone');
        [$emergencyDial, $emergencyNumber] = $phone('emergency_phone');

        return [
            'commercial_id' => $value('commercial_id'),
            'first_name' => $value('first_name'),
            'last_name' => $value('last_name'),
            'dial_code' => $dial,
            'phone_number' => $number,
            'secondary_phone' => $value('secondary_phone'),
            'gender' => $value('gender'),
            'birth_date' => $value('birth_date'),
            'birth_place' => $value('birth_place'),
            'residence_commune_id' => $value('residence_commune_id'),
            'residence_neighborhood_id' => $value('residence_neighborhood_id'),
            'residence_address' => $value('residence_address'),
            'residence_landmark' => $value('residence_landmark'),
            'emergency_contact_name' => $value('emergency_contact_name'),
            'emergency_contact_relation' => $value('emergency_contact_relation'),
            'emergency_contact_dial_code' => $emergencyDial,
            'emergency_contact_phone_number' => $emergencyNumber,
            'trade_id' => $value('trade_id'),
            'experience_years' => $integer('experience_years'),
            'secondary_trade_id' => $value('secondary_trade_id'),
            'secondary_experience_years' => $value('secondary_trade_id') ? $integer('secondary_experience_years') : null,
            'specialty_ids' => array_values(array_filter((array) $request->input('specialty_ids', []))),
            'skill_ids' => array_values(array_filter((array) $request->input('skill_ids', []))),
            'daily_rate' => $integer('daily_rate'),
            'mobility_radius_km' => $integer('mobility_radius_km'),
            'transport_mode' => $value('transport_mode'),
            'accepts_long_travel' => $request->boolean('accepts_long_travel'),
            'needs_accommodation' => $request->boolean('needs_accommodation'),
            'works_solo' => $request->boolean('works_solo'),
            'works_in_team' => $request->boolean('works_in_team'),
            'accepted_offer_types' => array_values((array) $request->input('accepted_offer_types', [])),
            'accepted_payment_frequencies' => array_values((array) $request->input('accepted_payment_frequencies', [])),
            'references' => array_values(array_map(fn (array $r) => array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $r), array_filter((array) $request->input('references', []), fn ($r) => is_array($r) && trim((string) ($r['project'] ?? '')) !== ''))),
        ];
    }

    /**
     * Appel de l’API ; ses erreurs de validation sont replacées sur les champs du formulaire, et un refus sur la
     * fiche (clé worker) devient une notification.
     *
     * @param  callable(): ApiResponse  $call
     */
    private function send(callable $call): ApiResponse
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $field => $messages) {
                $target = self::ERROR_FIELDS[$field] ?? match (true) {
                    str_starts_with($field, 'verso') => 'verso',
                    str_starts_with($field, 'recto') => 'recto',
                    str_starts_with($field, 'specialty_ids') => 'specialty_ids',
                    str_starts_with($field, 'skill_ids') => 'skill_ids',
                    default => $field,
                };
                $errors[$target] ??= $messages[0];
            }

            if (isset($errors['worker'])) {
                Inertia::flash('toastError', $errors['worker']);
                unset($errors['worker']);
            }

            throw ValidationException::withMessages($errors ?: ['form' => 'La modification a été refusée.']);
        }
    }
}
