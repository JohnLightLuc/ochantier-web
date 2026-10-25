<?php

use App\Http\Controllers\Api\V1\Auth\CommercialLoginController;
use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\PromoterLoginController;
use App\Http\Controllers\Api\V1\Auth\PromoterRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Commercial\ProspectController;
use App\Http\Controllers\Api\V1\Commercial\ProspectDocumentController;
use App\Http\Controllers\Api\V1\HelpController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Promoter\AttendanceController;
use App\Http\Controllers\Api\V1\Promoter\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Promoter\PaymentController;
use App\Http\Controllers\Api\V1\Promoter\ProfileController as PromoterProfileController;
use App\Http\Controllers\Api\V1\Promoter\ProfileDocumentController;
use App\Http\Controllers\Api\V1\Promoter\RatingController;
use App\Http\Controllers\Api\V1\Promoter\ReplacementController;
use App\Http\Controllers\Api\V1\Promoter\ReservationController;
use App\Http\Controllers\Api\V1\Promoter\SiteController;
use App\Http\Controllers\Api\V1\Promoter\SiteMediaController;
use App\Http\Controllers\Api\V1\Promoter\SiteOfferController;
use App\Http\Controllers\Api\V1\Promoter\SiteReportController;
use App\Http\Controllers\Api\V1\Promoter\SiteStepController;
use App\Http\Controllers\Api\V1\Promoter\SiteTrackingController;
use App\Http\Controllers\Api\V1\Promoter\SubscriptionController;
use App\Http\Controllers\Api\V1\Promoter\WorkerCatalogController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API des applications mobiles O’CHANTIER — v1
|--------------------------------------------------------------------------
|
| Applications Promoteur et Commercial (Ouvrier en V2). Jetons JWT adossés aux sessions (auth_sessions) :
| jeton d’accès court envoyé en Bearer, jeton de rafraîchissement à usage unique.
|
| Promoteur : inscription et connexion sans mot de passe, par code SMS ou e-mail.
| Commercial : compte créé par le Back Office, connexion par mot de passe puis code SMS.
| Espace commercial (prefix commercial) : prospects et dossiers ouvriers, contrat actif exigé.
| Espace promoteur (prefix promoter) : profil, vérification, préférences, chantiers, réservations, paiements et suivi
| (équipe, pointage, chronologie, remplacements), évaluations, factures et rapport de fin de chantier, abonnement.
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

        Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:otp')->name('password.forgot');
        Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
        Route::post('refresh', [SessionController::class, 'refresh'])->middleware('throttle:30,1')->name('refresh');
    });

    Route::middleware('auth:api')->group(function () {
        // Accessibles avant le remplacement du mot de passe provisoire.
        Route::get('me', [ProfileController::class, 'show'])->name('me');
        Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
        Route::put('auth/password', [PasswordController::class, 'update'])->middleware('user.type:commercial')->name('auth.password.update');

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
            Route::middleware('user.type:promoter')->prefix('promoter')->name('promoter.')->group(function () {
                Route::get('profile', [PromoterProfileController::class, 'show'])->name('profile.show');
                Route::patch('profile', [PromoterProfileController::class, 'update'])->name('profile.update');
                Route::post('profile/photo', [PromoterProfileController::class, 'storePhoto'])->name('profile.photo.store');
                Route::delete('profile/photo', [PromoterProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
                Route::post('profile/documents', [ProfileDocumentController::class, 'store'])->name('profile.documents.store');
                Route::delete('profile/documents/{document}', [ProfileDocumentController::class, 'destroy'])->name('profile.documents.destroy');
                Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
                Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

                Route::get('sites/form-options', [SiteController::class, 'formOptions'])->name('sites.form-options');
                Route::apiResource('sites', SiteController::class);
                Route::post('sites/{site}/photo', [SiteMediaController::class, 'storePhoto'])->name('sites.photo.store');
                Route::delete('sites/{site}/photo', [SiteMediaController::class, 'destroyPhoto'])->name('sites.photo.destroy');
                Route::post('sites/{site}/documents', [SiteMediaController::class, 'storeDocument'])->name('sites.documents.store');
                Route::delete('sites/{site}/documents/{document}', [SiteMediaController::class, 'destroyDocument'])->name('sites.documents.destroy');
                Route::put('sites/{site}/offer', [SiteOfferController::class, 'update'])->name('sites.offer.update');
                Route::post('sites/{site}/publish', [SiteOfferController::class, 'publish'])->name('sites.publish');
                Route::get('workers', WorkerCatalogController::class)->name('workers.index');
                Route::post('sites/{site}/reservations', [ReservationController::class, 'store'])->name('sites.reservations.store');
                Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
                Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
                Route::post('proposals/{proposal}/accept', [ReservationController::class, 'accept'])->name('proposals.accept');
                Route::post('proposals/{proposal}/refuse', [ReservationController::class, 'refuse'])->name('proposals.refuse');
                Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
                Route::get('payments/options', [PaymentController::class, 'options'])->name('payments.options');
                Route::post('payments/quote', [PaymentController::class, 'quote'])->name('payments.quote');
                Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
                Route::get('payments/schedules/{schedule}', [PaymentController::class, 'show'])->name('payments.show');
                Route::get('payments/transactions/{transaction}', [PaymentController::class, 'transaction'])->name('payments.transactions.show');

                Route::get('sites/{site}/tracking', [SiteTrackingController::class, 'show'])->name('sites.tracking');
                Route::get('sites/{site}/team', [SiteTrackingController::class, 'team'])->name('sites.team');
                Route::get('sites/{site}/attendance', [AttendanceController::class, 'index'])->name('sites.attendance.index');
                Route::get('sites/{site}/attendance/history', [AttendanceController::class, 'history'])->name('sites.attendance.history');
                Route::post('sites/{site}/attendance/events', [AttendanceController::class, 'storeEvent'])->name('sites.attendance.events.store');
                Route::post('sites/{site}/attendance/absences', [AttendanceController::class, 'storeAbsence'])->name('sites.attendance.absences.store');
                Route::post('sites/{site}/attendance/{date}/validate', [AttendanceController::class, 'validateDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('sites.attendance.validate');
                Route::scopeBindings()->group(function () {
                    Route::get('sites/{site}/steps', [SiteStepController::class, 'index'])->name('sites.steps.index');
                    Route::post('sites/{site}/steps', [SiteStepController::class, 'store'])->name('sites.steps.store');
                    Route::post('sites/{site}/steps/{step}/start', [SiteStepController::class, 'start'])->name('sites.steps.start');
                    Route::post('sites/{site}/steps/{step}/complete', [SiteStepController::class, 'complete'])->name('sites.steps.complete');
                    Route::delete('sites/{site}/steps/{step}', [SiteStepController::class, 'destroy'])->name('sites.steps.destroy');
                });
                Route::post('assignments/{assignment}/replacement', [ReplacementController::class, 'store'])->name('assignments.replacement.store');
                Route::get('replacements/{replacement}', [ReplacementController::class, 'show'])->name('replacements.show');
                Route::post('replacements/{replacement}/accept', [ReplacementController::class, 'accept'])->name('replacements.accept');
                Route::post('replacements/{replacement}/refuse', [ReplacementController::class, 'refuse'])->name('replacements.refuse');

                Route::get('sites/{site}/ratings', [RatingController::class, 'site'])->name('sites.ratings.index');
                Route::post('sites/{site}/ratings', [RatingController::class, 'store'])->name('sites.ratings.store');
                Route::get('ratings', [RatingController::class, 'index'])->name('ratings.index');
                Route::put('ratings/{rating}', [RatingController::class, 'update'])->name('ratings.update');
                Route::get('sites/{site}/invoices', [SiteReportController::class, 'invoices'])->name('sites.invoices');
                Route::get('sites/{site}/report', [SiteReportController::class, 'show'])->name('sites.report');
                Route::get('sites/{site}/report/pdf', [SiteReportController::class, 'pdf'])->name('sites.report.pdf');

                Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
                Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
            });

            // Application Commercial : prospects et dossiers ouvriers.
            Route::middleware(['user.type:commercial', 'commercial'])->prefix('commercial')->name('commercial.')->group(function () {
                Route::get('prospects/phone-check', [ProspectController::class, 'checkPhone'])->name('prospects.phone-check');
                Route::apiResource('prospects', ProspectController::class)->except('destroy');
                Route::post('prospects/{prospect}/abandon', [ProspectController::class, 'abandon'])->name('prospects.abandon');
                Route::post('prospects/{prospect}/submit', [ProspectController::class, 'submit'])->name('prospects.submit');
                Route::post('prospects/{prospect}/documents', [ProspectDocumentController::class, 'store'])->name('prospects.documents.store');
                Route::delete('prospects/{prospect}/documents/{document}', [ProspectDocumentController::class, 'destroy'])->name('prospects.documents.destroy');
            });
        });
    });

    // Fichiers : lien signé à durée limitée fourni dans les réponses de l’API.
    Route::get('media/{media}', MediaController::class)->middleware('signed')->name('media.show');
});
