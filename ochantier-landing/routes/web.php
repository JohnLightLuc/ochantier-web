<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');

Route::middleware('throttle:landing-forms')->group(function () {
    Route::post('/contact', [LandingController::class, 'contact'])->name('landing.contact');
    Route::post('/newsletter', [LandingController::class, 'newsletter'])->name('landing.newsletter');
    Route::post('/ouvriers', [LandingController::class, 'worker'])->name('landing.worker');
});
