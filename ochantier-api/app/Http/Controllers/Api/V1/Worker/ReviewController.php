<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewResource;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Avis publiés sur les prestations de l’ouvrier.
 */
class ReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            Review::query()
                ->where('worker_id', $request->user()->id)
                ->whereIn('status', ['Publié', 'Restauré'])
                ->where('is_test', false)
                ->with('site')
                ->latest('date')
                ->paginate(20)
        )->additional(['meta' => ['rating' => $request->user()->rating, 'reviews_count' => $request->user()->reviews_count]]);
    }
}
