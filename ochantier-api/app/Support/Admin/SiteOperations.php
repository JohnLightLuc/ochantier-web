<?php

namespace App\Support\Admin;

use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\Offer;
use App\Models\Site;
use App\Models\WorkDay;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\SiteClosure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur un chantier. La suspension bloque les nouveaux pointages et réservations ; l’équipe reste
 * affectée et les paiements ne bougent pas. L’annulation n’est possible qu’avant tout paiement.
 */
class SiteOperations
{
    /**
     * Annulable : aucun acompte n’est en jeu.
     */
    public const CANCELLABLE = [SiteStatus::Soumis, SiteStatus::Recherche];

    public const SUSPENDABLE = [SiteStatus::Confirme, SiteStatus::EnCours];

    public const CLOSABLE = [SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu];

    public const ARCHIVABLE = [SiteStatus::Termine, SiteStatus::Annule];

    public function __construct(private PromoterNotifier $notifier, private SiteClosure $closure) {}

    public function suspend(Site $site, Admin $admin, string $reason, Request $request): void
    {
        $this->ensure(in_array($site->status, self::SUSPENDABLE, true), 'Seul un chantier confirmé ou en cours peut être suspendu.');
        $before = $site->status;

        $this->changeStatus($site, SiteStatus::Suspendu, $reason, $admin);
        AdminAudit::record($admin, 'sites', 'site.suspended', $request, $site, ['status' => $before->value], ['status' => 'suspendu', 'reason' => $reason]);
        $this->notify($site, 'site.suspended', 'Chantier suspendu · '.$site->label, $reason.' Les pointages sont bloqués jusqu’à la reprise.');
    }

    public function resume(Site $site, Admin $admin, Request $request): void
    {
        $this->ensure($site->status === SiteStatus::Suspendu, 'Ce chantier n’est pas suspendu.');
        $target = $site->statusHistories()->where('to_status', SiteStatus::Suspendu->value)->latest()->latest('id')->first()?->from_status ?? SiteStatus::Confirme;

        $this->changeStatus($site, $target, 'Reprise du chantier', $admin);
        AdminAudit::record($admin, 'sites', 'site.resumed', $request, $site, ['status' => 'suspendu'], ['status' => $target->value]);
        $this->notify($site, 'site.resumed', 'Chantier repris · '.$site->label, 'Les pointages sont de nouveau ouverts.');
    }

    /**
     * Annule le chantier, ses offres et les propositions en attente.
     */
    public function cancel(Site $site, Admin $admin, string $reason, Request $request): void
    {
        $this->ensure(in_array($site->status, self::CANCELLABLE, true), 'Ce chantier ne peut plus être annulé : un paiement ou une équipe est engagé.');
        $before = $site->status;

        DB::transaction(function () use ($site, $admin, $reason): void {
            $this->changeStatus($site, SiteStatus::Annule, $reason, $admin, ['cancellation_reason' => $reason]);
            $site->proposals()->where('status', ProposalStatus::AValider)->update(['status' => ProposalStatus::Annulee]);

            foreach ($site->offers()->whereNotIn('status', [OfferStatus::Cloturee, OfferStatus::Annulee])->get() as $offer) {
                /** @var Offer $offer */
                $offer->statusHistories()->create(['from_status' => $offer->status->value, 'to_status' => OfferStatus::Annulee->value, 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
                $offer->update(['status' => OfferStatus::Annulee, 'cancellation_reason' => $reason]);
            }
        });

        AdminAudit::record($admin, 'sites', 'site.cancelled', $request, $site, ['status' => $before->value], ['status' => 'annule', 'reason' => $reason]);
        $this->notify($site, 'site.cancelled', 'Chantier annulé · '.$site->label, $reason);
    }

    /**
     * Les journées non validées doivent être traitées avant : la rémunération en dépend.
     */
    public function close(Site $site, Admin $admin, Request $request): void
    {
        $this->ensure(in_array($site->status, self::CLOSABLE, true), 'Seul un chantier confirmé, en cours ou suspendu peut être clôturé.');

        $pending = WorkDay::query()->whereIn('mission_id', $site->missions()->select('id'))->where('status', WorkDayStatus::AValider)->count();
        $this->ensure($pending === 0, $pending.' journée'.($pending > 1 ? 's' : '').' de pointage à valider avant la clôture.');

        $before = $site->status;
        $this->closure->close($site, 'Clôture par le Back Office', $admin);
        AdminAudit::record($admin, 'sites', 'site.closed', $request, $site, ['status' => $before->value], ['status' => 'termine']);
    }

    public function archive(Site $site, Admin $admin, Request $request): void
    {
        $this->ensure(in_array($site->status, self::ARCHIVABLE, true), 'Seul un chantier terminé ou annulé peut être archivé.');
        $this->ensure($site->archived_at === null, 'Ce chantier est déjà archivé.');

        $site->forceFill(['archived_at' => now()])->save();
        AdminAudit::record($admin, 'sites', 'site.archived', $request, $site);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function changeStatus(Site $site, SiteStatus $status, string $reason, Admin $admin, array $attributes = []): void
    {
        DB::transaction(function () use ($site, $status, $reason, $admin, $attributes): void {
            $site->statusHistories()->create(['from_status' => $site->status->value, 'to_status' => $status->value, 'reason' => $reason, 'actor_type' => $admin->getMorphClass(), 'actor_id' => $admin->getKey()]);
            $site->forceFill(['status' => $status, 'lock_version' => $site->lock_version + 1, ...$attributes])->save();
        });
    }

    private function notify(Site $site, string $event, string $title, string $body): void
    {
        $this->notifier->notify($site->promoterProfile, $event, $title, $body, $site, ['type' => 'site', 'id' => $site->id], $site, $event.':'.$site->id.':'.now()->timestamp);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['site' => $message]);
        }
    }
}
