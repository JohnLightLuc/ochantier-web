<?php

use App\Http\Controllers\Api\V1\Auth\CommercialLoginController;
use App\Http\Controllers\Api\V1\Auth\ContractorLoginController;
use App\Http\Controllers\Api\V1\Auth\ContractorPasswordController;
use App\Http\Controllers\Api\V1\Auth\ContractorRegistrationController;
use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\PromoterLoginController;
use App\Http\Controllers\Api\V1\Auth\PromoterRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Commercial\AccountController as CommercialAccountController;
use App\Http\Controllers\Api\V1\Commercial\EarningController as CommercialEarningController;
use App\Http\Controllers\Api\V1\Commercial\HomeController as CommercialHomeController;
use App\Http\Controllers\Api\V1\Commercial\NotificationController as CommercialNotificationController;
use App\Http\Controllers\Api\V1\Commercial\PerformanceController as CommercialPerformanceController;
use App\Http\Controllers\Api\V1\Commercial\ProspectController;
use App\Http\Controllers\Api\V1\Commercial\ProspectDocumentController;
use App\Http\Controllers\Api\V1\Commercial\WorkerController as CommercialWorkerController;
use App\Http\Controllers\Api\V1\Contractor\AttendanceController as ContractorAttendanceController;
use App\Http\Controllers\Api\V1\Contractor\ClosureController as ContractorClosureController;
use App\Http\Controllers\Api\V1\Contractor\OfferResponseController as ContractorOfferResponseController;
use App\Http\Controllers\Api\V1\Contractor\OpportunityController as ContractorOpportunityController;
use App\Http\Controllers\Api\V1\Contractor\ProfileController as ContractorProfileController;
use App\Http\Controllers\Api\V1\Contractor\ProfileDocumentController as ContractorProfileDocumentController;
use App\Http\Controllers\Api\V1\Contractor\QuoteProposalController as ContractorQuoteProposalController;
use App\Http\Controllers\Api\V1\Contractor\SiteController as ContractorSiteController;
use App\Http\Controllers\Api\V1\Contractor\StaffingController as ContractorStaffingController;
use App\Http\Controllers\Api\V1\Contractor\TeamController as ContractorTeamController;
use App\Http\Controllers\Api\V1\HelpController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Promoter\AttendanceController;
use App\Http\Controllers\Api\V1\Promoter\ClaimController;
use App\Http\Controllers\Api\V1\Promoter\ContractorOfferController;
use App\Http\Controllers\Api\V1\Promoter\DisputeController;
use App\Http\Controllers\Api\V1\Promoter\HomeController;
use App\Http\Controllers\Api\V1\Promoter\NotificationController;
use App\Http\Controllers\Api\V1\Promoter\PaymentController;
use App\Http\Controllers\Api\V1\Promoter\ProfileController as PromoterProfileController;
use App\Http\Controllers\Api\V1\Promoter\ProfileDocumentController;
use App\Http\Controllers\Api\V1\Promoter\QuoteRequestController;
use App\Http\Controllers\Api\V1\Promoter\RatingController;
use App\Http\Controllers\Api\V1\Promoter\ReferralController;
use App\Http\Controllers\Api\V1\Promoter\ReplacementController;
use App\Http\Controllers\Api\V1\Promoter\ReservationController;
use App\Http\Controllers\Api\V1\Promoter\SiteController;
use App\Http\Controllers\Api\V1\Promoter\SiteMediaController;
use App\Http\Controllers\Api\V1\Promoter\SiteOfferController;
use App\Http\Controllers\Api\V1\Promoter\SiteReportController;
use App\Http\Controllers\Api\V1\Promoter\SiteStepController;
use App\Http\Controllers\Api\V1\Promoter\SiteTrackingController;
use App\Http\Controllers\Api\V1\Promoter\SubscriptionController;
use App\Http\Controllers\Api\V1\Promoter\TeamController;
use App\Http\Controllers\Api\V1\Promoter\WorkerCatalogController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API des applications mobiles O’CHANTIER — v1
|--------------------------------------------------------------------------
|
| Applications Promoteur, Commercial et Entrepreneurs et Techniciens (Ouvrier en V2). Jetons JWT adossés aux sessions (auth_sessions) :
| jeton d’accès court envoyé en Bearer, jeton de rafraîchissement à usage unique.
|
| Promoteur : inscription et connexion sans mot de passe, par code SMS ou e-mail.
| Commercial : compte créé par le Back Office, connexion par mot de passe puis code SMS.
| Entrepreneur ou technicien (contractor) : inscription par téléphone ou e-mail et mot de passe, activée par un code ;
| connexion par mot de passe ; mot de passe oublié par code SMS ou e-mail.
| Espace commercial (prefix commercial) : prospects et dossiers ouvriers, ouvriers transmis et corrections, gains,
| accueil, objectifs et classement, profil et zone, préférences et notifications ; contrat actif exigé.
| Espace promoteur (prefix promoter) : profil, vérification, préférences, chantiers, réservations, paiements et suivi
| (équipe, pointage, chronologie, remplacements), évaluations, factures et rapport de fin de chantier, abonnement,
| utilisateurs et activité, accueil, notifications et parrainage. Chaque route exige un droit du rôle de l’utilisateur dans le compte (promoter.can, voir
| PromoterAccess::ABILITIES) ; les actions d’écriture sont inscrites au journal d’activité (promoter.activity).
|
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::prefix('promoter')->name('promoter.')->group(function () {
            Route::post('register', [PromoterRegistrationController::class, 'store'])->middleware('throttle:otp')->name('register');
            Route::post('register/verify', [PromoterRegistrationController::class, 'verify'])->middleware('throttle:10,1')->name('register.verify');
            Route::post('login', [PromoterLoginController::class, 'store'])->middleware('throttle:otp')->name('login');
            Route::post('login/verify', [PromoterLoginController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');
        });

        Route::prefix('commercial')->name('commercial.')->group(function () {
            Route::post('login', [CommercialLoginController::class, 'store'])->middleware('throttle:login')->name('login');
            Route::post('login/verify', [CommercialLoginController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');
        });

        Route::prefix('contractor')->name('contractor.')->group(function () {
            Route::post('register', [ContractorRegistrationController::class, 'store'])->middleware('throttle:otp')->name('register');
            Route::post('register/verify', [ContractorRegistrationController::class, 'verify'])->middleware('throttle:10,1')->name('register.verify');
            Route::post('login', [ContractorLoginController::class, 'store'])->middleware('throttle:login')->name('login');
            Route::post('password/forgot', [ContractorPasswordController::class, 'forgot'])->middleware('throttle:otp')->name('password.forgot');
            Route::post('password/verify', [ContractorPasswordController::class, 'verify'])->middleware('throttle:10,1')->name('password.verify');
            Route::post('password/reset', [ContractorPasswordController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
        });

        Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:otp')->name('password.forgot');
        Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
        Route::post('refresh', [SessionController::class, 'refresh'])->middleware('throttle:30,1')->name('refresh');
    });

    Route::middleware('auth:api')->group(function () {
        // Accessibles avant le remplacement du mot de passe provisoire.
        Route::get('me', [ProfileController::class, 'show'])->name('me');
        Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
        Route::put('auth/password', [PasswordController::class, 'update'])->middleware('user.type:commercial,contractor')->name('auth.password.update');

        Route::middleware('password.changed')->group(function () {
            Route::get('auth/sessions', [SessionController::class, 'index'])->name('auth.sessions.index');
            Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy'])->name('auth.sessions.destroy');

            Route::post('me/phone', [ProfileController::class, 'requestPhoneChange'])->middleware('throttle:otp')->name('me.phone');
            Route::post('me/phone/verify', [ProfileController::class, 'confirmPhoneChange'])->middleware('throttle:10,1')->name('me.phone.verify');

            Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
            Route::delete('devices/{token}', [DeviceController::class, 'destroy'])->where('token', '.*')->name('devices.destroy');

            Route::get('referentials', ReferenceController::class)->name('referentials');
            Route::get('help', HelpController::class)->name('help');

            // Application Promoteur : profil, pièces justificatives, préférences, chantiers, réservations, paiements, suivi et bilan.
            Route::middleware(['user.type:promoter', 'promoter.activity'])->prefix('promoter')->name('promoter.')->group(function () {
                // Tous les utilisateurs du compte : consultation (le technicien ne voit que ses chantiers).
                Route::middleware('promoter.can:sites.view')->group(function () {
                    Route::get('home', HomeController::class)->name('home');
                    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
                    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
                    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
                    Route::get('referrals', [ReferralController::class, 'show'])->name('referrals.show');
                    Route::post('referrals', [ReferralController::class, 'store'])->middleware('throttle:10,1')->name('referrals.store');
                    Route::get('profile', [PromoterProfileController::class, 'show'])->name('profile.show');
                    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
                    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');
                    Route::get('sites/form-options', [SiteController::class, 'formOptions'])->name('sites.form-options');
                    Route::apiResource('sites', SiteController::class)->only(['index', 'show']);
                    Route::get('workers', WorkerCatalogController::class)->name('workers.index');
                    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
                    Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
                    Route::get('sites/{site}/tracking', [SiteTrackingController::class, 'show'])->name('sites.tracking');
                    Route::get('sites/{site}/team', [SiteTrackingController::class, 'team'])->name('sites.team');
                    Route::get('sites/{site}/attendance', [AttendanceController::class, 'index'])->name('sites.attendance.index');
                    Route::get('sites/{site}/attendance/history', [AttendanceController::class, 'history'])->name('sites.attendance.history');
                    Route::get('sites/{site}/steps', [SiteStepController::class, 'index'])->name('sites.steps.index');
                    Route::get('sites/{site}/quote-request', [QuoteRequestController::class, 'show'])->name('sites.quote-request.show');
                    Route::get('sites/{site}/contractor-offers', [ContractorOfferController::class, 'index'])->name('sites.contractor-offers.index');
                    Route::get('replacements/{replacement}', [ReplacementController::class, 'show'])->name('replacements.show');
                    Route::get('sites/{site}/ratings', [RatingController::class, 'site'])->name('sites.ratings.index');
                    Route::get('ratings', [RatingController::class, 'index'])->name('ratings.index');
                });

                // Chantiers, besoins et réservations : propriétaire, administrateur, chef de chantier.
                Route::middleware('promoter.can:sites.manage')->group(function () {
                    Route::apiResource('sites', SiteController::class)->only(['store', 'update', 'destroy']);
                    Route::post('sites/{site}/photo', [SiteMediaController::class, 'storePhoto'])->name('sites.photo.store');
                    Route::delete('sites/{site}/photo', [SiteMediaController::class, 'destroyPhoto'])->name('sites.photo.destroy');
                    Route::post('sites/{site}/documents', [SiteMediaController::class, 'storeDocument'])->name('sites.documents.store');
                    Route::delete('sites/{site}/documents/{document}', [SiteMediaController::class, 'destroyDocument'])->name('sites.documents.destroy');
                    Route::put('sites/{site}/offer', [SiteOfferController::class, 'update'])->name('sites.offer.update');
                    Route::post('sites/{site}/publish', [SiteOfferController::class, 'publish'])->name('sites.publish');
                    Route::delete('sites/{site}/quote-request', [QuoteRequestController::class, 'destroy'])->name('sites.quote-request.destroy');
                    Route::post('sites/{site}/quote-request/visits/{bid}/reschedule', [QuoteRequestController::class, 'decideReschedule'])->name('sites.quote-request.visits.reschedule');
                    Route::post('sites/{site}/quote-request/quotes/{version}/acknowledge', [QuoteRequestController::class, 'acknowledge'])->name('sites.quote-request.quotes.acknowledge');
                    Route::post('sites/{site}/contractor-offers/{bid}/choose', [ContractorOfferController::class, 'choose'])->whereUuid('bid')->name('sites.contractor-offers.choose');
                    Route::post('sites/{site}/reservations', [ReservationController::class, 'store'])->name('sites.reservations.store');
                });

                Route::middleware('promoter.can:proposals.decide')->group(function () {
                    Route::post('proposals/{proposal}/accept', [ReservationController::class, 'accept'])->name('proposals.accept');
                    Route::post('proposals/{proposal}/refuse', [ReservationController::class, 'refuse'])->name('proposals.refuse');
                });

                // Suivi : pointage et chronologie (y compris le technicien sur ses chantiers), remplacements, avis.
                Route::middleware('promoter.can:attendance.manage')->group(function () {
                    Route::post('sites/{site}/attendance/events', [AttendanceController::class, 'storeEvent'])->name('sites.attendance.events.store');
                    Route::post('sites/{site}/attendance/absences', [AttendanceController::class, 'storeAbsence'])->name('sites.attendance.absences.store');
                    Route::post('sites/{site}/attendance/{date}/validate', [AttendanceController::class, 'validateDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('sites.attendance.validate');
                });
                Route::middleware('promoter.can:steps.manage')->scopeBindings()->group(function () {
                    Route::post('sites/{site}/steps', [SiteStepController::class, 'store'])->name('sites.steps.store');
                    Route::post('sites/{site}/steps/{step}/start', [SiteStepController::class, 'start'])->name('sites.steps.start');
                    Route::post('sites/{site}/steps/{step}/complete', [SiteStepController::class, 'complete'])->name('sites.steps.complete');
                    Route::delete('sites/{site}/steps/{step}', [SiteStepController::class, 'destroy'])->name('sites.steps.destroy');
                });
                Route::middleware('promoter.can:replacements.manage')->group(function () {
                    Route::post('assignments/{assignment}/replacement', [ReplacementController::class, 'store'])->name('assignments.replacement.store');
                    Route::post('replacements/{replacement}/accept', [ReplacementController::class, 'accept'])->name('replacements.accept');
                    Route::post('replacements/{replacement}/refuse', [ReplacementController::class, 'refuse'])->name('replacements.refuse');
                });
                Route::middleware('promoter.can:claims.manage')->group(function () {
                    Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
                    Route::post('claims', [ClaimController::class, 'store'])->name('claims.store');
                    Route::get('claims/{claimId}', [ClaimController::class, 'show'])->name('claims.show');
                    Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
                    Route::get('disputes/{disputeId}', [DisputeController::class, 'show'])->name('disputes.show');
                    Route::post('disputes/{disputeId}/messages', [DisputeController::class, 'message'])->name('disputes.messages');
                    Route::post('disputes/{disputeId}/proofs', [DisputeController::class, 'proof'])->name('disputes.proofs');
                    Route::post('disputes/{disputeId}/appeal', [DisputeController::class, 'appeal'])->name('disputes.appeal');
                });
                Route::middleware('promoter.can:ratings.manage')->group(function () {
                    Route::post('sites/{site}/ratings', [RatingController::class, 'store'])->name('sites.ratings.store');
                    Route::put('ratings/{rating}', [RatingController::class, 'update'])->name('ratings.update');
                });

                // Finances : paiements (propriétaire, administrateur, comptable), factures et rapport.
                Route::middleware('promoter.can:finance.view')->group(function () {
                    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
                    Route::get('payments/schedules/{schedule}', [PaymentController::class, 'show'])->name('payments.show');
                    Route::get('payments/transactions/{transaction}', [PaymentController::class, 'transaction'])->name('payments.transactions.show');
                    Route::get('sites/{site}/invoices', [SiteReportController::class, 'invoices'])->name('sites.invoices');
                    Route::get('sites/{site}/report', [SiteReportController::class, 'show'])->name('sites.report');
                    Route::get('sites/{site}/report/pdf', [SiteReportController::class, 'pdf'])->name('sites.report.pdf');
                });
                Route::middleware('promoter.can:payments.manage')->group(function () {
                    Route::get('payments/options', [PaymentController::class, 'options'])->name('payments.options');
                    Route::post('payments/quote', [PaymentController::class, 'quote'])->name('payments.quote');
                    Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
                });

                // Équipe : utilisateurs et rôles, activité (propriétaire, administrateur).
                Route::middleware('promoter.can:team.manage')->group(function () {
                    Route::get('team', [TeamController::class, 'index'])->name('team.index');
                    Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invitations.store');
                    Route::patch('team/members/{member}', [TeamController::class, 'update'])->whereUuid('member')->name('team.members.update');
                    Route::delete('team/members/{member}', [TeamController::class, 'destroy'])->whereUuid('member')->name('team.members.destroy');
                });
                Route::get('team/activity', [TeamController::class, 'activity'])->middleware('promoter.can:activity.view')->name('team.activity');

                // Compte : profil du compte et abonnement (propriétaire).
                Route::middleware('promoter.can:account.manage')->group(function () {
                    Route::patch('profile', [PromoterProfileController::class, 'update'])->name('profile.update');
                    Route::post('profile/photo', [PromoterProfileController::class, 'storePhoto'])->name('profile.photo.store');
                    Route::delete('profile/photo', [PromoterProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
                    Route::post('profile/documents', [ProfileDocumentController::class, 'store'])->name('profile.documents.store');
                    Route::delete('profile/documents/{document}', [ProfileDocumentController::class, 'destroy'])->name('profile.documents.destroy');
                    Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
                    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
                });
            });

            // Application Commercial : prospects, dossiers ouvriers, gains, activité, profil et notifications.
            // Application Entrepreneurs et Techniciens : choix du profil, dossier de vérification (KYB ou KYC) et opportunités.
            Route::middleware('user.type:contractor')->prefix('contractor')->name('contractor.')->group(function () {
                Route::get('profile', [ContractorProfileController::class, 'show'])->name('profile.show');
                Route::post('profile', [ContractorProfileController::class, 'store'])->name('profile.store');
                Route::patch('profile', [ContractorProfileController::class, 'update'])->name('profile.update');
                Route::post('profile/submit', [ContractorProfileController::class, 'submit'])->middleware('throttle:10,1')->name('profile.submit');
                Route::post('profile/photo', [ContractorProfileController::class, 'storePhoto'])->name('profile.photo.store');
                Route::delete('profile/photo', [ContractorProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
                Route::post('profile/documents', [ContractorProfileDocumentController::class, 'store'])->name('profile.documents.store');
                Route::delete('profile/documents/{document}', [ContractorProfileDocumentController::class, 'destroy'])->name('profile.documents.destroy');

                Route::get('opportunities', [ContractorOpportunityController::class, 'index'])->name('opportunities.index');
                Route::get('opportunities/quotes/{quote}', [ContractorOpportunityController::class, 'showQuote'])->name('opportunities.quotes.show');
                Route::get('opportunities/offers/{offer}', [ContractorOpportunityController::class, 'showOffer'])->name('opportunities.offers.show');
                Route::post('opportunities/quotes/{quote}/proposal', [ContractorQuoteProposalController::class, 'store'])->middleware('throttle:20,1')->name('opportunities.quotes.proposal');
                Route::post('opportunities/offers/{offer}/response', [ContractorOfferResponseController::class, 'store'])->middleware('throttle:20,1')->name('opportunities.offers.response');

                Route::get('my-offers', [ContractorOfferResponseController::class, 'index'])->name('my-offers.index');
                Route::get('offer-responses/{offerResponse}', [ContractorOfferResponseController::class, 'show'])->name('offer-responses.show');
                Route::delete('offer-responses/{offerResponse}', [ContractorOfferResponseController::class, 'destroy'])->name('offer-responses.destroy');

                Route::get('payments/installments', [ContractorStaffingController::class, 'installments'])->name('payments.installments');
                Route::post('payments/installments/{installment}/quote', [ContractorStaffingController::class, 'installmentQuote'])->name('payments.installments.quote');
                Route::post('payments/installments/{installment}/pay', [ContractorStaffingController::class, 'payInstallment'])->middleware('throttle:10,1')->name('payments.installments.pay');
                Route::put('ratings/{contractorRating}', [ContractorClosureController::class, 'update'])->name('ratings.update');
                Route::get('workers', [ContractorTeamController::class, 'catalog'])->name('workers.index');
                Route::get('workers/{catalogWorker}', [ContractorTeamController::class, 'worker'])->name('workers.show');
                Route::get('sites', [ContractorSiteController::class, 'index'])->name('sites.index');
                Route::prefix('sites/{contractorSite}')->name('sites.')->group(function () {
                    Route::get('/', [ContractorSiteController::class, 'show'])->name('show');
                    Route::get('payments', [ContractorSiteController::class, 'payments'])->name('payments');
                    Route::get('documents', [ContractorSiteController::class, 'documents'])->name('documents');
                    Route::get('timeline', [ContractorSiteController::class, 'timeline'])->name('timeline');
                    Route::post('steps', [ContractorSiteController::class, 'storeStep'])->name('steps.store');
                    Route::post('steps/{step}/start', [ContractorSiteController::class, 'startStep'])->name('steps.start');
                    Route::post('steps/{step}/complete', [ContractorSiteController::class, 'completeStep'])->name('steps.complete');
                    Route::delete('steps/{step}', [ContractorSiteController::class, 'destroyStep'])->name('steps.destroy');
                    Route::get('team', [ContractorTeamController::class, 'index'])->name('team.index');
                    Route::post('assignments', [ContractorTeamController::class, 'store'])->name('assignments.store');
                    Route::get('assignments/{teamAssignment}', [ContractorTeamController::class, 'show'])->name('assignments.show');
                    Route::post('assignments/{teamAssignment}/replace', [ContractorTeamController::class, 'replace'])->name('assignments.replace');
                    Route::post('assignments/{teamAssignment}/cancel', [ContractorTeamController::class, 'cancel'])->name('assignments.cancel');
                    Route::get('attendance', [ContractorAttendanceController::class, 'index'])->name('attendance.index');
                    Route::get('attendance/history', [ContractorAttendanceController::class, 'history'])->name('attendance.history');
                    Route::post('attendance/events', [ContractorAttendanceController::class, 'storeEvent'])->name('attendance.events.store');
                    Route::post('attendance/absences', [ContractorAttendanceController::class, 'storeAbsence'])->name('attendance.absences.store');
                    Route::post('attendance/{date}/validate', [ContractorAttendanceController::class, 'validateDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('attendance.validate');
                    Route::get('attendance/days/{workDay}', [ContractorAttendanceController::class, 'show'])->name('attendance.days.show');
                    Route::post('attendance/days/{workDay}/correction', [ContractorAttendanceController::class, 'correct'])->name('attendance.days.correct');
                    Route::get('ratings', [ContractorClosureController::class, 'ratings'])->name('ratings.index');
                    Route::post('ratings', [ContractorClosureController::class, 'rate'])->name('ratings.store');
                    Route::get('report', [ContractorClosureController::class, 'report'])->name('report');
                    Route::get('report/pdf', [ContractorClosureController::class, 'pdf'])->name('report.pdf');
                    Route::get('staffing-requests', [ContractorStaffingController::class, 'index'])->name('staffing.index');
                    Route::post('staffing-requests', [ContractorStaffingController::class, 'store'])->name('staffing.store');
                    Route::get('staffing-requests/{staffingRequest}', [ContractorStaffingController::class, 'show'])->name('staffing.show');
                    Route::post('staffing-requests/{staffingRequest}/payment-quote', [ContractorStaffingController::class, 'quote'])->name('staffing.quote');
                    Route::post('staffing-requests/{staffingRequest}/pay', [ContractorStaffingController::class, 'pay'])->middleware('throttle:10,1')->name('staffing.pay');
                    Route::post('staffing-requests/{staffingRequest}/refuse', [ContractorStaffingController::class, 'refuse'])->name('staffing.refuse');
                    Route::post('staffing-requests/{staffingRequest}/cancel', [ContractorStaffingController::class, 'cancel'])->name('staffing.cancel');
                });

                Route::get('quote-proposals', [ContractorQuoteProposalController::class, 'index'])->name('quote-proposals.index');
                Route::prefix('quote-proposals/{quoteProposal}')->name('quote-proposals.')->group(function () {
                    Route::get('/', [ContractorQuoteProposalController::class, 'show'])->name('show');
                    Route::post('withdraw', [ContractorQuoteProposalController::class, 'withdraw'])->name('withdraw');
                    Route::post('visit/done', [ContractorQuoteProposalController::class, 'visited'])->name('visit.done');
                    Route::post('visit/reschedule', [ContractorQuoteProposalController::class, 'reschedule'])->name('visit.reschedule');
                    Route::post('quotes', [ContractorQuoteProposalController::class, 'storeVersion'])->name('quotes.store');
                    Route::patch('quotes/{version}', [ContractorQuoteProposalController::class, 'updateVersion'])->name('quotes.update');
                    Route::post('quotes/{version}/send', [ContractorQuoteProposalController::class, 'sendVersion'])->name('quotes.send');
                });
            });

            Route::middleware(['user.type:commercial', 'commercial'])->prefix('commercial')->name('commercial.')->group(function () {
                Route::get('prospects/phone-check', [ProspectController::class, 'checkPhone'])->name('prospects.phone-check');
                Route::apiResource('prospects', ProspectController::class)->except('destroy');
                Route::post('prospects/{prospect}/abandon', [ProspectController::class, 'abandon'])->name('prospects.abandon');
                Route::post('prospects/{prospect}/submit', [ProspectController::class, 'submit'])->name('prospects.submit');
                Route::post('prospects/{prospect}/documents', [ProspectDocumentController::class, 'store'])->name('prospects.documents.store');
                Route::delete('prospects/{prospect}/documents/{document}', [ProspectDocumentController::class, 'destroy'])->name('prospects.documents.destroy');

                Route::get('home', CommercialHomeController::class)->name('home');
                Route::get('workers', [CommercialWorkerController::class, 'index'])->name('workers.index');
                Route::get('earnings', [CommercialEarningController::class, 'index'])->name('earnings.index');
                Route::get('objectives', [CommercialPerformanceController::class, 'objectives'])->name('objectives');
                Route::get('ranking', [CommercialPerformanceController::class, 'ranking'])->name('ranking');
                Route::get('profile', [CommercialAccountController::class, 'show'])->name('profile.show');
                Route::patch('profile', [CommercialAccountController::class, 'update'])->name('profile.update');
                Route::post('zone-requests', [CommercialAccountController::class, 'requestZone'])->name('zone-requests.store');
                Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
                Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');
                Route::get('notifications', [CommercialNotificationController::class, 'index'])->name('notifications.index');
                Route::post('notifications/read-all', [CommercialNotificationController::class, 'readAll'])->name('notifications.read-all');
                Route::post('notifications/{notification}/read', [CommercialNotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
            });
        });
    });

    // Fichiers : lien signé à durée limitée fourni dans les réponses de l’API.
    Route::get('media/{media}', MediaController::class)->middleware('signed')->name('media.show');
});
