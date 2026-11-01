<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A signed-in visitor wants the app, not the front door.
Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : Inertia::render('Welcome'))->name('home');

Route::get('health', HealthController::class)->name('health');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    Route::resource('notes', NoteController::class)->except('show');
});

require __DIR__.'/settings.php';
