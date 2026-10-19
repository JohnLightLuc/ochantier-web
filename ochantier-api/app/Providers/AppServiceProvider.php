<?php

namespace App\Providers;

use App\Models;
use App\Support\Auth\JwtAuthenticator;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\SimulatedPaymentGateway;
use App\Support\PhoneNumber;
use App\Support\Sms\LogSmsGateway;
use App\Support\Sms\SmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            default => new LogSmsGateway,
        });

        $this->app->bind(PaymentGateway::class, fn () => match (true) {
            $this->app->isProduction() => throw new RuntimeException('Aucun prestataire de paiement configuré pour la production (PAYMENT_DRIVER).'),
            default => new SimulatedPaymentGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Horodatages à la microseconde, comme les TIMESTAMPTZ du schéma.
        Builder::defaultTimePrecision(null);

        Relation::enforceMorphMap(self::morphMap());

        Auth::viaRequest('jwt', fn (Request $request) => $this->app->make(JwtAuthenticator::class)($request));

        // Un commercial n’accède qu’à ses propres prospects : ceux des autres sont introuvables (404).
        Route::bind('prospect', fn (string $id) => Str::isUuid($id)
            ? Models\Prospect::query()->whereKey($id)->where('commercial_id', request()->user()?->commercialProfile?->getKey())->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\Prospect::class, [$id]));

        // Un promoteur n’accède qu’à ses propres chantiers : ceux des autres sont introuvables (404).
        Route::bind('site', fn (string $id) => Str::isUuid($id)
            ? Models\Site::query()->whereKey($id)->whereHas('promoterProfile', fn ($query) => $query->where('user_id', request()->user()?->getKey()))->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\Site::class, [$id]));

        Route::bind('reservation', fn (string $id) => Str::isUuid($id)
            ? Models\Offer::query()->whereKey($id)->whereHas('site.promoterProfile', fn ($query) => $query->where('user_id', request()->user()?->getKey()))->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\Offer::class, [$id]));

        Route::bind('proposal', fn (string $id) => Str::isUuid($id)
            ? Models\Proposal::query()->whereKey($id)->whereHas('site.promoterProfile', fn ($query) => $query->where('user_id', request()->user()?->getKey()))->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\Proposal::class, [$id]));

        Route::bind('schedule', fn (string $id) => Str::isUuid($id)
            ? Models\PaymentSchedule::query()->whereKey($id)->whereHas('mission.site.promoterProfile', fn ($query) => $query->where('user_id', request()->user()?->getKey()))->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\PaymentSchedule::class, [$id]));

        Route::bind('transaction', fn (string $id) => Str::isUuid($id)
            ? Models\PaymentTransaction::query()->whereKey($id)->where('payer_type', 'promoter_profile')->whereIn('payer_id', Models\PromoterProfile::query()->where('user_id', request()->user()?->getKey())->select('id'))->firstOrFail()
            : throw (new ModelNotFoundException)->setModel(Models\PaymentTransaction::class, [$id]));

        Route::bind('media', fn (string $id) => Str::isUuid($id)
            ? Models\MediaFile::query()->findOrFail($id)
            : throw (new ModelNotFoundException)->setModel(Models\MediaFile::class, [$id]));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getMorphClass().$request->user()?->getKey() ?: $request->ip()));

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by('otp:'.(self::requestedPhone($request) ?? mb_strtolower((string) $request->input('email')))),
            Limit::perHour(10)->by('otp-ip:'.$request->ip()),
        ]);

        // Au-dessus du seuil de verrouillage du compte (config auth.lockout) pour que le verrouillage s’applique d’abord.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login:'.self::requestedPhone($request)),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }

    /**
     * Numéro saisi (dial_code et phone_number) au format E.164, clé des limites d’envoi et de connexion.
     */
    private static function requestedPhone(Request $request): ?string
    {
        return PhoneNumber::from($request->input('dial_code'), $request->input('phone_number'))?->e164();
    }

    /**
     * Alias stockés dans les colonnes polymorphes (<nom>_type) pour chaque modèle.
     *
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        return [
            'admin' => Models\Admin::class,
            'admin_ip_allowlist' => Models\AdminIpAllowlist::class,
            'admin_permission' => Models\AdminPermission::class,
            'admin_role' => Models\AdminRole::class,
            'app_version' => Models\AppVersion::class,
            'approval' => Models\Approval::class,
            'assignment' => Models\Assignment::class,
            'assignment_status_history' => Models\AssignmentStatusHistory::class,
            'attachment' => Models\Attachment::class,
            'attendance_anomaly' => Models\AttendanceAnomaly::class,
            'attendance_correction' => Models\AttendanceCorrection::class,
            'attendance_event' => Models\AttendanceEvent::class,
            'auth_session' => Models\AuthSession::class,
            'broadcast_campaign' => Models\BroadcastCampaign::class,
            'city' => Models\City::class,
            'claim' => Models\Claim::class,
            'commercial_adjustment' => Models\CommercialAdjustment::class,
            'commercial_appreciation' => Models\CommercialAppreciation::class,
            'commercial_earning' => Models\CommercialEarning::class,
            'commercial_earning_rule' => Models\CommercialEarningRule::class,
            'commercial_objective' => Models\CommercialObjective::class,
            'commercial_payout' => Models\CommercialPayout::class,
            'commercial_profile' => Models\CommercialProfile::class,
            'commercial_ranking' => Models\CommercialRanking::class,
            'commercial_ranking_entry' => Models\CommercialRankingEntry::class,
            'commercial_zone' => Models\CommercialZone::class,
            'commune' => Models\Commune::class,
            'consent' => Models\Consent::class,
            'construction_type' => Models\ConstructionType::class,
            'country' => Models\Country::class,
            'data_export' => Models\DataExport::class,
            'data_retention_policy' => Models\DataRetentionPolicy::class,
            'device_token' => Models\DeviceToken::class,
            'dispute' => Models\Dispute::class,
            'dispute_message' => Models\DisputeMessage::class,
            'dispute_party' => Models\DisputeParty::class,
            'document' => Models\Document::class,
            'document_type' => Models\DocumentType::class,
            'domain_event' => Models\DomainEvent::class,
            'faq_article' => Models\FaqArticle::class,
            'financial_exception' => Models\FinancialException::class,
            'financial_period_closing' => Models\FinancialPeriodClosing::class,
            'idempotency_key' => Models\IdempotencyKey::class,
            'integration' => Models\Integration::class,
            'invitation' => Models\Invitation::class,
            'legal_document' => Models\LegalDocument::class,
            'login_attempt' => Models\LoginAttempt::class,
            'matching_cycle' => Models\MatchingCycle::class,
            'matching_derogation' => Models\MatchingDerogation::class,
            'matching_exclusion' => Models\MatchingExclusion::class,
            'matching_run' => Models\MatchingRun::class,
            'matching_weight_set' => Models\MatchingWeightSet::class,
            'media_file' => Models\MediaFile::class,
            'mission' => Models\Mission::class,
            'mission_status_history' => Models\MissionStatusHistory::class,
            'need' => Models\Need::class,
            'need_requested_worker' => Models\NeedRequestedWorker::class,
            'need_certification' => Models\NeedCertification::class,
            'need_status_history' => Models\NeedStatusHistory::class,
            'neighborhood' => Models\Neighborhood::class,
            'notification' => Models\Notification::class,
            'notification_preference' => Models\NotificationPreference::class,
            'notification_rule' => Models\NotificationRule::class,
            'notification_template' => Models\NotificationTemplate::class,
            'offer' => Models\Offer::class,
            'offer_milestone' => Models\OfferMilestone::class,
            'offer_status_history' => Models\OfferStatusHistory::class,
            'organization' => Models\Organization::class,
            'organization_member' => Models\OrganizationMember::class,
            'otp_code' => Models\OtpCode::class,
            'password_reset' => Models\PasswordReset::class,
            'payment_allocation' => Models\PaymentAllocation::class,
            'payment_method' => Models\PaymentMethod::class,
            'payment_operator' => Models\PaymentOperator::class,
            'payment_schedule' => Models\PaymentSchedule::class,
            'payment_schedule_line' => Models\PaymentScheduleLine::class,
            'payment_transaction' => Models\PaymentTransaction::class,
            'payment_transaction_status_history' => Models\PaymentTransactionStatusHistory::class,
            'payment_webhook_event' => Models\PaymentWebhookEvent::class,
            'payout_adjustment' => Models\PayoutAdjustment::class,
            'pricing_parameter' => Models\PricingParameter::class,
            'pricing_snapshot' => Models\PricingSnapshot::class,
            'progress_declaration' => Models\ProgressDeclaration::class,
            'promoter_profile' => Models\PromoterProfile::class,
            'proposal' => Models\Proposal::class,
            'prospect' => Models\Prospect::class,
            'prospect_followup' => Models\ProspectFollowup::class,
            'rating' => Models\Rating::class,
            'rating_version' => Models\RatingVersion::class,
            'reason_code' => Models\ReasonCode::class,
            'receipt' => Models\Receipt::class,
            'reconciliation_item' => Models\ReconciliationItem::class,
            'reconciliation_run' => Models\ReconciliationRun::class,
            'refund' => Models\Refund::class,
            'replacement' => Models\Replacement::class,
            'replacement_status_history' => Models\ReplacementStatusHistory::class,
            'report_run' => Models\ReportRun::class,
            'report_schedule' => Models\ReportSchedule::class,
            'saved_view' => Models\SavedView::class,
            'selection' => Models\Selection::class,
            'setting' => Models\Setting::class,
            'setting_history' => Models\SettingHistory::class,
            'site' => Models\Site::class,
            'site_incident' => Models\SiteIncident::class,
            'site_member' => Models\SiteMember::class,
            'site_status_history' => Models\SiteStatusHistory::class,
            'skill' => Models\Skill::class,
            'specialty' => Models\Specialty::class,
            'subscription' => Models\Subscription::class,
            'subscription_invoice' => Models\SubscriptionInvoice::class,
            'subscription_plan' => Models\SubscriptionPlan::class,
            'subscription_status_history' => Models\SubscriptionStatusHistory::class,
            'suggestion' => Models\Suggestion::class,
            'suggestion_score_history' => Models\SuggestionScoreHistory::class,
            'team' => Models\Team::class,
            'ticket' => Models\Ticket::class,
            'ticket_escalation' => Models\TicketEscalation::class,
            'ticket_message' => Models\TicketMessage::class,
            'trade' => Models\Trade::class,
            'unavailability_request' => Models\UnavailabilityRequest::class,
            'user' => Models\User::class,
            'verification_correction' => Models\VerificationCorrection::class,
            'verification_review' => Models\VerificationReview::class,
            'work_day' => Models\WorkDay::class,
            'worker' => Models\Worker::class,
            'worker_availability' => Models\WorkerAvailability::class,
            'worker_certification' => Models\WorkerCertification::class,
            'worker_commercial_transfer' => Models\WorkerCommercialTransfer::class,
            'worker_duplicate_candidate' => Models\WorkerDuplicateCandidate::class,
            'worker_merge' => Models\WorkerMerge::class,
            'worker_offer_contact' => Models\WorkerOfferContact::class,
            'worker_payout' => Models\WorkerPayout::class,
            'worker_payout_batch' => Models\WorkerPayoutBatch::class,
            'worker_portfolio_item' => Models\WorkerPortfolioItem::class,
            'worker_reference' => Models\WorkerReference::class,
            'worker_reservation' => Models\WorkerReservation::class,
            'worker_response' => Models\WorkerResponse::class,
            'worker_sanction' => Models\WorkerSanction::class,
            'worker_status_history' => Models\WorkerStatusHistory::class,
            'worker_trade' => Models\WorkerTrade::class,
        ];
    }
}
