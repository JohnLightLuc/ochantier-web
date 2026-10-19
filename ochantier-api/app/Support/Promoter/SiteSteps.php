<?php

namespace App\Support\Promoter;

use App\Enums\SiteStatus;
use App\Enums\SiteStepStatus;
use App\Models\Site;
use App\Models\SiteStep;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Chronologie » du chantier : le promoteur ajoute les étapes de ses travaux, les démarre puis les valide une à une.
 * Disponible après le paiement de l’acompte, close à la fin du chantier.
 */
class SiteSteps
{
    /**
     * Chantiers dont la chronologie est modifiable.
     */
    public const EDITABLE = [SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu];

    /**
     * @return array<string, mixed>
     */
    public function summary(Site $site): array
    {
        $steps = $site->steps()->orderBy('sequence')->get();
        $editable = in_array($site->status, self::EDITABLE, true);
        $done = $steps->where('status', SiteStepStatus::Validee)->count();

        return [
            'editable' => $editable,
            'lock_message' => match (true) {
                $editable => null,
                in_array($site->status, [SiteStatus::Termine, SiteStatus::Annule], true) => 'Chantier terminé · chronologie clôturée',
                default => 'Disponible après validation de la réservation (paiement de l’acompte)',
            },
            'done' => $done,
            'total' => $steps->count(),
            'progress_percent' => $steps->isEmpty() ? 0 : (int) round($done / $steps->count() * 100),
            'count_label' => $steps->isEmpty() ? 'Aucune étape' : $done.'/'.$steps->count().' étapes validées',
            'steps' => $steps->map(fn (SiteStep $step) => $this->step($step, $editable))->values()->all(),
        ];
    }

    public function add(Site $site, User $author, string $title): SiteStep
    {
        $this->ensureEditable($site);

        return DB::transaction(fn () => $site->steps()->create([
            'sequence' => ((int) Site::query()->whereKey($site->getKey())->lockForUpdate()->first()->steps()->max('sequence')) + 1,
            'title' => $title,
            'status' => SiteStepStatus::AFaire,
            'created_by_user_id' => $author->getKey(),
        ]));
    }

    /**
     * « Démarrer » : une seule étape en cours à la fois.
     */
    public function start(SiteStep $step): SiteStep
    {
        $this->ensureEditable($step->site);

        if ($step->status !== SiteStepStatus::AFaire) {
            throw ValidationException::withMessages(['step' => 'Cette étape est déjà démarrée.']);
        }

        if ($running = $step->site->steps()->where('status', SiteStepStatus::EnCours)->first()) {
            throw ValidationException::withMessages(['step' => 'Validez d’abord « '.$running->title.' ».']);
        }

        return tap($step)->update(['status' => SiteStepStatus::EnCours, 'started_at' => now()]);
    }

    /**
     * « Valider l’étape ».
     */
    public function complete(SiteStep $step, User $author): SiteStep
    {
        $this->ensureEditable($step->site);

        if ($step->status !== SiteStepStatus::EnCours) {
            throw ValidationException::withMessages(['step' => $step->status === SiteStepStatus::Validee ? 'Cette étape est déjà validée.' : 'Démarrez d’abord cette étape.']);
        }

        return tap($step)->update(['status' => SiteStepStatus::Validee, 'completed_at' => now(), 'completed_by_user_id' => $author->getKey()]);
    }

    /**
     * « Retirer » : seulement une étape pas encore démarrée.
     */
    public function remove(SiteStep $step): void
    {
        $this->ensureEditable($step->site);

        if ($step->status !== SiteStepStatus::AFaire) {
            throw ValidationException::withMessages(['step' => 'Une étape démarrée ne peut plus être retirée.']);
        }

        $step->delete();
    }

    private function ensureEditable(Site $site): void
    {
        if (! in_array($site->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['step' => in_array($site->status, [SiteStatus::Termine, SiteStatus::Annule], true)
                ? 'Chantier terminé : la chronologie est clôturée.'
                : 'La chronologie sera disponible après le paiement de l’acompte.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function step(SiteStep $step, bool $editable): array
    {
        return [
            'id' => $step->id,
            'sequence' => $step->sequence,
            'title' => $step->title,
            'status' => ['value' => $step->status->value, 'label' => $step->status->label()],
            'started_at' => $step->started_at?->toIso8601String(),
            'completed_at' => $step->completed_at?->toIso8601String(),
            'subtitle' => match ($step->status) {
                SiteStepStatus::Validee => 'Validée le '.$this->date($step->completed_at).($step->started_at ? ' · démarrée le '.$this->date($step->started_at) : ''),
                SiteStepStatus::EnCours => 'En cours depuis le '.$this->date($step->started_at),
                SiteStepStatus::AFaire => 'À démarrer',
            },
            'actions' => ! $editable ? [] : match ($step->status) {
                SiteStepStatus::AFaire => ['demarrer', 'retirer'],
                SiteStepStatus::EnCours => ['valider'],
                SiteStepStatus::Validee => [],
            },
        ];
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
