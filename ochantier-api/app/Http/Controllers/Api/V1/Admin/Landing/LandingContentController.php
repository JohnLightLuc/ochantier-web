<?php

namespace App\Http\Controllers\Api\V1\Admin\Landing;

use App\Http\Controllers\Controller;
use App\Models\LandingContentVersion;
use App\Support\Admin\AdminAudit;
use App\Support\ApiResponse;
use App\Support\Landing\LandingContent;
use App\Support\Landing\LandingContentSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Éditeur de contenu du site public : brouillon par section, ordre et affichage des sections, publication en
 * versions et restauration d’une version antérieure.
 */
class LandingContentController extends Controller
{
    public function __construct(private LandingContent $content) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->content->editor());
    }

    public function saveSection(Request $request, string $section): JsonResponse
    {
        abort_unless(array_key_exists($section, LandingContentSchema::SECTIONS), 404);
        $values = $request->validate(['values' => ['required', 'array']])['values'];
        $values = validator($values, LandingContentSchema::rules($section), $this->messages())->validate();
        $this->content->saveSection($request->user(), $section, $values);

        return ApiResponse::success($this->content->editor(), 'Brouillon enregistré.');
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'size:'.count(LandingContentSchema::MIDDLE)],
            'order.*' => ['required', 'distinct', Rule::in(LandingContentSchema::MIDDLE)],
            'hidden' => ['present', 'array'],
            'hidden.*' => [Rule::in(LandingContentSchema::HIDEABLE)],
        ]);
        $this->content->saveLayout($request->user(), $data['order'], $data['hidden']);

        return ApiResponse::success($this->content->editor(), 'Brouillon enregistré.');
    }

    public function resetSection(Request $request, string $section): JsonResponse
    {
        abort_unless($section === 'layout' || array_key_exists($section, LandingContentSchema::SECTIONS), 404);
        $this->content->resetSection($request->user(), $section);

        return ApiResponse::success($this->content->editor(), 'Section remise à la version en ligne.');
    }

    public function discard(Request $request): JsonResponse
    {
        $this->content->discard($request->user());
        AdminAudit::record($request->user(), 'content', 'landing.discard', $request);

        return ApiResponse::success($this->content->editor(), 'Modifications annulées.');
    }

    public function publish(Request $request): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $version = $this->content->publish($request->user(), $note !== null ? trim($note) : null);
        AdminAudit::record($request->user(), 'content', 'landing.publish', $request, $version, null, ['number' => $version->number, 'sections' => $version->changed_sections, 'note' => $version->note]);

        return ApiResponse::success($this->content->editor(), "Version {$version->number} publiée : le site est à jour.");
    }

    public function versions(Request $request): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 20);
        $rows = LandingContentVersion::query()->with('publishedBy')->orderByDesc('number')->paginate($perPage)->withQueryString();
        $current = LandingContentVersion::query()->max('number');

        return ApiResponse::success(collect($rows->items())->map(fn (LandingContentVersion $v) => [
            'number' => $v->number,
            'note' => $v->note,
            'sections' => array_map(LandingContentSchema::label(...), $v->changed_sections),
            'restored_from' => $v->restored_from,
            'published_by' => $v->publishedBy?->fullName(),
            'published_at' => $v->published_at->toIso8601String(),
            'live' => $v->number === $current,
        ])->all(), meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }

    public function restore(Request $request, int $number): JsonResponse
    {
        $version = LandingContentVersion::query()->where('number', $number)->firstOrFail();
        $this->content->restore($request->user(), $version);
        AdminAudit::record($request->user(), 'content', 'landing.restore', $request, $version, null, ['number' => $version->number]);

        return ApiResponse::success($this->content->editor(), "Version {$version->number} restaurée dans le brouillon : publiez pour mettre le site à jour.");
    }

    public function upload(Request $request): JsonResponse
    {
        $accept = $request->validate(['accept' => ['required', Rule::in(array_keys(LandingContentSchema::FILE_RULES))]])['accept'];
        [$mimes, $maxKb] = LandingContentSchema::FILE_RULES[$accept];
        $request->validate(['file' => ['required', 'file', 'mimetypes:'.implode(',', $mimes), 'max:'.$maxKb]], [
            'file.mimetypes' => $accept === 'video/mp4' ? 'Envoyez une vidéo MP4.' : 'Envoyez une image JPEG, PNG, WebP ou SVG.',
            'file.max' => 'Le fichier dépasse '.intdiv($maxKb, 1024).' Mo.',
        ]);

        return ApiResponse::success($this->content->upload($request->user(), $request->file('file')), 'Fichier envoyé.', 201);
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long (:max caractères ou éléments au plus).',
            'min' => 'Ajoutez au moins :min élément(s).',
            'url' => 'Saisissez une adresse commençant par https://.',
            'email' => 'Saisissez une adresse e-mail valide.',
            'in' => 'Choisissez une valeur proposée.',
        ];
    }
}
