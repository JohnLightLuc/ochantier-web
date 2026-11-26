<?php

namespace App\Support\Matching;

use App\Enums\SuggestionStatus;
use App\Models\Need;
use App\Models\Suggestion;
use Carbon\CarbonImmutable;

/**
 * Un ouvrier ne tient qu’un poste à la fois : sur une même offre, il ne peut pas être retenu (d’accord) sur deux
 * besoins dont les périodes se chevauchent.
 */
class WorkerOverlap
{
    /**
     * Autre poste de la même offre, aux dates qui se chevauchent, sur lequel l’ouvrier est déjà d’accord.
     */
    public function retainedElsewhere(Suggestion $suggestion): ?Suggestion
    {
        $need = $suggestion->need;

        return Suggestion::query()
            ->with('need.trade')
            ->where('worker_id', $suggestion->worker_id)
            ->where('status', SuggestionStatus::Accepte)
            ->whereKeyNot($suggestion->getKey())
            ->where('need_id', '!=', $need->getKey())
            ->whereHas('need', fn ($query) => $query->where('offer_id', $need->offer_id)->whereIn('status', MatchingRunner::OPEN_NEEDS))
            ->get()
            ->first(fn (Suggestion $other) => $this->overlaps($need, $other->need));
    }

    public function overlaps(Need $a, Need $b): bool
    {
        [$startA, $endA] = $this->period($a);
        [$startB, $endB] = $this->period($b);

        return $startA->lte($endB) && $startB->lte($endA);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(Need $need): array
    {
        $start = CarbonImmutable::parse($need->start_date);

        return [$start, $need->end_date ? CarbonImmutable::parse($need->end_date) : $start->addDays(max(0, (int) $need->duration_days - 1))];
    }

    public function postLabel(Need $need): string
    {
        return ($need->trade?->label ?? $need->title).' ('.$need->reference.')';
    }
}
