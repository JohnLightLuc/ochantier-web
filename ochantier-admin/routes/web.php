<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PromoterAccountController;
use App\Http\Controllers\PromoterController;
use App\Http\Controllers\PromoterDemoController;
use App\Http\Controllers\PromoterDocumentController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerDocumentController;
use App\Http\Controllers\WorkerMissionController;
use App\Http\Controllers\WorkerPeriodController;
use App\Http\Controllers\WorkerProfileController;
use App\Http\Controllers\WorkerReplacementController;
use App\Http\Controllers\WorkerReviewController;
use App\Http\Controllers\WorkerStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');

    Route::get('/connexion/verification', [OtpController::class, 'create'])->name('login.otp');
    Route::post('/connexion/verification', [OtpController::class, 'store'])->middleware('throttle:6,1')->name('login.otp.store');

    Route::get('/mot-de-passe-oublie', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('promoteurs')->name('promoters.')->group(function () {
        Route::get('/', [PromoterController::class, 'index'])->name('index');
        Route::get('/export', [PromoterController::class, 'export'])->name('export');
        Route::post('/validation-groupee', [PromoterAccountController::class, 'validateMany'])->name('validate-many');
        Route::get('/nouveau', [PromoterController::class, 'create'])->name('create');
        Route::post('/', [PromoterController::class, 'store'])->name('store');

        Route::prefix('{promoter}')->scopeBindings()->group(function () {
            Route::get('/modifier', [PromoterController::class, 'edit'])->name('edit');
            Route::get('/modifier/entreprise', [PromoterController::class, 'edit'])->name('edit-company');
            Route::put('/', [PromoterController::class, 'update'])->name('update');
            Route::get('/fiche', [PromoterController::class, 'sheet'])->name('sheet');

            Route::post('/validation', [PromoterAccountController::class, 'validateAccount'])->name('validate');
            Route::post('/suspension', [PromoterAccountController::class, 'suspend'])->name('suspend');
            Route::delete('/suspension', [PromoterAccountController::class, 'reactivate'])->name('reactivate');
            Route::post('/notifications', [PromoterAccountController::class, 'notify'])->name('notify');

            Route::post('/documents', [PromoterDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/validation', [PromoterDocumentController::class, 'approve'])->name('documents.approve');
            Route::post('/documents/{document}/rejet', [PromoterDocumentController::class, 'reject'])->name('documents.reject');
            Route::get('/documents/{document}/telechargement', [PromoterDocumentController::class, 'download'])->name('documents.download');

            Route::get('/chantiers/{site}', [PromoterDemoController::class, 'site'])->name('sites.show');
            Route::get('/paiements/{ref}', [PromoterDemoController::class, 'payment'])->name('payments.show');
            Route::post('/paiements/{ref}/autorisation', [PromoterDemoController::class, 'authorizePayment'])->name('payments.authorize');
            Route::post('/paiements/{ref}/relance', [PromoterDemoController::class, 'retryPayment'])->name('payments.retry');
            Route::get('/paiements/{ref}/recu', [PromoterDemoController::class, 'receipt'])->name('payments.receipt');
            Route::post('/offres/{offer}/validation', [PromoterDemoController::class, 'approveOffer'])->name('offers.approve');
            Route::patch('/avis/{review}', [PromoterDemoController::class, 'moderateReview'])->name('reviews.moderate');

            Route::get('/{tab?}/{item?}', [PromoterController::class, 'show'])
                ->where(['tab' => 'kyc|chantiers|offres|paiements|avis|litiges|activite', 'item' => '[A-Za-z0-9-]+'])
                ->name('show');
        });
    });

    Route::prefix('ouvriers')->name('workers.')->group(function () {
        Route::get('/', [WorkerController::class, 'index'])->name('index');
        Route::get('/export', [WorkerController::class, 'export'])->name('export');
        Route::post('/validation-groupee', [WorkerStatusController::class, 'validateMany'])->name('validate-many');
        Route::get('/nouveau', [WorkerController::class, 'create'])->name('create');
        Route::post('/', [WorkerController::class, 'store'])->name('store');

        Route::prefix('{worker}')->scopeBindings()->group(function () {
            Route::get('/modifier/{step?}', [WorkerController::class, 'edit'])->whereNumber('step')->name('edit');
            Route::post('/', [WorkerController::class, 'update'])->name('update');
            Route::get('/fiche', [WorkerController::class, 'sheet'])->name('sheet');

            Route::post('/validation', [WorkerStatusController::class, 'validateProfile'])->name('validate');
            Route::post('/rejet', [WorkerStatusController::class, 'reject'])->name('reject');
            Route::post('/suspension', [WorkerStatusController::class, 'suspend'])->name('suspend');
            Route::post('/reactivation', [WorkerStatusController::class, 'reactivate'])->name('reactivate');
            Route::post('/archivage', [WorkerStatusController::class, 'archive'])->name('archive');

            Route::post('/documents', [WorkerDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/validation', [WorkerDocumentController::class, 'approve'])->name('documents.approve');
            Route::post('/documents/{document}/rejet', [WorkerDocumentController::class, 'reject'])->name('documents.reject');
            Route::post('/documents/{document}/commentaire', [WorkerDocumentController::class, 'comment'])->name('documents.comment');
            Route::get('/documents/{document}/telechargement', [WorkerDocumentController::class, 'download'])->name('documents.download');

            Route::post('/listes/{list}', [WorkerProfileController::class, 'addValue'])->name('lists.add');
            Route::delete('/listes/{list}', [WorkerProfileController::class, 'removeValue'])->name('lists.remove');
            Route::post('/experiences', [WorkerProfileController::class, 'storeExperience'])->name('experiences.store');
            Route::put('/experiences/{experience}', [WorkerProfileController::class, 'updateExperience'])->name('experiences.update');
            Route::delete('/experiences/{experience}', [WorkerProfileController::class, 'destroyExperience'])->name('experiences.destroy');

            Route::post('/periodes', [WorkerPeriodController::class, 'store'])->name('periods.store');
            Route::delete('/periodes/{period}', [WorkerPeriodController::class, 'destroy'])->name('periods.destroy');
            Route::post('/indisponibilite', [WorkerPeriodController::class, 'unavailability'])->name('unavailability');

            Route::post('/affectations', [WorkerMissionController::class, 'proposeAssignment'])->name('assignments.store');
            Route::post('/affectations/{assignment}/{action}', [WorkerMissionController::class, 'transitionAssignment'])
                ->whereIn('action', ['accepter', 'refuser', 'confirmer', 'suspendre', 'retirer'])->name('assignments.transition');
            Route::get('/pointages/export', [WorkerMissionController::class, 'exportTimesheets'])->name('timesheets.export');
            Route::post('/pointages/{timesheet}/anomalie', [WorkerMissionController::class, 'flagTimesheet'])->name('timesheets.flag');
            Route::post('/pointages/{timesheet}/correction', [WorkerMissionController::class, 'correctTimesheet'])->name('timesheets.correct');
            Route::post('/pointages/{timesheet}/validation', [WorkerMissionController::class, 'approveTimesheet'])->name('timesheets.approve');
            Route::post('/paiements/{payout}/validation', [WorkerMissionController::class, 'validatePayout'])->name('payouts.validate');
            Route::post('/paiements/{payout}/relance', [WorkerMissionController::class, 'retryPayout'])->name('payouts.retry');
            Route::get('/paiements/{payout}/releve', [WorkerMissionController::class, 'payoutStatement'])->name('payouts.statement');

            Route::post('/avis/{review}/signalement', [WorkerReviewController::class, 'flag'])->name('reviews.flag');
            Route::post('/avis/{review}/masquage', [WorkerReviewController::class, 'hide'])->name('reviews.hide');
            Route::post('/avis/{review}/restauration', [WorkerReviewController::class, 'restore'])->name('reviews.restore');
            Route::post('/avis/{review}/reponse', [WorkerReviewController::class, 'reply'])->name('reviews.reply');
            Route::post('/avis/{review}/litige', [WorkerReviewController::class, 'dispute'])->name('reviews.dispute');

            Route::post('/remplacements/{case}/candidat', [WorkerReplacementController::class, 'pick'])->name('replacements.pick');
            Route::post('/remplacements/{case}/refus', [WorkerReplacementController::class, 'refuse'])->name('replacements.refuse');
            Route::post('/remplacements/{case}/cloture', [WorkerReplacementController::class, 'close'])->name('replacements.close');
            Route::post('/remplacements/{case}/annulation', [WorkerReplacementController::class, 'cancel'])->name('replacements.cancel');
            Route::post('/remplacements/{case}/{action}', [WorkerReplacementController::class, 'advance'])
                ->whereIn('action', ['analyser', 'rechercher', 'valider', 'valider-promoteur', 'confirmer', 'transferer'])->name('replacements.advance');

            Route::get('/{tab?}', [WorkerController::class, 'show'])
                ->where('tab', 'identite-documents|metiers-competences|disponibilites|affectations|pointages|paiements|avis|remplacements|historique')
                ->name('show');
        });
    });

    // Modules pas encore intégrés : page d’attente.
    $modules = collect(config('admin.modules'))
        ->pluck('path')
        ->reject(fn (string $path): bool => in_array($path, ['/', '/promoteurs', '/ouvriers'], true))
        ->map(fn (string $path): string => preg_quote(ltrim($path, '/'), '#'))
        ->implode('|');

    Route::get('/{module}/{rest?}', ModuleController::class)
        ->where(['module' => $modules, 'rest' => '.*'])
        ->name('module');
});
