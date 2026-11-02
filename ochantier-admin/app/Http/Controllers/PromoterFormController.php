<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\PromoterPresenter;
use Illuminate\Http\Client\Response as ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Création et modification d’un promoteur, et ajout d’une pièce à son dossier, par l’API
 * (api/v1/admin/promoters).
 *
 * Le formulaire envoie des champs à plat (company_legal_name, hq_address…) ; ils sont regroupés pour l’API, et les
 * erreurs de l’API sont renvoyées sur les champs du formulaire.
 */
class PromoterFormController extends Controller
{
    /**
     * Champ de l’API => champ du formulaire.
     */
    private const ERROR_FIELDS = [
        'dial_code' => 'phone',
        'phone_number' => 'phone',
        'company' => 'company_legal_name',
        'company.legal_name' => 'company_legal_name',
        'company.trade_name' => 'company_trade_name',
        'company.legal_form' => 'company_legal_form',
        'company.rccm' => 'company_rccm',
        'company.ncc' => 'company_ncc',
        'company.headquarters' => 'hq_address',
        'company.headquarters.address' => 'hq_address',
        'company.headquarters.commune_id' => 'hq_commune_id',
        'company.headquarters.neighborhood_id' => 'hq_neighborhood_id',
        'document_type' => 'document_type',
        'file' => 'recto',
    ];

    private const LEGAL_FORMS = ['SA', 'SARL', 'SARLU', 'SAS', 'SASU', 'SNC', 'GIE', 'Entreprise individuelle', 'Autre'];

    public function __construct(private AdminApi $api) {}

    public function create(): Response
    {
        return $this->form('create', null, [
            'profile_kind' => 'entreprise',
            'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'job_title' => '',
            'address' => '', 'commune_id' => '', 'neighborhood_id' => '',
            'company_legal_name' => '', 'company_trade_name' => '', 'company_legal_form' => '', 'company_rccm' => '', 'company_ncc' => '',
            'hq_address' => '', 'hq_commune_id' => '', 'hq_neighborhood_id' => '',
            'send_invitation' => true,
        ], true);
    }

    public function store(Request $request): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->post('promoters', $this->payload($request)));
        Inertia::flash('toast', $response->json('message'));

        return to_route('promoters.show', $response->json('data.id'));
    }

    public function edit(string $promoter): Response
    {
        $data = $this->api->get("promoters/{$promoter}")->json('data');
        $company = $data['company'] ?? null;

        return $this->form('edit', ['id' => $data['id'], 'name' => PromoterPresenter::promoter($data)['name']], [
            'profile_kind' => $data['kind']['value'],
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'phone' => ($data['phone_number'] ?? null) ? '+'.$data['dial_code'].' '.trim(chunk_split((string) $data['phone_number'], 2, ' ')) : '',
            'email' => (string) ($data['email'] ?? ''),
            'job_title' => (string) ($data['job_title'] ?? ''),
            'address' => (string) ($data['address']['address'] ?? ''),
            'commune_id' => (string) ($data['address']['commune']['id'] ?? ''),
            'neighborhood_id' => (string) ($data['address']['neighborhood']['id'] ?? ''),
            'company_legal_name' => (string) ($company['legal_name'] ?? ''),
            'company_trade_name' => (string) ($company['trade_name'] ?? ''),
            'company_legal_form' => (string) ($company['legal_form'] ?? ''),
            'company_rccm' => (string) ($company['rccm'] ?? ''),
            'company_ncc' => (string) ($company['ncc'] ?? ''),
            'hq_address' => (string) ($company['headquarters']['address'] ?? ''),
            'hq_commune_id' => (string) ($company['headquarters']['commune']['id'] ?? ''),
            'hq_neighborhood_id' => (string) ($company['headquarters']['neighborhood']['id'] ?? ''),
            'send_invitation' => false,
        ], (bool) ($data['verification']['can_change_kind'] ?? false));
    }

    public function update(Request $request, string $promoter): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->patch("promoters/{$promoter}", $this->payload($request)));
        Inertia::flash('toast', $response->json('message'));

        return to_route('promoters.show', $promoter);
    }

    /**
     * Pièce ajoutée au nom du promoteur (recto et verso, ou un seul fichier selon le type).
     */
    public function storeDocument(Request $request, string $promoter): RedirectResponse
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

        $response = $this->send(fn () => $this->api->upload("promoters/{$promoter}/documents", $request->only(['document_type', 'number', 'expires_at']), $files));
        Inertia::flash('toast', $response->json('message'));

        return to_route('promoters.show', [$promoter, 'kyc']);
    }

    /**
     * Listes du formulaire, communes aux administrateurs : gardées 10 minutes.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return Cache::remember('promoters.form-options', 600, fn () => $this->api->get('promoters/form-options')->json('data'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function form(string $mode, ?array $promoter, array $values, bool $canChangeKind): Response
    {
        $options = $this->formOptions();
        $communes = collect($options['cities'])->flatMap(fn (array $city) => array_map(fn (array $commune) => [...$commune, 'city' => $city['name']], $city['communes']));

        return Inertia::render('Promoters/Form', [
            'mode' => $mode,
            'promoter' => $promoter,
            'values' => $values,
            'canChangeKind' => $canChangeKind,
            'options' => [
                'profile_kind' => array_map(fn (array $kind) => ['value' => $kind['value'], 'label' => $kind['label']], $options['profile_kinds']),
                'commune' => $communes->map(fn (array $c) => ['value' => $c['id'], 'label' => $c['city'] === $c['name'] ? $c['name'] : "{$c['name']} · {$c['city']}"])->values()->all(),
                'legal_form' => array_map(fn (string $form) => ['value' => $form, 'label' => $form], self::LEGAL_FORMS),
            ],
            'neighborhoods' => $communes->mapWithKeys(fn (array $c) => [$c['id'] => array_map(fn (array $n) => ['value' => $n['id'], 'label' => $n['name']], $c['neighborhoods'])])->all(),
            'header' => $this->header($mode === 'create' ? 'Nouveau promoteur' : 'Modifier le promoteur', '', array_values(array_filter([
                ['Promoteurs', '/promoteurs'],
                $promoter ? [$promoter['name'], "/promoteurs/{$promoter['id']}"] : null,
                [$mode === 'create' ? 'Nouveau promoteur' : 'Modifier'],
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
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        $number = str_starts_with($digits, '225') && strlen($digits) > 10 ? substr($digits, 3) : $digits;
        $company = $request->input('profile_kind') === 'entreprise';

        return [
            'first_name' => $value('first_name'),
            'last_name' => $value('last_name'),
            'dial_code' => $number !== '' ? '225' : null,
            'phone_number' => $number !== '' ? $number : null,
            'email' => $value('email'),
            'profile_kind' => $value('profile_kind'),
            'job_title' => $value('job_title'),
            'address' => $value('address'),
            'commune_id' => $value('commune_id'),
            'neighborhood_id' => $value('neighborhood_id'),
            ...($request->has('send_invitation') ? ['send_invitation' => $request->boolean('send_invitation')] : []),
            ...($company ? ['company' => [
                'legal_name' => $value('company_legal_name'),
                'trade_name' => $value('company_trade_name'),
                'legal_form' => $value('company_legal_form'),
                'rccm' => $value('company_rccm'),
                'ncc' => $value('company_ncc'),
                'headquarters' => $value('hq_address') ? ['address' => $value('hq_address'), 'commune_id' => $value('hq_commune_id'), 'neighborhood_id' => $value('hq_neighborhood_id')] : null,
            ]] : []),
        ];
    }

    /**
     * Appel de l’API ; ses erreurs de validation sont replacées sur les champs du formulaire.
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
                $target = self::ERROR_FIELDS[$field] ?? (str_starts_with($field, 'verso') ? 'verso' : (str_starts_with($field, 'recto') ? 'recto' : $field));
                $errors[$target] ??= $messages[0];
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
