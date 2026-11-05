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
 * Création et modification d’un commercial, et ajout d’une pièce à son dossier, par l’API
 * (api/v1/admin/commercials). Les zones se choisissent à la création.
 */
class SalesAgentFormController extends Controller
{
    private const ERROR_FIELDS = ['dial_code' => 'phone', 'phone_number' => 'phone', 'file' => 'recto'];

    public function __construct(private AdminApi $api) {}

    public function create(): Response
    {
        return $this->form('create', null, [
            'first_name' => '', 'last_name' => '', 'phone' => '', 'secondary_phone' => '', 'email' => '', 'address' => '',
            'started_at' => today()->format('Y-m-d'), 'supervisor_id' => '', 'is_supervisor' => false,
            'primary_commune_id' => '', 'secondary_commune_ids' => [], 'send_link' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->post('commercials', [
            ...$this->payload($request),
            'primary_commune_id' => $request->input('primary_commune_id') ?: null,
            'secondary_commune_ids' => array_values(array_filter((array) $request->input('secondary_commune_ids', []))),
            'send_link' => $request->boolean('send_link'),
        ]));
        Inertia::flash('toast', $response->json('message'));

        return to_route('sales-agents.show', [$response->json('data.id'), 'acces-securite']);
    }

    public function edit(string $commercial): Response
    {
        $c = $this->api->get("commercials/{$commercial}")->json('data');

        return $this->form('edit', ['id' => $c['id'], 'name' => $c['full_name'], 'code' => $c['employee_number']], [
            'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'phone' => (string) $c['phone'], 'secondary_phone' => (string) $c['secondary_phone'],
            'email' => (string) $c['email'], 'address' => (string) $c['address'], 'started_at' => (string) $c['started_at'],
            'supervisor_id' => (string) ($c['supervisor']['id'] ?? ''), 'is_supervisor' => $c['is_supervisor'],
            'primary_commune_id' => '', 'secondary_commune_ids' => [], 'send_link' => false,
        ]);
    }

    public function update(Request $request, string $commercial): RedirectResponse
    {
        $response = $this->send(fn () => $this->api->patch("commercials/{$commercial}", $this->payload($request)));
        Inertia::flash('toast', $response->json('message'));

        return to_route('sales-agents.show', $commercial);
    }

    public function storeDocument(Request $request, string $commercial): RedirectResponse
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

        $response = $this->send(fn () => $this->api->upload("commercials/{$commercial}/documents", array_filter($request->only(['document_type', 'number', 'expires_at'])), $files));
        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    /**
     * Types de pièces du dossier d’un commercial.
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
        ], $this->options()['document_types']);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return Cache::remember('commercials.form-options', 600, fn () => $this->api->get('commercials/form-options')->json('data'));
    }

    /**
     * @param  array<string, mixed>|null  $agent
     * @param  array<string, mixed>  $values
     */
    private function form(string $mode, ?array $agent, array $values): Response
    {
        $options = $this->options();
        $communes = collect($options['cities'])->flatMap(fn (array $city) => array_map(fn (array $commune) => ['value' => $commune['id'], 'label' => $city['name'] === $commune['name'] ? $commune['name'] : "{$commune['name']} · {$city['name']}"], $city['communes']));
        $supervisors = array_map(fn (array $s) => ['value' => $s['id'], 'label' => $s['full_name']], $options['supervisors']);

        return Inertia::render('SalesAgents/Form', [
            'mode' => $mode,
            'agent' => $agent,
            'values' => $values,
            'options' => [
                'supervisor' => array_values(array_filter($supervisors, fn (array $s) => $s['value'] !== ($agent['id'] ?? null))),
                'commune' => $communes->values()->all(),
            ],
            'header' => $this->header($mode === 'create' ? 'Ajouter un commercial' : 'Modifier un commercial', $agent ? "{$agent['name']} · {$agent['code']}" : 'Les champs marqués * sont obligatoires.', [['Commerciaux', '/commerciaux'], ...($agent ? [[$agent['name'], "/commerciaux/{$agent['id']}"]] : []), [$mode === 'create' ? 'Nouveau commercial' : 'Modifier']]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $value = fn (string $key): ?string => ($v = trim((string) $request->input($key))) === '' ? null : $v;
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        $number = str_starts_with($digits, '225') && strlen($digits) > 10 ? substr($digits, 3) : $digits;

        return [
            'first_name' => $value('first_name'), 'last_name' => $value('last_name'),
            'dial_code' => $number === '' ? null : '225', 'phone_number' => $number === '' ? null : $number,
            'secondary_phone' => $value('secondary_phone'), 'email' => $value('email'), 'address' => $value('address'),
            'started_at' => $value('started_at'), 'supervisor_id' => $value('supervisor_id'), 'is_supervisor' => $request->boolean('is_supervisor'),
        ];
    }

    /**
     * Appel de l’API ; ses erreurs sont replacées sur les champs du formulaire, un refus sur la fiche devient une
     * notification.
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
                    str_starts_with($field, 'secondary_commune_ids') => 'secondary_commune_ids',
                    str_starts_with($field, 'verso') => 'verso',
                    str_starts_with($field, 'recto') => 'recto',
                    default => $field,
                };
                $errors[$target] ??= $messages[0];
            }

            if (isset($errors['commercial'])) {
                Inertia::flash('toastError', $errors['commercial']);
                unset($errors['commercial']);
            }

            throw ValidationException::withMessages($errors ?: ['form' => 'La modification a été refusée.']);
        }
    }
}
