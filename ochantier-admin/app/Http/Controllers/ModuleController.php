<?php

namespace App\Http\Controllers;

use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page d’attente des modules pas encore intégrés depuis la maquette.
 */
class ModuleController extends Controller
{
    public function __invoke(string $module, ?string $rest = null): Response
    {
        $definition = Arr::first(config('admin.modules'), fn (array $item): bool => $item['path'] === '/'.$module);

        abort_unless($definition, 404);

        return Inertia::render('ModulePlaceholder', [
            'module' => Arr::only($definition, ['key', 'label', 'icon', 'title', 'subtitle']),
        ]);
    }
}
