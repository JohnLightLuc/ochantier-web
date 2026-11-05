<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialAccessController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialAccountController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialCompensationController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialPortfolioController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialRankingController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialTerritoryController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterAccountController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterRelationController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterReviewController;
use App\Http\Controllers\Api\V1\Admin\Site\SiteController;
use App\Http\Controllers\Api\V1\Admin\Site\SiteDecisionController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerAccountController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerRelationController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerReviewController;
use App\Http\Controllers\Api\V1\Auth\CommercialActivationController;
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

    // Lien reçu par un commercial : appelé sans jeton par la page publique du Back Office.
    Route::get('commercial-activation', [CommercialActivationController::class, 'show'])->middleware('throttle:10,1')->name('commercial-activation.show');
    Route::post('commercial-activation', [CommercialActivationController::class, 'store'])->middleware('throttle:10,1')->name('commercial-activation.store');
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
                Route::middleware('admin.can:promoters.read')->controller(PromoterRelationController::class)->group(function () {
                    Route::get('sites', 'sites')->name('sites');
                    Route::get('offers', 'offers')->name('offers');
                    Route::get('payments', 'payments')->name('payments');
                    Route::get('ratings', 'ratings')->name('ratings');
                    Route::get('disputes', 'disputes')->name('disputes');
                    Route::get('activity', 'activity')->name('activity');
                });
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

        // Ouvriers : liste, export, création et modification, fiche, contrôle du dossier et des pièces, suspension et exclusion.
        Route::prefix('workers')->name('workers.')->group(function () {
            Route::get('/', [WorkerController::class, 'index'])->middleware('admin.can:workers.read')->name('index');
            Route::get('export', [WorkerController::class, 'export'])->middleware('admin.can:workers.export')->name('export');
            Route::post('approve', [WorkerReviewController::class, 'approveMany'])->middleware('admin.can:workers.validate')->name('approve-many');
            Route::get('form-options', [WorkerAccountController::class, 'formOptions'])->middleware('admin.can:workers.read')->name('form-options');
            Route::post('/', [WorkerAccountController::class, 'store'])->middleware('admin.can:workers.create')->name('store');

            Route::prefix('{worker}')->whereUuid('worker')->group(function () {
                Route::get('/', [WorkerController::class, 'show'])->middleware('admin.can:workers.read')->name('show');
                Route::middleware('admin.can:workers.read')->controller(WorkerRelationController::class)->group(function () {
                    Route::get('availability', 'availability')->name('availability');
                    Route::get('assignments', 'assignments')->name('assignments');
                    Route::get('attendance', 'attendance')->name('attendance');
                    Route::get('earnings', 'earnings')->name('earnings');
                    Route::get('ratings', 'ratings')->name('ratings');
                    Route::get('replacements', 'replacements')->name('replacements');
                    Route::get('history', 'history')->name('history');
                });
                Route::patch('/', [WorkerAccountController::class, 'update'])->middleware('admin.can:workers.update')->name('update');
                Route::post('documents', [WorkerAccountController::class, 'storeDocument'])->middleware('admin.can:documents.validate')->name('documents.store');
                Route::post('approve', [WorkerReviewController::class, 'approve'])->middleware('admin.can:workers.validate')->name('approve');
                Route::post('corrections', [WorkerReviewController::class, 'requestCorrections'])->middleware('admin.can:workers.validate')->name('corrections');
                Route::post('reject', [WorkerReviewController::class, 'reject'])->middleware('admin.can:workers.validate')->name('reject');
                Route::post('documents/{document}/approve', [WorkerReviewController::class, 'approveDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.approve');
                Route::post('documents/{document}/reject', [WorkerReviewController::class, 'rejectDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.reject');
                Route::post('suspension', [WorkerReviewController::class, 'suspend'])->middleware('admin.can:workers.update')->name('suspend');
                Route::delete('suspension', [WorkerReviewController::class, 'reactivate'])->middleware('admin.can:workers.update')->name('reactivate');
                Route::post('exclusion', [WorkerReviewController::class, 'exclude'])->middleware('admin.can:workers.validate')->name('exclude');
            });
        });

        // Chantiers : liste, export, fiche et décisions (suspension, reprise, annulation, clôture, archivage).
        Route::prefix('sites')->name('sites.')->group(function () {
            Route::get('/', [SiteController::class, 'index'])->middleware('admin.can:sites.read')->name('index');
            Route::get('export', [SiteController::class, 'export'])->middleware('admin.can:sites.export')->name('export');

            Route::prefix('{siteId}')->whereUuid('siteId')->group(function () {
                Route::get('/', [SiteController::class, 'show'])->middleware('admin.can:sites.read')->name('show');
                Route::post('suspension', [SiteDecisionController::class, 'suspend'])->middleware('admin.can:sites.update')->name('suspend');
                Route::delete('suspension', [SiteDecisionController::class, 'resume'])->middleware('admin.can:sites.update')->name('resume');
                Route::post('cancellation', [SiteDecisionController::class, 'cancel'])->middleware('admin.can:sites.validate')->name('cancel');
                Route::post('closure', [SiteDecisionController::class, 'close'])->middleware('admin.can:sites.validate')->name('close');
                Route::post('archive', [SiteDecisionController::class, 'archive'])->middleware('admin.can:sites.validate')->name('archive');
            });
        });

        // Commerciaux : liste, export, fiche, suspension, réactivation et fin de contrat.
        Route::prefix('commercials')->name('commercials.')->group(function () {
            Route::get('/', [CommercialController::class, 'index'])->middleware('admin.can:commercials.read')->name('index');
            Route::get('export', [CommercialController::class, 'export'])->middleware('admin.can:commercials.export')->name('export');
            Route::get('form-options', [CommercialAccountController::class, 'formOptions'])->middleware('admin.can:commercials.read')->name('form-options');
            Route::post('/', [CommercialAccountController::class, 'store'])->middleware('admin.can:commercials.create')->name('store');
            Route::get('ranking', [CommercialRankingController::class, 'show'])->middleware('admin.can:commercials.read')->name('ranking');
            Route::get('ranking/export', [CommercialRankingController::class, 'export'])->middleware('admin.can:commercials.export')->name('ranking.export');
            Route::post('ranking/close', [CommercialRankingController::class, 'close'])->middleware('admin.can:commercials.validate')->name('ranking.close');
            Route::post('ranking/publish', [CommercialRankingController::class, 'publish'])->middleware('admin.can:commercials.validate')->name('ranking.publish');

            Route::prefix('{commercial}')->whereUuid('commercial')->group(function () {
                Route::get('/', [CommercialController::class, 'show'])->middleware('admin.can:commercials.read')->name('show');
                Route::post('suspension', [CommercialAccessController::class, 'suspend'])->middleware('admin.can:commercials.update')->name('suspend');
                Route::post('reactivation', [CommercialAccessController::class, 'reactivate'])->middleware('admin.can:commercials.update')->name('reactivate');
                Route::post('termination', [CommercialAccessController::class, 'terminate'])->middleware('admin.can:commercials.delete')->name('terminate');
                Route::patch('/', [CommercialAccountController::class, 'update'])->middleware('admin.can:commercials.update')->name('update');
                Route::post('documents', [CommercialAccountController::class, 'storeDocument'])->middleware('admin.can:documents.validate')->name('documents.store');
                Route::post('documents/{document}/approve', [CommercialAccountController::class, 'approveDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.approve');
                Route::post('documents/{document}/reject', [CommercialAccountController::class, 'rejectDocument'])->whereUuid('document')->middleware('admin.can:documents.validate')->name('documents.reject');
                Route::post('access-link', [CommercialAccountController::class, 'sendLink'])->middleware('admin.can:commercials.update')->name('access-link.send');
                Route::delete('access-link', [CommercialAccountController::class, 'revokeLink'])->middleware('admin.can:commercials.update')->name('access-link.revoke');
                Route::get('access', [CommercialAccountController::class, 'access'])->middleware('admin.can:commercials.read')->name('access');
                Route::delete('sessions/{session?}', [CommercialAccountController::class, 'revokeSessions'])->whereUuid('session')->middleware('admin.can:commercials.update')->name('sessions.revoke');
                Route::get('workers', [CommercialPortfolioController::class, 'workers'])->middleware('admin.can:commercials.read')->name('workers');
                Route::post('workers/transfer', [CommercialPortfolioController::class, 'transfer'])->middleware('admin.can:commercials.update')->name('workers.transfer');
                Route::get('prospects', [CommercialPortfolioController::class, 'prospects'])->middleware('admin.can:commercials.read')->name('prospects');
                Route::post('zones', [CommercialTerritoryController::class, 'addZone'])->middleware('admin.can:commercials.update')->name('zones.store');
                Route::delete('zones/{commune}', [CommercialTerritoryController::class, 'removeZone'])->whereUuid('commune')->middleware('admin.can:commercials.update')->name('zones.destroy');
                Route::get('zone-requests', [CommercialTerritoryController::class, 'requests'])->middleware('admin.can:commercials.read')->name('zone-requests');
                Route::post('zone-requests/{zoneRequest}/accept', [CommercialTerritoryController::class, 'acceptRequest'])->whereUuid('zoneRequest')->middleware('admin.can:commercials.validate')->name('zone-requests.accept');
                Route::post('zone-requests/{zoneRequest}/refuse', [CommercialTerritoryController::class, 'refuseRequest'])->whereUuid('zoneRequest')->middleware('admin.can:commercials.validate')->name('zone-requests.refuse');
                Route::get('objectives', [CommercialTerritoryController::class, 'objectives'])->middleware('admin.can:commercials.read')->name('objectives');
                Route::post('objectives', [CommercialTerritoryController::class, 'saveObjective'])->middleware('admin.can:commercials.update')->name('objectives.save');
                Route::get('earnings', [CommercialCompensationController::class, 'earnings'])->middleware('admin.can:commercials.read')->name('earnings');
                Route::post('adjustments', [CommercialCompensationController::class, 'propose'])->middleware('admin.can:commercial_earnings.update')->name('adjustments.store');
                Route::post('adjustments/{adjustment}/approve', [CommercialCompensationController::class, 'approve'])->whereUuid('adjustment')->middleware('admin.can:commercial_earnings.approve')->name('adjustments.approve');
                Route::post('adjustments/{adjustment}/refuse', [CommercialCompensationController::class, 'refuse'])->whereUuid('adjustment')->middleware('admin.can:commercial_earnings.approve')->name('adjustments.refuse');
                Route::get('appreciations', [CommercialCompensationController::class, 'appreciations'])->middleware('admin.can:commercials.read')->name('appreciations');
                Route::post('appreciations', [CommercialCompensationController::class, 'storeAppreciation'])->middleware('admin.can:commercials.update')->name('appreciations.store');
                Route::get('history', [CommercialCompensationController::class, 'history'])->middleware('admin.can:commercials.read')->name('history');
            });
        });
    });
});
