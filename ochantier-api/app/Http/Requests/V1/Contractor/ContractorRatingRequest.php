<?php

namespace App\Http\Requests\V1\Contractor;

use App\Http\Requests\V1\Promoter\RatingRequest;
use App\Models\Rating;

/**
 * Note d’un ouvrier ou de l’équipe par l’entrepreneur (mêmes règles que pour le promoteur). En modification, la
 * portée vient de l’avis de l’entrepreneur désigné par la route.
 */
class ContractorRatingRequest extends RatingRequest
{
    public function route($param = null, $default = null)
    {
        if ($param === 'rating' && ($id = parent::route('contractorRating'))) {
            return Rating::query()->whereKey($id)->where('author_user_id', $this->user()?->getKey())->first();
        }

        return parent::route($param, $default);
    }
}
