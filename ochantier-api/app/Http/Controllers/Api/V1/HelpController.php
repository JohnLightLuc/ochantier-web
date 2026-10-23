<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\FaqArticle;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Centre d’aide » : questions fréquentes de l’application et contacts du support.
 */
class HelpController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $audience = $request->user()->user_type === UserType::Commercial ? 'commercial' : 'promoter';
        $settings = Setting::query()->where('key', 'like', 'support.%')->pluck('value', 'key');

        $faq = FaqArticle::query()
            ->whereIn('audience', [$audience, 'all'])
            ->where('is_published', true)
            ->orderBy('section')
            ->orderBy('created_at')
            ->get()
            ->map(fn (FaqArticle $article) => [
                'id' => $article->id,
                'section' => $article->section,
                'question' => $article->title,
                'answer' => $article->content,
                'context_key' => $article->context_key,
            ]);

        return ApiResponse::success([
            'faq' => $faq->values(),
            'support' => [
                'phone' => $settings['support.phone'] ?? null,
                'whatsapp' => $settings['support.whatsapp'] ?? null,
                'email' => $settings['support.email'] ?? null,
                'hours' => $settings['support.hours'] ?? null,
            ],
        ], headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
