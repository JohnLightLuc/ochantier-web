<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\CommercialActivationController;
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
use App\Http\Controllers\MatchingController;
use App\Http\Controllers\NeedController;
use App\Http\Controllers\NeedDecisionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\PromoterController;
use App\Http\Controllers\PromoterFormController;
use App\Http\Controllers\PromoterReviewController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\ReviewActionController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SalesAgentAccessController;
use App\Http\Controllers\SalesAgentController;
use App\Http\Controllers\SalesAgentFormController;
use App\Http\Controllers\SalesRankingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteDecisionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerFormController;
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

// Lien reçu par un commercial (adresse fixée côté API : COMMERCIAL_ACTIVATION_URL).
Route::get('/activation-commercial', [CommercialActivationController::class, 'create'])->name('commercial-activation');
Route::post('/activation-commercial', [CommercialActivationController::class, 'store'])->middleware('throttle:6,1')->name('commercial-activation.store');

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/mot-de-passe/nouveau', [PasswordController::class, 'create'])->name('password.change');
    Route::put('/mot-de-passe', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'password.changed'])->group(function () {

    Route::get('/', DashboardController::class)->name('dashboard');

    // Promoteurs : données de l’API (api/v1/admin/promoters). Liste, fiche et ses onglets, création, modification
    // et décisions sur le dossier.
    Route::prefix('promoteurs')->name('promoters.')->group(function () {
        Route::get('/', [PromoterController::class, 'index'])->name('index');
        Route::get('/export', [PromoterController::class, 'export'])->name('export');
        Route::get('/nouveau', [PromoterFormController::class, 'create'])->name('create');
        Route::post('/', [PromoterFormController::class, 'store'])->name('store');
        Route::post('/validation-groupee', [PromoterReviewController::class, 'approveMany'])->name('validate-many');

        Route::prefix('{promoter}')->whereUuid('promoter')->group(function () {
            Route::get('/modifier', [PromoterFormController::class, 'edit'])->name('edit');
            Route::patch('/', [PromoterFormController::class, 'update'])->name('update');
            Route::post('/documents', [PromoterFormController::class, 'storeDocument'])->name('documents.store');
            Route::post('/validation', [PromoterReviewController::class, 'approve'])->name('validate');
            Route::post('/corrections', [PromoterReviewController::class, 'requestCorrections'])->name('corrections');
            Route::post('/refus', [PromoterReviewController::class, 'reject'])->name('reject');
            Route::post('/suspension', [PromoterReviewController::class, 'suspend'])->name('suspend');
            Route::delete('/suspension', [PromoterReviewController::class, 'reactivate'])->name('reactivate');
            Route::post('/notifications', [PromoterReviewController::class, 'message'])->name('notify');
            Route::post('/documents/{document}/validation', [PromoterReviewController::class, 'approveDocument'])->whereUuid('document')->name('documents.approve');
            Route::post('/documents/{document}/rejet', [PromoterReviewController::class, 'rejectDocument'])->whereUuid('document')->name('documents.reject');

            Route::get('/{tab?}/{item?}', [PromoterController::class, 'show'])
                ->where(['tab' => 'kyc|chantiers|offres|paiements|avis|activite', 'item' => '[A-Za-z0-9_-]+'])
                ->name('show');
        });

        Route::get('/{promoter}/{rest?}', [PromoterController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Ouvriers : données de l’API (api/v1/admin/workers). Liste, création et modification, fiche, contrôle du dossier
    // (pièces, validation, corrections, refus), suspension et exclusion.
    Route::prefix('ouvriers')->name('workers.')->group(function () {
        Route::get('/', [WorkerController::class, 'index'])->name('index');
        Route::get('/export', [WorkerController::class, 'export'])->name('export');
        Route::post('/validation-groupee', [WorkerStatusController::class, 'approveMany'])->name('validate-many');
        Route::get('/nouveau', [WorkerFormController::class, 'create'])->name('create');
        Route::post('/', [WorkerFormController::class, 'store'])->name('store');

        Route::prefix('{worker}')->whereUuid('worker')->group(function () {
            Route::get('/modifier', [WorkerFormController::class, 'edit'])->name('edit');
            Route::patch('/', [WorkerFormController::class, 'update'])->name('update');
            Route::post('/documents', [WorkerFormController::class, 'storeDocument'])->name('documents.store');
            Route::post('/validation', [WorkerStatusController::class, 'approve'])->name('validate');
            Route::post('/corrections', [WorkerStatusController::class, 'requestCorrections'])->name('corrections');
            Route::post('/rejet', [WorkerStatusController::class, 'reject'])->name('reject');
            Route::post('/suspension', [WorkerStatusController::class, 'suspend'])->name('suspend');
            Route::delete('/suspension', [WorkerStatusController::class, 'reactivate'])->name('reactivate');
            Route::post('/exclusion', [WorkerStatusController::class, 'exclude'])->name('exclude');
            Route::post('/documents/{document}/validation', [WorkerStatusController::class, 'approveDocument'])->whereUuid('document')->name('documents.approve');
            Route::post('/documents/{document}/rejet', [WorkerStatusController::class, 'rejectDocument'])->whereUuid('document')->name('documents.reject');

            Route::get('/{tab?}', [WorkerController::class, 'show'])
                ->where('tab', 'identite-documents|metiers-competences|disponibilites|affectations|pointages|paiements|avis|remplacements|historique')
                ->name('show');
        });

        Route::get('/{worker}/{rest?}', [WorkerController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Commerciaux : données de l’API (api/v1/admin/commercials). Liste, fiche et décisions sur le compte.
    Route::prefix('commerciaux')->name('sales-agents.')->group(function () {
        Route::get('/', [SalesAgentController::class, 'index'])->name('index');
        Route::get('/export', [SalesAgentController::class, 'export'])->name('export');
        Route::get('/nouveau', [SalesAgentFormController::class, 'create'])->name('create');
        Route::post('/', [SalesAgentFormController::class, 'store'])->name('store');

        Route::get('/classement/export', [SalesRankingController::class, 'export'])->name('ranking.export');
        Route::post('/classement/cloture', [SalesRankingController::class, 'close'])->name('ranking.close');
        Route::post('/classement/publication', [SalesRankingController::class, 'publish'])->name('ranking.publish');
        Route::get('/classement', [SalesRankingController::class, 'show'])->name('ranking.show');

        Route::prefix('{commercial}')->whereUuid('commercial')->group(function () {
            Route::get('/modifier', [SalesAgentFormController::class, 'edit'])->name('edit');
            Route::patch('/', [SalesAgentFormController::class, 'update'])->name('update');
            Route::post('/documents', [SalesAgentFormController::class, 'storeDocument'])->name('documents.store');
            Route::post('/documents/{document}/validation', [SalesAgentAccessController::class, 'approveDocument'])->whereUuid('document')->name('documents.approve');
            Route::post('/documents/{document}/rejet', [SalesAgentAccessController::class, 'rejectDocument'])->whereUuid('document')->name('documents.reject');
            Route::post('/lien-acces', [SalesAgentAccessController::class, 'sendLink'])->name('link.send');
            Route::delete('/lien-acces', [SalesAgentAccessController::class, 'revokeLink'])->name('link.revoke');
            Route::delete('/sessions/{session?}', [SalesAgentAccessController::class, 'revokeSessions'])->whereUuid('session')->name('sessions.revoke');
            Route::post('/ouvriers/transfert', [SalesAgentAccessController::class, 'transfer'])->name('workers.transfer');
            Route::post('/zones', [SalesAgentAccessController::class, 'addZone'])->name('zones.store');
            Route::delete('/zones/{commune}', [SalesAgentAccessController::class, 'removeZone'])->whereUuid('commune')->name('zones.destroy');
            Route::post('/demandes-zone/{zoneRequest}/acceptation', [SalesAgentAccessController::class, 'acceptZoneRequest'])->whereUuid('zoneRequest')->name('zone-requests.accept');
            Route::post('/demandes-zone/{zoneRequest}/refus', [SalesAgentAccessController::class, 'refuseZoneRequest'])->whereUuid('zoneRequest')->name('zone-requests.refuse');
            Route::post('/objectifs', [SalesAgentAccessController::class, 'saveObjective'])->name('objectives.save');
            Route::post('/ajustements', [SalesAgentAccessController::class, 'proposeAdjustment'])->name('adjustments.store');
            Route::post('/ajustements/{adjustment}/approbation', [SalesAgentAccessController::class, 'approveAdjustment'])->whereUuid('adjustment')->name('adjustments.approve');
            Route::post('/ajustements/{adjustment}/refus', [SalesAgentAccessController::class, 'refuseAdjustment'])->whereUuid('adjustment')->name('adjustments.refuse');
            Route::post('/appreciations', [SalesAgentAccessController::class, 'storeAppreciation'])->name('appreciations.store');
            Route::post('/suspension', [SalesAgentAccessController::class, 'suspend'])->name('suspend');
            Route::post('/reactivation', [SalesAgentAccessController::class, 'reactivate'])->name('reactivate');
            Route::post('/fin-de-contrat', [SalesAgentAccessController::class, 'terminate'])->name('terminate');
            Route::get('/{tab?}', [SalesAgentController::class, 'show'])->where('tab', 'profil-documents|ouvriers|activite|zones|objectifs|gains|acces-securite|historique')->name('show');
        });

        Route::get('/{commercial}/{rest?}', [SalesAgentController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Chantiers : données de l’API (api/v1/admin/sites). Liste, fiche et décisions.
    Route::prefix('chantiers')->name('sites.')->group(function () {
        Route::get('/', [SiteController::class, 'index'])->name('index');
        Route::get('/export', [SiteController::class, 'export'])->name('export');

        Route::prefix('{site}')->whereUuid('site')->group(function () {
            Route::post('/suspension', [SiteDecisionController::class, 'suspend'])->name('suspend');
            Route::delete('/suspension', [SiteDecisionController::class, 'resume'])->name('resume');
            Route::post('/annulation', [SiteDecisionController::class, 'cancel'])->name('cancel');
            Route::post('/cloture', [SiteDecisionController::class, 'close'])->name('close');
            Route::post('/archivage', [SiteDecisionController::class, 'archive'])->name('archive');
            Route::post('/incidents', [SiteDecisionController::class, 'declareIncident'])->name('incidents.store');
            Route::post('/incidents/{incident}/prise-en-charge', [SiteDecisionController::class, 'startIncident'])->whereUuid('incident')->name('incidents.start');
            Route::post('/incidents/{incident}/resolution', [SiteDecisionController::class, 'resolveIncident'])->whereUuid('incident')->name('incidents.resolve');
            Route::get('/{tab?}', [SiteController::class, 'show'])->where('tab', 'equipe|incidents|documents|historique')->name('show');
        });

        Route::get('/{site}/{rest?}', [SiteController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Besoins : données de l’API (api/v1/admin/offers). Liste, fiche, onglets et décisions.
    Route::prefix('besoins')->name('needs.')->group(function () {
        Route::get('/', [NeedController::class, 'index'])->name('index');
        Route::get('/export', [NeedController::class, 'export'])->name('export');

        Route::prefix('{need}')->whereUuid('need')->group(function () {
            Route::post('/suspension', [NeedDecisionController::class, 'suspend'])->name('suspend');
            Route::delete('/suspension', [NeedDecisionController::class, 'resume'])->name('resume');
            Route::post('/prolongation', [NeedDecisionController::class, 'extend'])->name('extend');
            Route::post('/annulation', [NeedDecisionController::class, 'cancel'])->name('cancel');
            Route::post('/cloture', [NeedDecisionController::class, 'close'])->name('close');
            Route::get('/{tab?}', [NeedController::class, 'show'])->where('tab', 'ouvriers|documents|historique')->name('show');
        });

        Route::get('/{need}/{rest?}', [NeedController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Matching : données de l’API (api/v1/admin/matching). File des besoins, lancement et résultats.
    Route::prefix('matching')->name('matching.')->group(function () {
        Route::get('/', [MatchingController::class, 'index'])->name('index');
        Route::post('/besoins/{offer}/lancement', [MatchingController::class, 'run'])->whereUuid('offer')->name('run');
        Route::post('/besoins/{offer}/envoi', [MatchingController::class, 'dispatch'])->whereUuid('offer')->name('dispatch');
        Route::post('/suggestions/{suggestion}/contact', [MatchingController::class, 'contact'])->whereUuid('suggestion')->name('contact');
        Route::post('/suggestions/{suggestion}/reponse', [MatchingController::class, 'respond'])->whereUuid('suggestion')->name('respond');
        Route::post('/suggestions/{suggestion}/ecart', [MatchingController::class, 'exclude'])->whereUuid('suggestion')->name('exclude');
        Route::delete('/exclusions/{exclusion}', [MatchingController::class, 'lift'])->whereUuid('exclusion')->name('lift');
        Route::post('/exclusions/{exclusion}/derogation', [MatchingController::class, 'requestDerogation'])->whereUuid('exclusion')->name('derogation');
        Route::post('/derogations/{derogation}/approbation', [MatchingController::class, 'approveDerogation'])->whereUuid('derogation')->name('derogation.approve');
        Route::delete('/derogations/{derogation}', [MatchingController::class, 'revokeDerogation'])->whereUuid('derogation')->name('derogation.revoke');
        Route::get('/besoins/{offer}', [MatchingController::class, 'show'])->whereUuid('offer')->name('show');
        Route::get('/{rest}', [MatchingController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Affectations : données de l’API (api/v1/admin/assignments). Liste, fiche et indisponibilité.
    Route::prefix('affectations')->name('assignments.')->group(function () {
        Route::get('/', [AssignmentController::class, 'index'])->name('index');
        Route::get('/export', [AssignmentController::class, 'export'])->name('export');
        Route::post('/remplacements/{replacement}/recherche', [AssignmentController::class, 'searchReplacement'])->whereUuid('replacement')->name('replacement.search');
        Route::post('/remplacements/{replacement}/envoi', [AssignmentController::class, 'sendReplacement'])->whereUuid('replacement')->name('replacement.send');
        Route::post('/{assignment}/indisponibilite', [AssignmentController::class, 'declareUnavailability'])->whereUuid('assignment')->name('unavailability');
        Route::get('/{assignment}', [AssignmentController::class, 'show'])->whereUuid('assignment')->name('show');
        Route::get('/{rest}', [AssignmentController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    // Pointages : données de l’API (api/v1/admin/attendance). Liste, fiche, relance du promoteur, anomalies et corrections.
    Route::prefix('pointages')->name('attendances.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::get('/export', [AttendanceController::class, 'export'])->name('export');
        Route::get('/anomalies', [AttendanceController::class, 'anomalies'])->name('anomalies');
        Route::post('/anomalies/{anomaly}/prise-en-charge', [AttendanceController::class, 'startAnomaly'])->whereUuid('anomaly')->name('anomalies.start');
        Route::post('/anomalies/{anomaly}/cloture', [AttendanceController::class, 'closeAnomaly'])->whereUuid('anomaly')->name('anomalies.close');
        Route::get('/corrections', [AttendanceController::class, 'corrections'])->name('corrections');
        Route::post('/corrections/{correction}/approbation', [AttendanceController::class, 'approveCorrection'])->whereUuid('correction')->name('corrections.approve');
        Route::post('/corrections/{correction}/refus', [AttendanceController::class, 'refuseCorrection'])->whereUuid('correction')->name('corrections.refuse');
        Route::post('/{attendance}/corrections', [AttendanceController::class, 'requestCorrection'])->whereUuid('attendance')->name('corrections.store');
        Route::post('/{attendance}/anomalies', [AttendanceController::class, 'reportAnomaly'])->whereUuid('attendance')->name('anomalies.report');
        Route::post('/{attendance}/relance', [AttendanceController::class, 'remind'])->whereUuid('attendance')->name('remind');
        Route::get('/{attendance}', [AttendanceController::class, 'show'])->whereUuid('attendance')->name('show');
        Route::get('/{rest}', [AttendanceController::class, 'legacy'])->where('rest', '.*')->name('legacy');
    });

    Route::prefix('paiements')->name('payouts.')->controller(PayoutController::class)->group(function () {
        Route::get('/a-reverser', 'payable')->name('payable');
        Route::post('/a-reverser/lot', 'prepare')->name('prepare');
        Route::get('/reversements', 'index')->name('index');
        Route::get('/reversements/export', 'export')->name('export');
        Route::get('/reversements/{payout}', 'show')->whereUuid('payout')->name('show');
        Route::post('/reversements/{payout}/retrait', 'cancel')->whereUuid('payout')->name('cancel');
        Route::post('/reversements/{payout}/renvoi', 'retry')->whereUuid('payout')->name('retry');
        Route::post('/reversements/{payout}/ajustements', 'propose')->whereUuid('payout')->name('adjustments.store');
        Route::post('/reversements/{payout}/ajustements/{adjustment}/approbation', 'approveAdjustment')->whereUuid(['payout', 'adjustment'])->name('adjustments.approve');
        Route::post('/reversements/{payout}/ajustements/{adjustment}/refus', 'refuseAdjustment')->whereUuid(['payout', 'adjustment'])->name('adjustments.refuse');
        Route::get('/lots', 'batches')->name('batches');
        Route::get('/lots/{batch}', 'batch')->whereUuid('batch')->name('batches.show');
        Route::post('/lots/{batch}/soumission', 'submit')->whereUuid('batch')->name('batches.submit');
        Route::post('/lots/{batch}/validation', 'approve')->whereUuid('batch')->name('batches.approve');
        Route::post('/lots/{batch}/renvoi', 'reject')->whereUuid('batch')->name('batches.reject');
        Route::post('/lots/{batch}/annulation', 'cancelBatch')->whereUuid('batch')->name('batches.cancel');
        Route::post('/lots/{batch}/reprise', 'resume')->whereUuid('batch')->name('batches.resume');
        Route::get('/numeros', 'methods')->name('methods');
        Route::get('/commerciaux', 'commercialPayable')->name('commercials.payable');
        Route::post('/commerciaux/versements', 'prepareCommercial')->name('commercials.prepare');
        Route::get('/commerciaux/versements', 'commercialPayouts')->name('commercials.index');
        Route::get('/commerciaux/versements/export', 'exportCommercial')->name('commercials.export');
        Route::get('/commerciaux/versements/{payout}', 'commercialPayout')->whereUuid('payout')->name('commercials.show');
        Route::post('/commerciaux/versements/{payout}/validation', 'approveCommercial')->whereUuid('payout')->name('commercials.approve');
        Route::post('/commerciaux/versements/{payout}/renvoi', 'retryCommercial')->whereUuid('payout')->name('commercials.retry');
        Route::post('/commerciaux/versements/{payout}/annulation', 'cancelCommercial')->whereUuid('payout')->name('commercials.cancel');

        foreach (['ouvriers' => 'workers', 'commerciaux' => 'commercials'] as $segment => $owner) {
            Route::post("/{$segment}/{id}/numero", 'storeMethod')->whereUuid('id')->defaults('owner', $owner)->name("methods.{$owner}.store");
            Route::post("/{$segment}/{id}/numero/{method}/confirmation", 'approveMethod')->whereUuid(['id', 'method'])->defaults('owner', $owner)->name("methods.{$owner}.approve");
            Route::post("/{$segment}/{id}/numero/{method}/refus", 'rejectMethod')->whereUuid(['id', 'method'])->defaults('owner', $owner)->name("methods.{$owner}.reject");
        }
    });

    Route::prefix('paiements')->name('refunds.')->controller(RefundController::class)->group(function () {
        Route::get('/regularisations', 'regularisations')->name('regularisations');
        Route::get('/regularisations/{mission}', 'regularisation')->whereUuid('mission')->name('regularisations.show');
        Route::post('/regularisations/{mission}/calcul', 'run')->whereUuid('mission')->name('regularisations.run');
        Route::post('/regularisations/{mission}/remboursement', 'requestRefund')->whereUuid('mission')->name('regularisations.refund');
        Route::get('/remboursements', 'index')->name('index');
        Route::get('/remboursements/export', 'export')->name('export');
        Route::get('/remboursements/{refund}', 'show')->whereUuid('refund')->name('show');
        Route::post('/remboursements/{refund}/numero-enregistre', 'chooseNumber')->whereUuid('refund')->name('method');
        Route::post('/remboursements/{refund}/numero', 'enterNumber')->whereUuid('refund')->name('number');
        Route::post('/remboursements/{refund}/validation', 'approve')->whereUuid('refund')->name('approve');
        Route::post('/remboursements/{refund}/renvoi', 'retry')->whereUuid('refund')->name('retry');
        Route::post('/remboursements/{refund}/refus', 'refuse')->whereUuid('refund')->name('refuse');
    });

    Route::prefix('paiements')->name('payments.')->controller(PaymentController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::get('/echeances', 'schedules')->name('schedules');
        Route::get('/echeances/export', 'exportSchedules')->name('schedules.export');
        Route::get('/echeances/{schedule}', 'schedule')->whereUuid('schedule')->name('schedules.show');
        Route::post('/echeances/{schedule}/relance', 'remind')->whereUuid('schedule')->name('schedules.remind');
        Route::get('/{transaction}', 'show')->whereUuid('transaction')->name('show');
        Route::get('/{rest}', 'legacy')->where('rest', '.*')->name('legacy');
    });

    // Abonnements : données de l’API (api/v1/admin/subscriptions et subscription-plans).
    Route::prefix('abonnements')->name('subscriptions.')->controller(SubscriptionController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::post('/formules', 'storePlan')->name('plans.store');

        Route::prefix('formules/{plan}')->whereUuid('plan')->name('plans.')->group(function () {
            Route::put('/', 'updatePlan')->name('update');
            Route::post('/retrait', 'retirePlan')->name('retire');
            Route::post('/remise', 'restorePlan')->name('restore');
        });

        Route::prefix('{subscription}')->whereUuid('subscription')->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('/relance', 'remind')->name('remind');
            Route::post('/formule', 'schedulePlan')->name('schedule-plan');
            Route::delete('/formule', 'cancelPlanChange')->name('cancel-plan');
            Route::post('/suspension', 'suspend')->name('suspend');
            Route::post('/reactivation', 'reactivate')->name('reactivate');
            Route::post('/resiliation', 'terminate')->name('terminate');
            Route::delete('/resiliation', 'resume')->name('resume');
        });

        Route::get('/{rest}', 'legacy')->where('rest', '.*')->name('legacy');
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
