<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterAccountController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API du Back Office O’CHANTIER ADMIN — v1 (préfixe api/v1/admin)
|--------------------------------------------------------------------------
|
| Appelée par le serveur du Back Office, qui conserve les jetons en session. Jetons JWT de type admin, refusés par
| les routes des applications mobiles (et inversement).
|
| Connexion par e-mail et mot de passe puis code envoyé par e-mail ; mot de passe oublié par lien. Politique des
| sessions, verrouillage et restriction par adresse IP : config auth.admin. Chaque route métier exige une
| permission des rôles de l’administrateur (admin.can, voir AdminAccessSeeder).
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:admin-login')->name('login');
    Route::post('login/verify', [LoginController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');
    Route::post('login/resend', [LoginController::class, 'resend'])->middleware('throttle:admin-login')->name('login.resend');
    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:admin-login')->name('password.forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
    Route::post('refresh', [SessionController::class, 'refresh'])->middleware('throttle:30,1')->name('refresh');
});

Route::middleware('auth:admin')->group(function () {
    // Accessibles avant le remplacement du mot de passe provisoire.
    Route::get('me', [SessionController::class, 'me'])->name('me');
    Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
    Route::put('auth/password', [PasswordController::class, 'update'])->name('auth.password.update');

    Route::middleware('password.changed')->group(function () {
        Route::get('auth/sessions', [SessionController::class, 'index'])->name('auth.sessions.index');
        Route::delete('auth/sessions/{session}', [SessionController::class, 'destroy'])->whereUuid('session')->name('auth.sessions.destroy');

        // Promoteurs : liste, export, création et modification, fiche, contrôle du dossier et des pièces, suspension, messages.
        Route::prefix('promoters')->name('promoters.')->group(function () {
            Route::get('/', [PromoterController::class, 'index'])->middleware('admin.can:promoters.read')->name('index');
            Route::get('export', [PromoterController::class, 'export'])->middleware('admin.can:promoters.export')->name('export');
            Route::post('approve', [PromoterReviewController::class, 'approveMany'])->middleware('admin.can:promoters.validate')->name('approve-many');
            Route::get('form-options', [PromoterAccountController::class, 'formOptions'])->middleware('admin.can:promoters.read')->name('form-options');
            Route::post('/', [PromoterAccountController::class, 'store'])->middleware('admin.can:promoters.create')->name('store');

            Route::prefix('{promoter}')->whereUuid('promoter')->group(function () {
                Route::get('/', [PromoterController::class, 'show'])->middleware('admin.can:promoters.read')->name('show');
                Route::patch('/', [PromoterAccountController::class, 'update'])->middleware('admin.can:promoters.update')->name('update');
                Route::post('documents', [PromoterAccountController::class, 'storeDocument'])->middleware('admin.can:documents.validate')->name('documents.store');
                Route::post('approve', [PromoterReviewController::class, 'approve'])->middleware('admin.can:promoters.validate')->name('approve');
                Route::post('corrections', [PromoterReviewController::class, 'requestCorrections'])->middleware('admin.can:promoters.validate')->name('corrections');
                Route::post('reject', [PromoterReviewController::class, 'reject'])->middleware('admin.can:promoters.validate')->name('reject');
                Route::post('documents/{document}/approve', [PromoterReviewController::class, 'approveDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.approve');
                Route::post('documents/{document}/reject', [PromoterReviewController::class, 'rejectDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.reject');
                Route::post('suspension', [PromoterReviewController::class, 'suspend'])->middleware('admin.can:promoters.update')->name('suspend');
                Route::delete('suspension', [PromoterReviewController::class, 'reactivate'])->middleware('admin.can:promoters.update')->name('reactivate');
                Route::post('messages', [PromoterReviewController::class, 'message'])->middleware('admin.can:promoters.update')->name('messages');
            });
        });
    });
});
