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

    // Modules pas encore intégrés : page d’attente.
    $modules = collect(config('admin.modules'))
        ->pluck('path')
        ->reject(fn (string $path): bool => in_array($path, ['/', '/promoteurs'], true))
        ->map(fn (string $path): string => preg_quote(ltrim($path, '/'), '#'))
        ->implode('|');

    Route::get('/{module}/{rest?}', ModuleController::class)
        ->where(['module' => $modules, 'rest' => '.*'])
        ->name('module');
});
