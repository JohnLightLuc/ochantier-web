<?php

use App\Http\Controllers\Api\V1\Admin\Assignment\AssignmentController;
use App\Http\Controllers\Api\V1\Admin\Attendance\AnomalyController;
use App\Http\Controllers\Api\V1\Admin\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Admin\Attendance\CorrectionController;
use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\Claim\ClaimController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialAccessController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialAccountController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialCompensationController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialPortfolioController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialRankingController;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialTerritoryController;
use App\Http\Controllers\Api\V1\Admin\Dispute\DisputeController;
use App\Http\Controllers\Api\V1\Admin\Finance\ClosingController;
use App\Http\Controllers\Api\V1\Admin\Finance\FinanceController;
use App\Http\Controllers\Api\V1\Admin\Finance\ReconciliationController;
use App\Http\Controllers\Api\V1\Admin\Matching\MatchingController;
use App\Http\Controllers\Api\V1\Admin\Offer\OfferController;
use App\Http\Controllers\Api\V1\Admin\Payment\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\Payment\RefundController;
use App\Http\Controllers\Api\V1\Admin\Payment\RegularisationController;
use App\Http\Controllers\Api\V1\Admin\Payout\CommercialPayoutController;
use App\Http\Controllers\Api\V1\Admin\Payout\PayoutBatchController;
use App\Http\Controllers\Api\V1\Admin\Payout\PayoutController;
use App\Http\Controllers\Api\V1\Admin\Payout\PayoutMethodController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterAccountController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterRelationController;
use App\Http\Controllers\Api\V1\Admin\Promoter\PromoterReviewController;
use App\Http\Controllers\Api\V1\Admin\Rating\RatingController;
use App\Http\Controllers\Api\V1\Admin\Report\ReportController;
use App\Http\Controllers\Api\V1\Admin\Site\SiteController;
use App\Http\Controllers\Api\V1\Admin\Site\SiteDecisionController;
use App\Http\Controllers\Api\V1\Admin\Site\SiteRelationController;
use App\Http\Controllers\Api\V1\Admin\Subscription\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\Subscription\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerAccountController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerRelationController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerReviewController;
use App\Http\Controllers\Api\V1\Auth\CommercialActivationController;
use App\Support\Admin\ReportCatalog;
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
                Route::controller(PayoutMethodController::class)->group(function () {
                    Route::get('payment-method', 'show')->middleware('admin.can:payouts.read')->name('payment-method.show');
                    Route::post('payment-method', 'store')->middleware('admin.can:payouts.create')->name('payment-method.store');
                    Route::post('payment-method/{method}/approve', 'approve')->whereUuid('method')->middleware('admin.can:payouts.approve')->name('payment-method.approve');
                    Route::post('payment-method/{method}/reject', 'reject')->whereUuid('method')->middleware('admin.can:payouts.approve')->name('payment-method.reject');
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

        // Reversements : ce qui est à reverser, reversements, lots et numéros Mobile Money des ouvriers.
        Route::prefix('payouts')->name('payouts.')->group(function () {
            Route::controller(PayoutController::class)->group(function () {
                Route::get('/', 'index')->middleware('admin.can:payouts.read')->name('index');
                Route::get('payable', 'payable')->middleware('admin.can:payouts.read')->name('payable');
                Route::get('export', 'export')->middleware('admin.can:payouts.export')->name('export');
            });

            Route::get('payment-methods/pending', [PayoutMethodController::class, 'pending'])->middleware('admin.can:payouts.read')->name('payment-methods.pending');

            Route::prefix('batches')->name('batches.')->controller(PayoutBatchController::class)->group(function () {
                Route::get('/', 'index')->middleware('admin.can:payouts.read')->name('index');
                Route::post('/', 'store')->middleware('admin.can:payouts.create')->name('store');

                Route::prefix('{batchId}')->whereUuid('batchId')->group(function () {
                    Route::get('/', 'show')->middleware('admin.can:payouts.read')->name('show');
                    Route::post('submit', 'submit')->middleware('admin.can:payouts.create')->name('submit');
                    Route::post('cancel', 'cancel')->middleware('admin.can:payouts.create')->name('cancel');
                    Route::post('approve', 'approve')->middleware('admin.can:payouts.approve')->name('approve');
                    Route::post('reject', 'reject')->middleware('admin.can:payouts.approve')->name('reject');
                    Route::post('resume', 'resume')->middleware('admin.can:payouts.approve')->name('resume');
                });
            });

            Route::prefix('{payoutId}')->whereUuid('payoutId')->controller(PayoutController::class)->group(function () {
                Route::get('/', 'show')->middleware('admin.can:payouts.read')->name('show');
                Route::post('cancel', 'cancel')->middleware('admin.can:payouts.create')->name('cancel');
                Route::post('retry', 'retry')->middleware('admin.can:payouts.approve')->name('retry');
                Route::post('adjustments', 'propose')->middleware('admin.can:payouts.create')->name('adjustments.store');
                Route::post('adjustments/{adjustment}/approve', 'approveAdjustment')->whereUuid('adjustment')->middleware('admin.can:payouts.approve')->name('adjustments.approve');
                Route::post('adjustments/{adjustment}/refuse', 'refuseAdjustment')->whereUuid('adjustment')->middleware('admin.can:payouts.approve')->name('adjustments.refuse');
            });
        });

        // Versements aux commerciaux : ce qui est dû par mois, préparation par une personne, validation par une autre.
        Route::prefix('commercial-payouts')->name('commercial-payouts.')->controller(CommercialPayoutController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:commercial_earnings.read')->name('index');
            Route::post('/', 'store')->middleware('admin.can:commercial_earnings.update')->name('store');
            Route::get('payable', 'payable')->middleware('admin.can:commercial_earnings.read')->name('payable');
            Route::get('export', 'export')->middleware('admin.can:commercial_earnings.export')->name('export');

            Route::prefix('{payoutId}')->whereUuid('payoutId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:commercial_earnings.read')->name('show');
                Route::post('approve', 'approve')->middleware('admin.can:commercial_earnings.approve')->name('approve');
                Route::post('retry', 'retry')->middleware('admin.can:commercial_earnings.approve')->name('retry');
                Route::post('cancel', 'cancel')->middleware('admin.can:commercial_earnings.update')->name('cancel');
            });
        });

        // Fin de mission : écart entre le coût réel et ce qui a été réglé.
        Route::prefix('regularisations')->name('regularisations.')->controller(RegularisationController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:payments.read')->name('index');

            Route::prefix('{missionId}')->whereUuid('missionId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:payments.read')->name('show');
                Route::post('/', 'store')->middleware('admin.can:payments.update')->name('store');
                Route::post('refunds', 'refund')->middleware('admin.can:payments.update')->name('refund');
            });
        });

        // Rapports : vue d’ensemble, consultation et export.
        Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
            Route::get('/', 'overview')->middleware('admin.can:reports.read')->name('overview');

            Route::prefix('{kind}')->whereIn('kind', array_keys(ReportCatalog::KINDS))->group(function () {
                Route::get('/', 'show')->middleware('admin.can:reports.read')->name('show');
                Route::get('export', 'export')->middleware('admin.can:reports.export')->name('export');
            });
        });

        // Finance : chiffres d’une période, calculés sur les flux réels.
        Route::prefix('finance')->name('finance.')->controller(FinanceController::class)->group(function () {
            Route::get('overview', 'overview')->middleware('admin.can:finance.read')->name('overview');
            Route::get('commissions', 'commissions')->middleware('admin.can:finance.read')->name('commissions');
            Route::get('commissions/export', 'export')->middleware('admin.can:finance.export')->name('commissions.export');
        });

        // Clôtures mensuelles : une personne prépare, une autre clôture ; rouvrir (finance.validate) revient à la Direction.
        Route::prefix('finance/closings')->name('finance.closings.')->controller(ClosingController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:finance.read')->name('index');

            Route::prefix('{month}')->where(['month' => '20\d{2}-(0[1-9]|1[0-2])'])->group(function () {
                Route::get('/', 'show')->middleware('admin.can:finance.read')->name('show');
                Route::get('export', 'export')->middleware('admin.can:finance.export')->name('export');
                Route::post('prepare', 'prepare')->middleware('admin.can:finance.update')->name('prepare');
                Route::post('close', 'close')->middleware('admin.can:finance.approve')->name('close');
                Route::post('reopen', 'reopen')->middleware('admin.can:finance.validate')->name('reopen');
            });
        });

        // Rapprochements : un relevé d’opérateur comparé aux opérations de la plateforme.
        Route::prefix('finance/reconciliations')->name('finance.reconciliations.')->controller(ReconciliationController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:finance.read')->name('index');
            Route::post('/', 'store')->middleware('admin.can:finance.update')->name('store');

            Route::prefix('{runId}')->whereUuid('runId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:finance.read')->name('show');
                Route::post('items/{itemId}/accept', 'accept')->whereUuid('itemId')->middleware('admin.can:finance.update')->name('accept');
                Route::post('items/{itemId}/resolve', 'resolve')->whereUuid('itemId')->middleware('admin.can:finance.update')->name('resolve');
            });
        });

        // Réclamations : déposées dans l’application Promoteur ou saisies ici au nom du demandeur.
        Route::prefix('claims')->name('claims.')->controller(ClaimController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:disputes.read')->name('index');
            Route::post('/', 'store')->middleware('admin.can:disputes.update')->name('store');

            Route::prefix('{claimId}')->whereUuid('claimId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:disputes.read')->name('show');
                Route::post('take', 'take')->middleware('admin.can:disputes.update')->name('take');
                Route::post('priority', 'prioritize')->middleware('admin.can:disputes.update')->name('priority');
                Route::post('resolve', 'resolve')->middleware('admin.can:disputes.update')->name('resolve');
                Route::post('reject', 'reject')->middleware('admin.can:disputes.update')->name('reject');
                Route::post('escalate', 'escalate')->middleware('admin.can:disputes.update')->name('escalate');
                Route::post('close', 'close')->middleware('admin.can:disputes.update')->name('close');
            });
        });

        // Litiges : la décision et le recours reviennent chacun à une autre personne que l’instructeur.
        Route::prefix('disputes')->name('disputes.')->controller(DisputeController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:disputes.read')->name('index');
            Route::post('/', 'store')->middleware('admin.can:disputes.update')->name('store');

            Route::prefix('{disputeId}')->whereUuid('disputeId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:disputes.read')->name('show');
                Route::post('take', 'take')->middleware('admin.can:disputes.update')->name('take');
                Route::post('urgency', 'urgency')->middleware('admin.can:disputes.update')->name('urgency');
                Route::post('mission', 'linkMission')->middleware('admin.can:disputes.update')->name('mission');
                Route::post('messages', 'message')->middleware('admin.can:disputes.update')->name('messages');
                Route::post('proofs', 'proof')->middleware('admin.can:disputes.update')->name('proofs');
                Route::post('appeal', 'appeal')->middleware('admin.can:disputes.update')->name('appeal');
                Route::post('close', 'close')->middleware('admin.can:disputes.update')->name('close');
                Route::post('decision', 'decide')->middleware('admin.can:disputes.validate')->name('decision');
                Route::post('refund', 'requestRefund')->middleware('admin.can:disputes.validate')->name('refund');
                Route::post('appeal-decision', 'decideAppeal')->middleware('admin.can:disputes.approve')->name('appeal-decision');
            });
        });

        // Avis : la note et le texte ne changent jamais, chaque décision de modération est motivée.
        Route::prefix('ratings')->name('ratings.')->controller(RatingController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:ratings.read')->name('index');

            Route::prefix('{ratingId}')->whereUuid('ratingId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:ratings.read')->name('show');
                Route::post('report', 'report')->middleware('admin.can:ratings.update')->name('report');
                Route::post('suspicion', 'suspect')->middleware('admin.can:ratings.update')->name('suspect');
                Route::post('keep', 'keep')->middleware('admin.can:ratings.validate')->name('keep');
                Route::post('hide', 'hide')->middleware('admin.can:ratings.validate')->name('hide');
                Route::post('restore', 'restore')->middleware('admin.can:ratings.validate')->name('restore');
            });
        });

        // Abonnements : le promoteur paie dans l’application, le Back Office relance et décide du reste.
        Route::prefix('subscriptions')->name('subscriptions.')->controller(SubscriptionController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:subscriptions.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:subscriptions.export')->name('export');

            Route::prefix('{subscriptionId}')->whereUuid('subscriptionId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:subscriptions.read')->name('show');
                Route::post('remind', 'remind')->middleware('admin.can:subscriptions.update')->name('remind');
                Route::post('plan-change', 'schedulePlan')->middleware('admin.can:subscriptions.update')->name('plan-change');
                Route::delete('plan-change', 'cancelPlanChange')->middleware('admin.can:subscriptions.update')->name('plan-change.cancel');
                Route::post('suspension', 'suspend')->middleware('admin.can:subscriptions.update')->name('suspend');
                Route::post('reactivation', 'reactivate')->middleware('admin.can:subscriptions.update')->name('reactivate');
                Route::post('termination', 'terminate')->middleware('admin.can:subscriptions.update')->name('terminate');
                Route::delete('termination', 'resume')->middleware('admin.can:subscriptions.update')->name('resume');
            });
        });

        Route::prefix('subscription-plans')->name('subscription-plans.')->controller(SubscriptionPlanController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:subscriptions.read')->name('index');
            Route::post('/', 'store')->middleware('admin.can:subscriptions.update')->name('store');

            Route::prefix('{planId}')->whereUuid('planId')->group(function () {
                Route::patch('/', 'update')->middleware('admin.can:subscriptions.update')->name('update');
                Route::post('retire', 'retire')->middleware('admin.can:subscriptions.update')->name('retire');
                Route::post('restore', 'restore')->middleware('admin.can:subscriptions.update')->name('restore');
            });
        });

        // Remboursements : une personne demande, une autre valide, une troisième à partir du seuil.
        Route::prefix('refunds')->name('refunds.')->controller(RefundController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:payments.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:payments.export')->name('export');

            Route::prefix('{refundId}')->whereUuid('refundId')->group(function () {
                Route::get('/', 'show')->middleware('admin.can:payments.read')->name('show');
                Route::post('method', 'chooseNumber')->middleware('admin.can:payments.update')->name('method');
                Route::post('number', 'enterNumber')->middleware('admin.can:payments.update')->name('number');
                Route::post('approve', 'approve')->middleware('admin.can:payments.approve')->name('approve');
                Route::post('retry', 'retry')->middleware('admin.can:payments.approve')->name('retry');
                Route::post('refuse', 'refuse')->middleware('admin.can:payments.approve')->name('refuse');
            });
        });

        // Paiements : encaissements des promoteurs, échéances et relance.
        Route::prefix('payments')->name('payments.')->controller(AdminPaymentController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:payments.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:payments.export')->name('export');
            Route::get('schedules', 'schedules')->middleware('admin.can:payments.read')->name('schedules');
            Route::get('schedules/export', 'exportSchedules')->middleware('admin.can:payments.export')->name('schedules.export');
            Route::get('schedules/{scheduleId}', 'schedule')->whereUuid('scheduleId')->middleware('admin.can:payments.read')->name('schedules.show');
            Route::post('schedules/{scheduleId}/remind', 'remind')->whereUuid('scheduleId')->middleware('admin.can:payments.update')->name('schedules.remind');
            Route::get('{transactionId}', 'show')->whereUuid('transactionId')->middleware('admin.can:payments.read')->name('show');
        });

        // Pointages : liste, export, fiche d’une journée, relance du promoteur, anomalies et corrections.
        Route::prefix('attendance')->name('attendance.')->controller(AttendanceController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:attendance.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:attendance.export')->name('export');
            Route::get('anomalies', [AnomalyController::class, 'index'])->middleware('admin.can:attendance.read')->name('anomalies');
            Route::post('anomalies/{anomalyId}/start', [AnomalyController::class, 'start'])->whereUuid('anomalyId')->middleware('admin.can:attendance.update')->name('anomalies.start');
            Route::post('anomalies/{anomalyId}/close', [AnomalyController::class, 'close'])->whereUuid('anomalyId')->middleware('admin.can:attendance.update')->name('anomalies.close');
            Route::get('corrections', [CorrectionController::class, 'index'])->middleware('admin.can:attendance.read')->name('corrections');
            Route::post('corrections/{correctionId}/approve', [CorrectionController::class, 'approve'])->whereUuid('correctionId')->middleware('admin.can:attendance.validate')->name('corrections.approve');
            Route::post('corrections/{correctionId}/refuse', [CorrectionController::class, 'refuse'])->whereUuid('correctionId')->middleware('admin.can:attendance.validate')->name('corrections.refuse');
            Route::post('{workDayId}/corrections', [CorrectionController::class, 'store'])->whereUuid('workDayId')->middleware('admin.can:attendance.update')->name('corrections.store');
            Route::post('{workDayId}/anomalies', [AnomalyController::class, 'report'])->whereUuid('workDayId')->middleware('admin.can:attendance.update')->name('anomalies.report');
            Route::get('{workDayId}', 'show')->whereUuid('workDayId')->middleware('admin.can:attendance.read')->name('show');
            Route::post('{workDayId}/remind', 'remind')->whereUuid('workDayId')->middleware('admin.can:attendance.update')->name('remind');
        });

        // Affectations : liste, export, fiche, indisponibilité et recherche de remplaçants.
        Route::prefix('assignments')->name('assignments.')->controller(AssignmentController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:missions.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:missions.export')->name('export');
            Route::get('{assignmentId}', 'show')->whereUuid('assignmentId')->middleware('admin.can:missions.read')->name('show');
            Route::post('{assignmentId}/unavailability', 'declareUnavailability')->whereUuid('assignmentId')->middleware('admin.can:missions.update')->name('unavailability');
            Route::post('replacements/{replacementId}/search', 'searchReplacement')->whereUuid('replacementId')->middleware('admin.can:missions.update')->name('replacements.search');
            Route::post('replacements/{replacementId}/send', 'sendReplacement')->whereUuid('replacementId')->middleware('admin.can:missions.update')->name('replacements.send');
        });

        // Matching : file des besoins, lancement du calcul et résultats.
        Route::prefix('matching')->name('matching.')->controller(MatchingController::class)->group(function () {
            Route::get('queue', 'queue')->middleware('admin.can:matching.read')->name('queue');
            Route::get('offers/{offerId}', 'show')->whereUuid('offerId')->middleware('admin.can:matching.read')->name('offers.show');
            Route::post('offers/{offerId}/run', 'run')->whereUuid('offerId')->middleware('admin.can:matching.create')->name('offers.run');
            Route::post('offers/{offerId}/send', 'send')->whereUuid('offerId')->middleware('admin.can:matching.update')->name('offers.send');
            Route::post('suggestions/{suggestionId}/contact', 'contact')->whereUuid('suggestionId')->middleware('admin.can:matching.update')->name('suggestions.contact');
            Route::post('suggestions/{suggestionId}/response', 'respond')->whereUuid('suggestionId')->middleware('admin.can:matching.update')->name('suggestions.response');
            Route::post('suggestions/{suggestionId}/exclusion', 'exclude')->whereUuid('suggestionId')->middleware('admin.can:matching.update')->name('suggestions.exclude');
            Route::delete('exclusions/{exclusionId}', 'lift')->whereUuid('exclusionId')->middleware('admin.can:matching.update')->name('exclusions.lift');
            Route::post('exclusions/{exclusionId}/derogation', 'requestDerogation')->whereUuid('exclusionId')->middleware('admin.can:matching.update')->name('exclusions.derogation');
            Route::get('derogations', 'derogations')->middleware('admin.can:matching.read')->name('derogations');
            Route::post('derogations/{derogationId}/approve', 'approveDerogation')->whereUuid('derogationId')->middleware('admin.can:matching.approve')->name('derogations.approve');
            Route::delete('derogations/{derogationId}', 'revokeDerogation')->whereUuid('derogationId')->middleware('admin.can:matching.approve')->name('derogations.revoke');
        });

        // Besoins (offres des promoteurs) : liste, export, fiche, onglets et décisions.
        Route::prefix('offers')->name('offers.')->controller(OfferController::class)->group(function () {
            Route::get('/', 'index')->middleware('admin.can:offers.read')->name('index');
            Route::get('export', 'export')->middleware('admin.can:offers.export')->name('export');

            Route::prefix('{offerId}')->whereUuid('offerId')->group(function () {
                Route::middleware('admin.can:offers.read')->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::get('workers', 'workers')->name('workers');
                    Route::get('documents', 'documents')->name('documents');
                    Route::get('history', 'history')->name('history');
                });
                Route::post('suspension', 'suspend')->middleware('admin.can:offers.update')->name('suspend');
                Route::delete('suspension', 'resume')->middleware('admin.can:offers.update')->name('resume');
                Route::post('extension', 'extend')->middleware('admin.can:offers.update')->name('extend');
                Route::post('cancellation', 'cancel')->middleware('admin.can:offers.validate')->name('cancel');
                Route::post('closure', 'close')->middleware('admin.can:offers.validate')->name('close');
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
                Route::middleware('admin.can:sites.read')->controller(SiteRelationController::class)->group(function () {
                    Route::get('team', 'team')->name('team');
                    Route::get('incidents', 'incidents')->name('incidents');
                    Route::get('documents', 'documents')->name('documents');
                    Route::get('history', 'history')->name('history');
                });
                Route::middleware('admin.can:sites.update')->controller(SiteRelationController::class)->group(function () {
                    Route::post('incidents', 'declareIncident')->name('incidents.store');
                    Route::post('incidents/{incident}/start', 'startIncident')->whereUuid('incident')->name('incidents.start');
                    Route::post('incidents/{incident}/resolve', 'resolveIncident')->whereUuid('incident')->name('incidents.resolve');
                });
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
                Route::controller(PayoutMethodController::class)->group(function () {
                    Route::get('payment-method', 'showCommercial')->middleware('admin.can:commercial_earnings.read')->name('payment-method.show');
                    Route::post('payment-method', 'storeCommercial')->middleware('admin.can:commercial_earnings.update')->name('payment-method.store');
                    Route::post('payment-method/{method}/approve', 'approveCommercial')->whereUuid('method')->middleware('admin.can:commercial_earnings.approve')->name('payment-method.approve');
                    Route::post('payment-method/{method}/reject', 'rejectCommercial')->whereUuid('method')->middleware('admin.can:commercial_earnings.approve')->name('payment-method.reject');
                });
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
