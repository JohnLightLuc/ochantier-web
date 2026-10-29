<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
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
    });
});
