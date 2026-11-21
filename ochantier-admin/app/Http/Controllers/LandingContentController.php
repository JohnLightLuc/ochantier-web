<?php

namespace App\Http\Controllers;

use App\Support\LandingContentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing Page, éditeur du site : contenu section par section, ordre et affichage des sections, publication en
 * versions et restauration, lus et enregistrés dans l’API (api/v1/admin/landing/content).
 */
class LandingContentController extends LandingPageController
{
    public function edit(Request $request, ?string $section = null): Response|RedirectResponse
    {
        abort_unless($this->allowed($request, 'content.read'), 403);
        $shell = $this->shell($request);
        $keys = array_column($shell['editor']['schema'], 'key');

        if ($section === null || ! in_array($section, $keys, true)) {
            return redirect()->route('landing.content', ['section' => 'cover']);
        }

        return Inertia::render('Landing/Content', [
            'tab' => 'contenu',
            ...Arr::except($shell, ['editor', 'overview']),
            'editor' => LandingContentPresenter::editor($shell['editor'], $section),
        ]);
    }

    public function versions(Request $request): Response
    {
        abort_unless($this->allowed($request, 'content.read'), 403);
        $page = $request->query('page');
        $response = $this->api->get('landing/content/versions', array_filter(['page' => is_string($page) ? $page : null, 'per_page' => 20]));
        $meta = $response->json('meta');
        $rows = array_map(LandingContentPresenter::version(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Landing/Publications', [
            'tab' => 'publications',
            ...Arr::except($this->shell($request), ['editor', 'overview']),
            'list' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
        ]);
    }

    public function save(Request $request, string $section): RedirectResponse
    {
        return $this->send(fn () => $this->api->put("landing/content/sections/{$section}", ['values' => (array) $request->input('values', [])]));
    }

    public function layout(Request $request): RedirectResponse
    {
        return $this->send(fn () => $this->api->put('landing/content/layout', ['order' => (array) $request->input('order', []), 'hidden' => array_values((array) $request->input('hidden', []))]));
    }

    public function reset(string $section): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("landing/content/sections/{$section}/reset"));
    }

    public function discard(): RedirectResponse
    {
        return $this->send(fn () => $this->api->post('landing/content/discard'));
    }

    public function publish(Request $request): RedirectResponse
    {
        return $this->send(fn () => $this->api->post('landing/content/publish', ['note' => trim((string) $request->input('note')) ?: null]));
    }

    public function restore(int $number): RedirectResponse
    {
        return $this->send(fn () => $this->api->post("landing/content/versions/{$number}/restore"));
    }

    /**
     * Envoie un fichier (vidéo, logo, QR code, image de partage) ; l’éditeur le place ensuite dans son champ.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file'], 'accept' => ['required', 'string']], ['file.required' => 'Choisissez un fichier.']);

        try {
            $response = $this->api->upload('landing/content/files', ['accept' => (string) $request->input('accept')], ['file' => $request->file('file')]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['file' => collect($e->errors())->flatten()->first() ?? 'Ce fichier a été refusé.']);
        }

        Inertia::flash('upload', ['field' => (string) $request->input('field'), ...$response->json('data')]);

        return back();
    }
}
