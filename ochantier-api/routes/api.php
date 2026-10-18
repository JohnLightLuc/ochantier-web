<?php

use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Promoter;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\SalesAgent;
use App\Http\Controllers\Api\V1\Worker;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API des applications mobiles O’CHANTIER — v1
|--------------------------------------------------------------------------
|
| Une API pour les trois applications : Ouvrier (worker), Promoteur (promoter) et Commercial (sales_agent).
| Connexion par numéro de téléphone et code SMS ; le jeton Sanctum porte le profil de l’application.
|
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('reference', ReferenceController::class)->name('reference');

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('otp', [OtpController::class, 'store'])->middleware('throttle:otp')->name('otp');
        Route::post('verify', [OtpController::class, 'verify'])->middleware('throttle:10,1')->name('verify');
        Route::post('register/worker', [RegistrationController::class, 'worker'])->middleware('throttle:otp')->name('register.worker');
        Route::post('register/promoter', [RegistrationController::class, 'promoter'])->middleware('throttle:otp')->name('register.promoter');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [ProfileController::class, 'show'])->name('me');
        Route::post('auth/logout', [ProfileController::class, 'logout'])->name('auth.logout');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices/{token}', [DeviceController::class, 'destroy'])->name('devices.destroy');

        /*
         * Application Ouvrier
         */
        Route::prefix('worker')->name('worker.')->middleware('abilities:worker')->group(function () {
            Route::get('profile', [Worker\ProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [Worker\ProfileController::class, 'update'])->name('profile.update');
            Route::post('profile/submit', [Worker\ProfileController::class, 'submit'])->name('profile.submit');
            Route::patch('availability', [Worker\ProfileController::class, 'availability'])->name('availability');

            Route::get('documents', [Worker\DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents/{type}', [Worker\DocumentController::class, 'store'])->name('documents.store');

            Route::apiResource('experiences', Worker\ExperienceController::class)->except('show');
            Route::apiResource('periods', Worker\PeriodController::class)->except('show');

            Route::get('proposals', [Worker\ProposalController::class, 'index'])->name('proposals.index');
            Route::post('proposals/{assignment:reference}/accept', [Worker\ProposalController::class, 'accept'])->name('proposals.accept');
            Route::post('proposals/{assignment:reference}/refuse', [Worker\ProposalController::class, 'refuse'])->name('proposals.refuse');

            Route::get('missions', [Worker\MissionController::class, 'index'])->name('missions.index');
            Route::get('missions/{assignment:reference}', [Worker\MissionController::class, 'show'])->name('missions.show');

            Route::get('attendances', [Worker\AttendanceController::class, 'index'])->name('attendances.index');
            Route::get('attendances/{attendance}', [Worker\AttendanceController::class, 'show'])->name('attendances.show');

            Route::get('payments', [Worker\PaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/{payment}', [Worker\PaymentController::class, 'show'])->name('payments.show');

            Route::get('reviews', [Worker\ReviewController::class, 'index'])->name('reviews.index');

            Route::get('claims', [Worker\ClaimController::class, 'index'])->name('claims.index');
            Route::post('claims', [Worker\ClaimController::class, 'store'])->name('claims.store');
        });

        /*
         * Application Promoteur
         */
        Route::prefix('promoter')->name('promoter.')->middleware('abilities:promoter')->group(function () {
            Route::get('profile', [Promoter\ProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [Promoter\ProfileController::class, 'update'])->name('profile.update');

            Route::get('documents', [Promoter\DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents/{type}', [Promoter\DocumentController::class, 'store'])->name('documents.store');

            Route::apiResource('sites', Promoter\SiteController::class)->except('destroy');
            Route::get('sites/{site}/incidents', [Promoter\SiteIncidentController::class, 'index'])->name('sites.incidents.index');
            Route::post('sites/{site}/incidents', [Promoter\SiteIncidentController::class, 'store'])->name('sites.incidents.store');

            Route::get('needs', [Promoter\NeedController::class, 'index'])->name('needs.index');
            Route::post('needs', [Promoter\NeedController::class, 'store'])->name('needs.store');
            Route::get('needs/{need}', [Promoter\NeedController::class, 'show'])->name('needs.show');
            Route::post('needs/{need}/validate', [Promoter\NeedController::class, 'validateTeam'])->name('needs.validate');

            Route::get('attendances', [Promoter\AttendanceController::class, 'index'])->name('attendances.index');
            Route::get('attendances/{attendance}', [Promoter\AttendanceController::class, 'show'])->name('attendances.show');
            Route::post('attendances/{attendance}/{action}', [Promoter\AttendanceController::class, 'record'])
                ->whereIn('action', ['arrival', 'arrival-validation', 'departure', 'departure-validation', 'absence', 'absence-validation'])
                ->name('attendances.record');

            Route::get('payments', [Promoter\PaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/{payment}', [Promoter\PaymentController::class, 'show'])->name('payments.show');

            Route::get('subscription', [Promoter\SubscriptionController::class, 'show'])->name('subscription.show');
            Route::get('subscription/plans', [Promoter\SubscriptionController::class, 'plans'])->name('subscription.plans');
            Route::post('subscription/change', [Promoter\SubscriptionController::class, 'change'])->name('subscription.change');

            Route::get('reviews', [Promoter\ReviewController::class, 'index'])->name('reviews.index');

            Route::get('claims', [Promoter\ClaimController::class, 'index'])->name('claims.index');
            Route::post('claims', [Promoter\ClaimController::class, 'store'])->name('claims.store');
        });

        /*
         * Application Commercial
         */
        Route::prefix('sales-agent')->name('sales-agent.')->middleware('abilities:sales_agent')->group(function () {
            Route::get('profile', [SalesAgent\ProfileController::class, 'show'])->name('profile.show');
            Route::get('dashboard', [SalesAgent\DashboardController::class, 'show'])->name('dashboard');

            Route::get('workers', [SalesAgent\WorkerController::class, 'index'])->name('workers.index');
            Route::post('workers', [SalesAgent\WorkerController::class, 'store'])->name('workers.store');
            Route::get('workers/{worker}', [SalesAgent\WorkerController::class, 'show'])->name('workers.show');

            Route::get('field-activities', [SalesAgent\FieldActivityController::class, 'index'])->name('field-activities.index');
            Route::post('field-activities', [SalesAgent\FieldActivityController::class, 'store'])->name('field-activities.store');
        });
    });
});
