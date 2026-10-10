<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Dashboard', [
    'appName' => config('app.name'),
    'laravelVersion' => app()->version(),
]))->name('dashboard');
