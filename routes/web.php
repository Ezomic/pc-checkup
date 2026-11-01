<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\ReportImportController;
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

    Route::get('machines', [MachineController::class, 'index'])->name('machines.index');
    Route::get('imports/create', [ReportImportController::class, 'create'])->name('imports.create');
    Route::post('imports', [ReportImportController::class, 'store'])->name('imports.store');
});

require __DIR__.'/settings.php';
