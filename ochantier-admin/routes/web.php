<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssignmentComplementController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignmentNeedController;
use App\Http\Controllers\AttendanceActionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceEntityController;
use App\Http\Controllers\AttendanceSectionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ClaimActionController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeActionController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\FinanceActionController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\MatchingCandidateController;
use App\Http\Controllers\MatchingController;
use App\Http\Controllers\MatchingRunController;
use App\Http\Controllers\MatchingSelectionController;
use App\Http\Controllers\NeedController;
use App\Http\Controllers\NeedDocumentController;
use App\Http\Controllers\NeedStatusController;
use App\Http\Controllers\PaymentActionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PromoterAccountController;
use App\Http\Controllers\PromoterController;
use App\Http\Controllers\PromoterDemoController;
use App\Http\Controllers\PromoterDocumentController;
use App\Http\Controllers\PromoterOfferController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\ReviewActionController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SalesAgentAccountController;
use App\Http\Controllers\SalesAgentController;
use App\Http\Controllers\SalesAgentDocumentController;
use App\Http\Controllers\SalesAgentFieldController;
use App\Http\Controllers\SalesAgentPerformanceController;
use App\Http\Controllers\SalesAgentTerritoryController;
use App\Http\Controllers\SalesRankingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteDocumentController;
use App\Http\Controllers\SiteIncidentController;
use App\Http\Controllers\SiteStatusController;
use App\Http\Controllers\SubscriptionActionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerDocumentController;
use App\Http\Controllers\WorkerMissionController;
use App\Http\Controllers\WorkerPeriodController;
use App\Http\Controllers\WorkerProfileController;
use App\Http\Controllers\WorkerReplacementController;
use App\Http\Controllers\WorkerReviewController;
use App\Http\Controllers\WorkerStatusController;
use App\Support\Settings;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');

    Route::get('/connexion/verification', [OtpController::class, 'create'])->name('login.otp');
    Route::post('/connexion/verification', [OtpController::class, 'store'])->middleware('throttle:6,1')->name('login.otp.store');
    Route::post('/connexion/verification/renvoi', [OtpController::class, 'resend'])->middleware('throttle:6,1')->name('login.otp.resend');

    Route::get('/mot-de-passe-oublie', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');

    // Lien reçu par e-mail (adresse fixée côté API : ADMIN_PASSWORD_RESET_URL).
    Route::get('/reinitialiser-mot-de-passe', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reinitialiser-mot-de-passe', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/mot-de-passe/nouveau', [PasswordController::class, 'create'])->name('password.change');
    Route::put('/mot-de-passe', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'password.changed'])->group(function () {

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

    Route::prefix('commerciaux')->name('sales-agents.')->group(function () {
        Route::get('/', [SalesAgentController::class, 'index'])->name('index');
        Route::get('/export', [SalesAgentController::class, 'export'])->name('export');
        Route::get('/nouveau', [SalesAgentController::class, 'create'])->name('create');
        Route::post('/', [SalesAgentController::class, 'store'])->name('store');

        Route::prefix('classement')->name('ranking.')->group(function () {
            Route::get('/{year?}/{month?}', [SalesRankingController::class, 'show'])->whereNumber(['year', 'month'])->name('show');
            Route::get('/{year}/{month}/export', [SalesRankingController::class, 'export'])->whereNumber(['year', 'month'])->name('export');
            Route::post('/{year}/{month}/publication', [SalesRankingController::class, 'togglePublication'])->whereNumber(['year', 'month'])->name('publication');
            Route::post('/{year}/{month}/cloture', [SalesRankingController::class, 'close'])->whereNumber(['year', 'month'])->name('close');
        });

        Route::prefix('{salesAgent}')->scopeBindings()->group(function () {
            Route::get('/modifier/{step?}', [SalesAgentController::class, 'edit'])->whereNumber('step')->name('edit');
            Route::post('/', [SalesAgentController::class, 'update'])->name('update');
            Route::get('/fiche', [SalesAgentController::class, 'sheet'])->name('sheet');

            Route::post('/invitation', [SalesAgentAccountController::class, 'invite'])->name('invite');
            Route::post('/lien/copie', [SalesAgentAccountController::class, 'copyLink'])->name('link.copy');
            Route::post('/lien/revocation', [SalesAgentAccountController::class, 'revokeLink'])->name('link.revoke');
            Route::post('/lien/nouveau', [SalesAgentAccountController::class, 'newLink'])->name('link.new');
            Route::post('/email', [SalesAgentAccountController::class, 'updateEmail'])->name('email');
            Route::post('/activation', [SalesAgentAccountController::class, 'activate'])->name('activate');
            Route::post('/reinitialisation', [SalesAgentAccountController::class, 'resetAccess'])->name('reset');
            Route::post('/suspension', [SalesAgentAccountController::class, 'suspend'])->name('suspend');
            Route::post('/reactivation', [SalesAgentAccountController::class, 'reactivate'])->name('reactivate');
            Route::post('/desactivation', [SalesAgentAccountController::class, 'deactivate'])->name('deactivate');
            Route::post('/archivage', [SalesAgentAccountController::class, 'archive'])->name('archive');
            Route::delete('/', [SalesAgentAccountController::class, 'destroy'])->name('destroy');
            Route::post('/permissions/{permission}', [SalesAgentAccountController::class, 'togglePermission'])->name('permissions.toggle');
            Route::post('/role', [SalesAgentAccountController::class, 'updateRole'])->name('role');
            Route::post('/double-authentification', [SalesAgentAccountController::class, 'toggleTwoFactor'])->name('two-factor');
            Route::delete('/sessions/{session?}', [SalesAgentAccountController::class, 'revokeSessions'])->name('sessions.revoke');

            Route::post('/zones', [SalesAgentTerritoryController::class, 'addZone'])->name('zones.store');
            Route::post('/zones/transfert', [SalesAgentTerritoryController::class, 'transferZone'])->name('zones.transfer');
            Route::delete('/listes/{list}', [SalesAgentTerritoryController::class, 'removeValue'])->name('lists.remove');
            Route::post('/indisponibilite', [SalesAgentTerritoryController::class, 'unavailability'])->name('unavailability');
            Route::delete('/periodes/{period}', [SalesAgentTerritoryController::class, 'destroyPeriod'])->name('periods.destroy');

            Route::post('/objectifs', [SalesAgentPerformanceController::class, 'storeObjective'])->name('objectives.store');
            Route::put('/objectifs/{objective}', [SalesAgentPerformanceController::class, 'updateObjective'])->name('objectives.update');
            Route::post('/objectifs/{objective}/cloture', [SalesAgentPerformanceController::class, 'closeObjective'])->name('objectives.close');
            Route::post('/appreciations', [SalesAgentPerformanceController::class, 'storeAppreciation'])->name('appreciations.store');
            Route::post('/score/{month}/correction', [SalesAgentPerformanceController::class, 'correctScore'])->where('month', '\d{4}-\d{2}')->name('score.correct');
            Route::post('/bonus', [SalesAgentPerformanceController::class, 'storeBonus'])->name('bonuses.store');
            Route::post('/retenues', [SalesAgentPerformanceController::class, 'storeDeduction'])->name('deductions.store');
            Route::get('/gains/releve', [SalesAgentPerformanceController::class, 'statement'])->name('payouts.statement');
            Route::get('/gains/{ref}/justificatif', [SalesAgentPerformanceController::class, 'receipt'])->name('payouts.receipt');
            Route::post('/gains/{ref}/{action}', [SalesAgentPerformanceController::class, 'updatePayout'])->whereIn('action', ['valider', 'suspendre', 'reprendre'])->name('payouts.update');
            Route::get('/rapport', [SalesAgentPerformanceController::class, 'report'])->name('report');

            Route::get('/activites/export', [SalesAgentFieldController::class, 'exportActivities'])->name('activities.export');
            Route::post('/activites/{fieldActivity}/validation', [SalesAgentFieldController::class, 'validateActivity'])->name('activities.validate');
            Route::post('/activites/{fieldActivity}/anomalie', [SalesAgentFieldController::class, 'flagActivity'])->name('activities.flag');
            Route::post('/ouvriers/{worker}/soumission', [SalesAgentFieldController::class, 'submitWorker'])->withoutScopedBindings()->name('workers.submit');

            Route::post('/documents', [SalesAgentDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/validation', [SalesAgentDocumentController::class, 'approve'])->name('documents.approve');
            Route::post('/documents/{document}/rejet', [SalesAgentDocumentController::class, 'reject'])->name('documents.reject');
            Route::post('/documents/{document}/commentaire', [SalesAgentDocumentController::class, 'comment'])->name('documents.comment');
            Route::get('/documents/{document}/telechargement', [SalesAgentDocumentController::class, 'download'])->name('documents.download');

            Route::get('/{tab?}', [SalesAgentController::class, 'show'])
                ->where('tab', 'profil-documents|ouvriers|activite|zones-disponibilites|objectifs-performances|classement|gains-paiements|acces-securite|historique')
                ->name('show');
        });
    });

    Route::prefix('chantiers')->name('sites.')->group(function () {
        Route::get('/', [SiteController::class, 'index'])->name('index');
        Route::get('/export', [SiteController::class, 'export'])->name('export');
        Route::post('/', [SiteController::class, 'store'])->name('store');

        Route::prefix('{site}')->scopeBindings()->group(function () {
            Route::get('/fiche', [SiteController::class, 'sheet'])->name('sheet');

            Route::put('/informations', [SiteStatusController::class, 'updateInfo'])->name('info.update');
            Route::post('/correction', [SiteStatusController::class, 'requestCorrection'])->name('correction');
            Route::post('/planification', [SiteStatusController::class, 'plan'])->name('plan');
            Route::post('/suspension', [SiteStatusController::class, 'suspend'])->name('suspend');
            Route::post('/reprise', [SiteStatusController::class, 'resume'])->name('resume');
            Route::post('/cloture', [SiteStatusController::class, 'close'])->name('close');
            Route::post('/annulation', [SiteStatusController::class, 'cancel'])->name('cancel');
            Route::post('/archivage', [SiteStatusController::class, 'archive'])->name('archive');

            Route::post('/incidents', [SiteIncidentController::class, 'store'])->name('incidents.store');
            Route::post('/incidents/{incident}/resolution', [SiteIncidentController::class, 'resolve'])->name('incidents.resolve');

            Route::post('/documents', [SiteDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/validation', [SiteDocumentController::class, 'approve'])->name('documents.approve');
            Route::post('/documents/{document}/rejet', [SiteDocumentController::class, 'reject'])->name('documents.reject');
            Route::post('/documents/{document}/commentaire', [SiteDocumentController::class, 'comment'])->name('documents.comment');
            Route::get('/documents/{document}/telechargement', [SiteDocumentController::class, 'download'])->name('documents.download');

            Route::get('/{tab?}', [SiteController::class, 'show'])
                ->where('tab', 'equipe|incidents|documents|historique')
                ->name('show');
        });
    });

    Route::prefix('besoins')->name('needs.')->group(function () {
        Route::get('/', [NeedController::class, 'index'])->name('index');
        Route::get('/export', [NeedController::class, 'export'])->name('export');
        Route::post('/actualisation-groupee', [NeedStatusController::class, 'refreshMany'])->name('refresh-many');
        Route::get('/nouveau-besoin', [NeedController::class, 'create'])->name('create');
        Route::post('/', [NeedController::class, 'store'])->name('store');

        Route::prefix('{need}')->scopeBindings()->group(function () {
            Route::get('/modifier', [NeedController::class, 'edit'])->name('edit');
            Route::post('/', [NeedController::class, 'update'])->name('update');
            Route::get('/fiche', [NeedController::class, 'sheet'])->name('sheet');

            Route::post('/suspension', [NeedStatusController::class, 'suspend'])->name('suspend');
            Route::post('/reactivation', [NeedStatusController::class, 'reactivate'])->name('reactivate');
            Route::post('/annulation', [NeedStatusController::class, 'cancel'])->name('cancel');
            Route::post('/cloture', [NeedStatusController::class, 'close'])->name('close');
            Route::post('/archivage', [NeedStatusController::class, 'archive'])->name('archive');
            Route::post('/actualisation', [NeedStatusController::class, 'refreshAll'])->name('refresh');
            Route::post('/offres/{offer}/actualisation', [NeedStatusController::class, 'refreshOffer'])->name('offers.refresh');
            Route::post('/offres/{offer}/prolongation', [NeedStatusController::class, 'extend'])->name('offers.extend');
            Route::post('/offres/{offer}/cloture-candidatures', [NeedStatusController::class, 'closeApplications'])->name('offers.close-applications');

            Route::post('/documents', [NeedDocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/validation', [NeedDocumentController::class, 'approve'])->name('documents.approve');
            Route::delete('/documents/{document}', [NeedDocumentController::class, 'destroy'])->name('documents.destroy');
            Route::get('/documents/{document}/telechargement', [NeedDocumentController::class, 'download'])->name('documents.download');

            Route::get('/{tab?}', [NeedController::class, 'show'])
                ->where('tab', 'metiers|affectations|documents|historique')
                ->name('show');
        });
    });

    Route::prefix('matching')->name('matching.')->group(function () {
        Route::get('/', [MatchingController::class, 'index'])->name('index');
        Route::get('/resultats', [MatchingController::class, 'results'])->name('results');
        Route::get('/resultats/export', [MatchingController::class, 'export'])->name('export');
        Route::get('/a-valider', [MatchingController::class, 'toValidate'])->name('to-validate');
        Route::get('/historique', [MatchingController::class, 'history'])->name('history');
        Route::get('/analyses', [MatchingController::class, 'analyses'])->name('analyses');
        Route::get('/lancer', [MatchingController::class, 'launch'])->name('launch');

        Route::post('/lancement', [MatchingRunController::class, 'launch'])->name('run');
        Route::post('/lancement/annulation', [MatchingRunController::class, 'cancel'])->name('cancel');
        Route::post('/actualisation', [MatchingRunController::class, 'refresh'])->name('refresh');
        Route::post('/transmission', [MatchingSelectionController::class, 'transmitMany'])->name('transmit-many');

        Route::prefix('offres/{offer}')->name('offers.')->group(function () {
            Route::post('/choix', [MatchingSelectionController::class, 'toggle'])->name('toggle');
            Route::post('/remplacant', [MatchingSelectionController::class, 'replacement'])->name('replacement');
            Route::post('/soumission', [MatchingSelectionController::class, 'submit'])->name('submit');
            Route::post('/correction', [MatchingSelectionController::class, 'correction'])->name('correction');
            Route::post('/rejet', [MatchingSelectionController::class, 'reject'])->name('reject');
            Route::post('/reinitialisation', [MatchingSelectionController::class, 'reset'])->name('reset');
            Route::post('/sur-selection', [MatchingSelectionController::class, 'overselection'])->name('overselection');
            Route::post('/transmission', [MatchingSelectionController::class, 'transmit'])->name('transmit');
            Route::post('/commerciaux', [MatchingRunController::class, 'notifySales'])->name('notify-sales');

            Route::post('/profils/{key}/rejet', [MatchingCandidateController::class, 'reject'])->name('profiles.reject');
            Route::post('/profils/{key}/derogation', [MatchingCandidateController::class, 'derogation'])->name('profiles.derogation');
            Route::post('/profils/{key}/recalcul', [MatchingCandidateController::class, 'recalculate'])->name('profiles.recalculate');
            Route::post('/profils/{key}/archivage', [MatchingCandidateController::class, 'archive'])->name('profiles.archive');
            Route::post('/profils/{key}/verification', [MatchingCandidateController::class, 'verification'])->name('profiles.verification');
        });

        Route::get('/besoins/{offer}/{tab?}', [MatchingController::class, 'need'])
            ->where('tab', 'resultats|comparaison|selection|exclus')
            ->name('need');
        Route::get('/{match}/{tab?}', [MatchingController::class, 'match'])
            ->where(['match' => 'mt-\\d+-\\d{4}', 'tab' => 'score|besoin|profil|validation|affectation|resultat|historique'])
            ->name('match');
    });

    Route::prefix('affectations')->name('assignments.')->group(function () {
        Route::get('/', [AssignmentController::class, 'index'])->name('index');
        Route::get('/export', [AssignmentController::class, 'export'])->name('export');

        Route::prefix('besoins/{need}')->name('needs.')->scopeBindings()->group(function () {
            Route::get('/', [AssignmentController::class, 'need'])->name('show');
            Route::post('/contact', [AssignmentNeedController::class, 'contact'])->name('contact');
            Route::post('/validation', [AssignmentNeedController::class, 'validateWorkers'])->name('validate');
            Route::post('/propositions', [AssignmentNeedController::class, 'addProposals'])->name('proposals');
            Route::post('/ouvriers', [AssignmentNeedController::class, 'addManual'])->name('manual');
            Route::post('/acompte', [AssignmentNeedController::class, 'payDeposit'])->name('deposit');
            Route::post('/relance', [AssignmentNeedController::class, 'remind'])->name('remind');
            Route::post('/complement', [AssignmentComplementController::class, 'forNeed'])->name('complement');
            Route::post('/ouvriers/{assignment}/invalidation', [AssignmentNeedController::class, 'decline'])->name('decline');
            Route::post('/ouvriers/{assignment}/annulation-validation', [AssignmentNeedController::class, 'cancelValidation'])->name('cancel-validation');
            Route::post('/ouvriers/{assignment}/demande-remplacement', [AssignmentNeedController::class, 'requestReplacement'])->name('replacement-request');
            Route::post('/ouvriers/{assignment}/remplacant', [AssignmentNeedController::class, 'propose'])->name('propose');
        });

        Route::prefix('offres/{promoterOffer}')->name('offers.')->scopeBindings()->group(function () {
            Route::get('/', [AssignmentController::class, 'offer'])->name('show');
            Route::post('/contact', [PromoterOfferController::class, 'contact'])->name('contact');
            Route::post('/validation', [PromoterOfferController::class, 'accept'])->name('accept');
            Route::post('/notification', [PromoterOfferController::class, 'notify'])->name('notify');
            Route::post('/acompte', [PromoterOfferController::class, 'payDeposit'])->name('deposit');
            Route::post('/annulation', [PromoterOfferController::class, 'cancel'])->name('cancel');
            Route::post('/complement', [AssignmentComplementController::class, 'forOffer'])->name('complement');
            Route::post('/profils/{profile}/refus', [PromoterOfferController::class, 'refuse'])->name('refuse');
            Route::post('/profils/{profile}/demande-remplacement', [PromoterOfferController::class, 'requestReplacement'])->name('replacement-request');
            Route::post('/profils/{profile}/remplacant', [PromoterOfferController::class, 'propose'])->name('propose');
        });
    });

    Route::prefix('pointages')->name('attendances.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/aujourdhui', [AttendanceController::class, 'index'])->name('today');
        Route::get('/export', [AttendanceController::class, 'export'])->name('export');
        Route::get('/export/{view}', [AttendanceSectionController::class, 'export'])->whereIn('view', ['incomplets', 'absences', 'rapport', 'heures-ouvriers'])->name('sections.export');
        Route::get('/incomplets', [AttendanceSectionController::class, 'incomplete'])->name('incomplete');
        Route::get('/anomalies', [AttendanceSectionController::class, 'anomalies'])->name('anomalies');
        Route::get('/corrections', [AttendanceSectionController::class, 'corrections'])->name('corrections');
        Route::get('/absences', [AttendanceSectionController::class, 'absences'])->name('absences');
        Route::get('/calendrier', [AttendanceSectionController::class, 'calendar'])->name('calendar');
        Route::get('/rapports', [AttendanceSectionController::class, 'reports'])->name('reports');
        Route::get('/application', [AttendanceEntityController::class, 'app'])->name('app');
        Route::post('/application/groupe', [AttendanceEntityController::class, 'recordMany'])->name('app.many');
        Route::get('/chantiers/{site}/{tab?}', [AttendanceEntityController::class, 'site'])->where('tab', 'semaine|mois')->name('site');
        Route::get('/ouvriers/{worker}/{tab?}', [AttendanceEntityController::class, 'worker'])->where('tab', 'affectations')->name('worker');
        Route::post('/relance', [AttendanceActionController::class, 'remind'])->name('remind');
        Route::post('/cloture', [AttendanceActionController::class, 'close'])->name('close');

        Route::prefix('{attendance}')->where(['attendance' => 'PT-\\d{4}-\\d{4}'])->scopeBindings()->group(function () {
            Route::post('/notes', [AttendanceActionController::class, 'note'])->name('note');
            Route::post('/corrections', [AttendanceActionController::class, 'requestCorrection'])->name('corrections.store');
            Route::post('/corrections/{correction}/approbation', [AttendanceActionController::class, 'approveCorrection'])->name('corrections.approve');
            Route::post('/corrections/{correction}/rejet', [AttendanceActionController::class, 'rejectCorrection'])->name('corrections.reject');
            Route::post('/anomalies', [AttendanceActionController::class, 'reportAnomaly'])->name('anomalies.store');
            Route::post('/anomalies/{anomaly}/statut', [AttendanceActionController::class, 'updateAnomaly'])->name('anomalies.update');
            Route::post('/regularisation', [AttendanceActionController::class, 'regularize'])->name('regularize');
            Route::post('/reouverture', [AttendanceActionController::class, 'reopen'])->name('reopen');
            Route::post('/transmission', [AttendanceActionController::class, 'transmit'])->name('transmit');
            Route::post('/preuves/{event}/{proof}/{action}', [AttendanceActionController::class, 'proof'])
                ->whereIn('event', ['arrivee', 'depart'])->whereIn('action', ['verification', 'rejet', 'remplacement', 'commentaire'])->name('proofs');
            Route::post('/application/{action}', [AttendanceEntityController::class, 'record'])
                ->whereIn('action', ['arrivee', 'validation-arrivee', 'depart', 'validation-depart', 'absence', 'validation-absence'])->name('app.record');
            Route::post('/decisions/{object}', [AttendanceActionController::class, 'decide'])->whereIn('object', ['lateness', 'early_leave', 'overtime', 'absence'])->name('decide');

            Route::get('/{tab?}', [AttendanceController::class, 'show'])
                ->where('tab', 'arrivee|depart|temps|preuves|anomalies|corrections|impact-financier|historique')
                ->name('show');
        });
    });

    Route::prefix('paiements')->name('payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::get('/operations', [PaymentController::class, 'operations'])->name('operations');
        Route::get('/a-reverser', [PaymentController::class, 'operations'])->name('to-pay-out');
        Route::get('/reversements', [PaymentController::class, 'operations'])->name('payouts');
        Route::get('/lots', [PaymentController::class, 'batches'])->name('batches');
        Route::get('/commissions', [PaymentController::class, 'commissions'])->name('commissions');
        Route::get('/commissions/operations', [PaymentController::class, 'commissionOperations'])->name('commissions.operations');
        Route::get('/remboursements', [PaymentController::class, 'refunds'])->name('refunds');
        Route::get('/rapprochements', [PaymentController::class, 'reconciliations'])->name('reconciliations');
        Route::get('/anomalies', [PaymentController::class, 'anomalies'])->name('anomalies');
        Route::get('/rapports', [PaymentController::class, 'reports'])->name('reports');
        Route::get('/rapports/commissions/{format}', [PaymentController::class, 'commissionReport'])->whereIn('format', ['pdf', 'excel'])->name('reports.commissions');
        Route::get('/export/{view}', [PaymentController::class, 'export'])->whereIn('view', ['operations', 'a-reverser', 'reversements', 'commissions', 'commissions-operations', 'rapprochements', 'rapport-operations'])->name('export');
        Route::post('/lots', [PaymentActionController::class, 'batch'])->name('batches.store');

        Route::prefix('operations/{payment}')->scopeBindings()->group(function () {
            Route::post('/reversement', [PaymentActionController::class, 'payout'])->name('payout');
            Route::post('/relance', [PaymentActionController::class, 'retry'])->name('retry');
            Route::post('/remboursements', [PaymentActionController::class, 'requestRefund'])->name('refunds.store');
            Route::post('/remboursements/{refund}/{decision}', [PaymentActionController::class, 'decideRefund'])->whereIn('decision', ['approbation', 'rejet'])->name('refunds.decide');
            Route::post('/elements/{kind}', [PaymentActionController::class, 'addItem'])->whereIn('kind', ['bonus', 'indemnity', 'deduction'])->name('items.store');
            Route::post('/elements/{item}/{decision}', [PaymentActionController::class, 'decideItem'])->whereNumber('item')->whereIn('decision', ['approbation', 'rejet'])->name('items.decide');
            Route::post('/rapprochement', [PaymentActionController::class, 'reconcile'])->name('reconcile');
            Route::post('/anomalies', [PaymentActionController::class, 'reportAnomaly'])->name('anomalies.store');
            Route::get('/documents/{document}', [PaymentActionController::class, 'document'])
                ->whereIn('document', ['recu-promoteur', 'recu-reversement', 'facture', 'releve-commission', 'remboursement', 'justificatif'])->name('documents');
            Route::get('/{tab?}', [PaymentController::class, 'show'])
                ->where('tab', 'commission|bonus|rapprochement|calcul|paiement|reversement|remboursements|pointages|documents|historique')
                ->name('show');
        });
    });

    Route::prefix('abonnements')->name('subscriptions.')->group(function () {
        Route::get('/', [SubscriptionController::class, 'index'])->name('index');
        Route::get('/export', [SubscriptionController::class, 'export'])->name('export');
        Route::post('/formules', [SubscriptionPlanController::class, 'store'])->name('plans.store');
        Route::put('/formules/{plan:key}', [SubscriptionPlanController::class, 'update'])->name('plans.update');
        Route::delete('/formules/{plan:key}', [SubscriptionPlanController::class, 'destroy'])->name('plans.destroy');

        Route::prefix('{promoter}')->group(function () {
            Route::get('/', [SubscriptionController::class, 'show'])->name('show');
            Route::post('/formule', [SubscriptionActionController::class, 'changePlan'])->name('change-plan');
            Route::delete('/formule-programmee', [SubscriptionActionController::class, 'cancelPending'])->name('cancel-pending');
            Route::post('/relance', [SubscriptionActionController::class, 'remind'])->name('remind');
            Route::post('/paiement', [SubscriptionActionController::class, 'markPaid'])->name('mark-paid');
            Route::post('/essai', [SubscriptionActionController::class, 'extendTrial'])->name('extend-trial');
            Route::post('/suspension', [SubscriptionActionController::class, 'suspend'])->name('suspend');
            Route::post('/reactivation', [SubscriptionActionController::class, 'reactivate'])->name('reactivate');
            Route::post('/resiliation', [SubscriptionActionController::class, 'terminate'])->name('terminate');
            Route::post('/restauration', [SubscriptionActionController::class, 'restore'])->name('restore');
            Route::post('/utilisateurs', [SubscriptionActionController::class, 'invite'])->name('users.store');
            Route::delete('/utilisateurs/{user}', [SubscriptionActionController::class, 'removeUser'])->name('users.destroy');
        });
    });

    Route::prefix('finance-reporting')->name('finance.')->group(function () {
        Route::get('/', [FinanceController::class, 'index'])->name('index');
        Route::post('/synchronisation', [FinanceActionController::class, 'sync'])->name('sync');
        Route::post('/vues', [FinanceActionController::class, 'storeView'])->name('views.store');
        Route::post('/vues/partage', [FinanceActionController::class, 'shareView'])->name('views.share');
        Route::get('/commissions', [FinanceController::class, 'journal'])->name('journal');
        Route::get('/commissions/{reference}', [FinanceController::class, 'commission'])->name('commission');
        Route::get('/commissions/{reference}/export', [FinanceActionController::class, 'commissionExport'])->name('commission.export');
        Route::post('/commissions/{reference}/ecart', [FinanceActionController::class, 'reportGap'])->name('commission.report');
        Route::get('/creances', [FinanceController::class, 'receivables'])->name('receivables');
        Route::get('/reversements', [FinanceController::class, 'payouts'])->name('payouts');
        Route::get('/tresorerie', [FinanceController::class, 'treasury'])->name('treasury');
        Route::get('/remboursements', [FinanceController::class, 'refunds'])->name('refunds');
        Route::get('/frais-operateurs', [FinanceController::class, 'fees'])->name('fees');
        Route::get('/rapprochements', [FinanceController::class, 'reconciliations'])->name('reconciliations');
        Route::post('/rapprochements/{reference}/ecart', [FinanceActionController::class, 'resolveGap'])->name('reconciliations.gap');
        Route::post('/rapprochements/{reference}/doublon', [FinanceActionController::class, 'confirmDuplicate'])->name('reconciliations.duplicate');
        Route::get('/anomalies', [FinanceController::class, 'risks'])->name('risks');
        Route::get('/objectifs', [FinanceController::class, 'objectives'])->name('objectives');
        Route::get('/previsions', [FinanceController::class, 'forecasts'])->name('forecasts');
        Route::get('/clotures', [FinanceController::class, 'closings'])->name('closings');
        Route::get('/clotures/{month}/instantane', [FinanceActionController::class, 'snapshotExport'])->where('month', '\d{4}-\d{2}')->name('closings.snapshot');
        Route::post('/clotures/{month}/{action}', [FinanceActionController::class, 'closing'])->where('month', '\d{4}-\d{2}')->whereIn('action', ['preparation', 'controle', 'cloture', 'reouverture'])->name('closings.update');
        Route::get('/rapports', [FinanceController::class, 'reports'])->name('reports');
        Route::get('/rapports/generer', [FinanceActionController::class, 'generate'])->name('reports.generate');
        Route::get('/definitions', [FinanceController::class, 'definitions'])->name('definitions');
        Route::get('/audit', [FinanceController::class, 'audit'])->name('audit');
        Route::get('/audit/export', [FinanceActionController::class, 'auditExport'])->name('audit.export');
        Route::get('/{dimension}', [FinanceController::class, 'analysis'])->whereIn('dimension', array_keys(FinanceController::DIMENSIONS))->name('analysis');
        Route::get('/{dimension}/{key}', [FinanceController::class, 'analysisDetail'])->whereIn('dimension', ['chantiers', 'promoteurs', 'ouvriers'])->name('analysis.show');
    });

    Route::prefix('avis-litiges')->group(function () {
        Route::get('/', [QualityController::class, 'index'])->name('quality.index');
        Route::get('/moderation', [QualityController::class, 'moderation'])->name('quality.moderation');
        Route::get('/signalements', [QualityController::class, 'reports'])->name('quality.reports');
        Route::get('/mediations', [QualityController::class, 'mediations'])->name('quality.mediations');
        Route::get('/recours', [QualityController::class, 'appeals'])->name('quality.appeals');
        Route::get('/rapports', [QualityController::class, 'reportsCatalog'])->name('quality.catalog');
        Route::get('/rapports/generer', [QualityController::class, 'generate'])->name('quality.generate');
        Route::get('/notifications', [QualityController::class, 'notifications'])->name('quality.notifications');
        Route::get('/audit', [QualityController::class, 'audit'])->name('quality.audit');
        Route::post('/messages', [DisputeActionController::class, 'message'])->name('quality.message');

        Route::prefix('avis')->name('reviews.')->group(function () {
            Route::get('/', [ReviewController::class, 'index'])->name('index');
            Route::post('/eligibilite', [ReviewActionController::class, 'eligibility'])->name('eligibility');
            Route::prefix('{review}')->group(function () {
                Route::post('/moderation', [ReviewActionController::class, 'moderate'])->name('moderate');
                Route::post('/correction', [ReviewActionController::class, 'askChange'])->name('ask-change');
                Route::post('/signalements', [ReviewActionController::class, 'report'])->name('report');
                Route::post('/signalements/{report}', [ReviewActionController::class, 'decideReport'])->name('decide-report');
                Route::post('/detections/{index}', [ReviewActionController::class, 'decideDetection'])->whereNumber('index')->name('decide-detection');
                Route::post('/reponse', [ReviewActionController::class, 'moderateReply'])->name('moderate-reply');
                Route::post('/reponse/notification', [ReviewActionController::class, 'notifyReply'])->name('notify-reply');
                Route::post('/reponse/depot', [ReviewActionController::class, 'submitReply'])->name('submit-reply');
                Route::post('/litige', [ReviewActionController::class, 'link'])->name('link');
                Route::get('/{tab?}', [ReviewController::class, 'show'])->where('tab', 'notes|commentaire|preuves|reponse|signalements|moderation|historique')->name('show');
            });
        });

        Route::prefix('reclamations')->name('claims.')->group(function () {
            Route::get('/', [ClaimController::class, 'index'])->name('index');
            Route::post('/', [ClaimActionController::class, 'store'])->name('store');
            Route::get('/{claim}', [ClaimController::class, 'show'])->name('show');
            Route::post('/{claim}/qualification', [ClaimActionController::class, 'qualify'])->name('qualify');
            Route::post('/{claim}/resolution', [ClaimActionController::class, 'resolve'])->name('resolve');
            Route::post('/{claim}/cloture', [ClaimActionController::class, 'close'])->name('close');
            Route::post('/{claim}/litige', [ClaimActionController::class, 'transform'])->name('transform');
        });

        Route::prefix('litiges')->name('disputes.')->group(function () {
            Route::get('/', [DisputeController::class, 'index'])->name('index');
            Route::post('/', [DisputeActionController::class, 'store'])->name('store');
            Route::get('/nouveau', [DisputeController::class, 'create'])->name('create');
            Route::post('/affectation', [DisputeActionController::class, 'bulkAssign'])->name('bulk-assign');
            Route::prefix('{dispute}')->group(function () {
                Route::post('/recevabilite', [DisputeActionController::class, 'qualify'])->name('qualify');
                Route::post('/affectation', [DisputeActionController::class, 'assign'])->name('assign');
                Route::post('/priorite', [DisputeActionController::class, 'priority'])->name('priority');
                Route::post('/informations', [DisputeActionController::class, 'requestInfo'])->name('info');
                Route::post('/informations/recues', [DisputeActionController::class, 'infoReceived'])->name('info-received');
                Route::post('/preuves', [DisputeActionController::class, 'addProof'])->name('proofs.store');
                Route::post('/preuves/{proof}/statut', [DisputeActionController::class, 'proofStatus'])->name('proofs.status');
                Route::post('/preuves/{proof}/version', [DisputeActionController::class, 'proofVersion'])->name('proofs.version');
                Route::post('/preuves/{proof}/consultation', [DisputeActionController::class, 'proofView'])->name('proofs.view');
                Route::post('/declarations', [DisputeActionController::class, 'declare'])->name('declarations.store');
                Route::post('/declarations/{declaration}/version', [DisputeActionController::class, 'declarationVersion'])->name('declarations.version');
                Route::post('/reponse', [DisputeActionController::class, 'response'])->name('response');
                Route::post('/notes', [DisputeActionController::class, 'note'])->name('note');
                Route::post('/mediation', [DisputeActionController::class, 'proposeMediation'])->name('mediation.propose');
                Route::post('/mediation/acceptation', [DisputeActionController::class, 'mediationAnswer'])->name('mediation.answer');
                Route::post('/mediation/echange', [DisputeActionController::class, 'mediationExchange'])->name('mediation.exchange');
                Route::post('/mediation/proposition', [DisputeActionController::class, 'mediationProposal'])->name('mediation.proposal');
                Route::post('/mediation/accord', [DisputeActionController::class, 'mediationAgreement'])->name('mediation.agreement');
                Route::post('/mediation/validation', [DisputeActionController::class, 'mediationValidate'])->name('mediation.validate');
                Route::post('/mediation/echec', [DisputeActionController::class, 'mediationFail'])->name('mediation.fail');
                Route::post('/mediation/cloture', [DisputeActionController::class, 'mediationClose'])->name('mediation.close');
                Route::post('/demandes-financieres', [DisputeActionController::class, 'financial'])->name('financial');
                Route::post('/remplacements', [DisputeActionController::class, 'replacement'])->name('replacement');
                Route::post('/corrections-pointage', [DisputeActionController::class, 'attendance'])->name('attendance');
                Route::post('/demandes/{kind}/{ref}/synchronisation', [DisputeActionController::class, 'syncRequest'])->whereIn('kind', ['fin', 'rempl', 'ptc'])->name('requests.sync');
                Route::post('/decision', [DisputeActionController::class, 'prepareDecision'])->name('decision.prepare');
                Route::post('/decision/rendu', [DisputeActionController::class, 'renderDecision'])->name('decision.render');
                Route::post('/execution', [DisputeActionController::class, 'executed'])->name('executed');
                Route::post('/recours', [DisputeActionController::class, 'openAppeal'])->name('appeals.store');
                Route::post('/recours/{index}/recevable', [DisputeActionController::class, 'appealAdmissible'])->whereNumber('index')->name('appeals.admissible');
                Route::post('/recours/{index}/non-recevable', [DisputeActionController::class, 'appealInadmissible'])->whereNumber('index')->name('appeals.inadmissible');
                Route::post('/recours/{index}/decision', [DisputeActionController::class, 'appealDecision'])->whereNumber('index')->name('appeals.decision');
                Route::post('/cloture', [DisputeActionController::class, 'close'])->name('close');
                Route::post('/reouverture', [DisputeActionController::class, 'reopen'])->name('reopen');
                Route::post('/annulation', [DisputeActionController::class, 'cancel'])->name('cancel');
                Route::post('/archivage', [DisputeActionController::class, 'archive'])->name('archive');
                Route::get('/{tab?}', [DisputeController::class, 'show'])->where('tab', 'parties|chronologie|declarations|preuves|pointages|paiements|mediation|decision|execution|recours|historique')->name('show');
            });
        });
    });

    Route::prefix('rapports')->name('analytics.')->group(function () {
        Route::get('/', [AnalyticsController::class, 'index'])->name('index');
        Route::get('/generer', [AnalyticsController::class, 'generate'])->name('generate');
        Route::post('/bibliotheque', [AnalyticsController::class, 'store'])->name('reports.store');
        Route::get('/bibliotheque/{report}/telechargement', [AnalyticsController::class, 'download'])->name('reports.download');
        Route::post('/programmations', [AnalyticsController::class, 'schedule'])->name('schedules.store');
        Route::post('/programmations/{schedule}/statut', [AnalyticsController::class, 'toggle'])->name('schedules.toggle');
        Route::post('/programmations/{schedule}/suppression', [AnalyticsController::class, 'destroy'])->name('schedules.destroy');
        Route::get('/chantiers/{site:slug}', [AnalyticsController::class, 'site'])->name('site');
        Route::get('/{kind}', [AnalyticsController::class, 'show'])->whereIn('kind', ['financier', 'missions', 'chantiers', 'ouvriers', 'bibliotheque'])->name('show');
    });

    Route::prefix('parametres')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/utilisateurs/invitations', [AdminUserController::class, 'invite'])->name('users.invite');
        Route::post('/utilisateurs/{user}/role', [AdminUserController::class, 'role'])->name('users.role');
        Route::post('/utilisateurs/{user}/statut', [AdminUserController::class, 'status'])->name('users.status');
        Route::post('/utilisateurs/{user}/invitation', [AdminUserController::class, 'resend'])->name('users.resend');
        Route::post('/integrations/{integration}/test', [IntegrationController::class, 'test'])->name('integrations.test');
        Route::post('/integrations/{integration}/statut', [IntegrationController::class, 'status'])->name('integrations.status');
        Route::get('/{section}', [SettingsController::class, 'show'])->whereIn('section', array_keys(Settings::SECTIONS))->name('show');
        Route::post('/{section}', [SettingsController::class, 'save'])->whereIn('section', array_keys(Settings::SECTIONS))->name('save');
    });
});
