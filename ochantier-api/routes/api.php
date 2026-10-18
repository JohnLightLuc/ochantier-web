<?php

use App\Http\Controllers\Api\V1\Auth\CommercialLoginController;
use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\PromoterLoginController;
use App\Http\Controllers\Api\V1\Auth\PromoterRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
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
        });
    });
});
