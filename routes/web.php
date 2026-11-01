<?php

declare(strict_types=1);

use App\Http\Controllers\ChecklistEntryController;
use App\Http\Controllers\CheckupScriptController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\LoginLinkRequestController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\MachineNoteController;
use App\Http\Controllers\MachineStatusController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ReportImportController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A signed-in visitor wants the app, not the front door.
Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : Inertia::render('Welcome'))->name('home');

Route::get('health', HealthController::class)->name('health');

Route::get('scripts/{script}', CheckupScriptController::class)->name('scripts.download');

Route::middleware('guest')->group(function () {
    Route::post('login/link', LoginLinkRequestController::class)
        ->middleware('throttle:login-link')->name('login.link.request');
    Route::get('login/link-invalid', [LoginLinkController::class, 'invalid'])->name('login.link.invalid');

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('login/link/{token}', [LoginLinkController::class, 'show'])->name('login.link.show');
        Route::post('login/link/{token}', [LoginLinkController::class, 'consume'])->name('login.link.consume');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    Route::resource('notes', NoteController::class)->except('show');

    Route::get('machines', [MachineController::class, 'index'])->name('machines.index');
    Route::get('machines/create', [MachineController::class, 'create'])->name('machines.create');
    Route::post('machines', [MachineController::class, 'store'])->name('machines.store');
    Route::get('machines/{machine}', [MachineController::class, 'show'])->name('machines.show');
    Route::get('machines/{machine}/summary', [MachineController::class, 'summary'])->name('machines.summary');
    Route::put('machines/{machine}/status', [MachineStatusController::class, 'update'])->name('machines.status.update');
    Route::put('machines/{machine}/checklist/{item}', [ChecklistEntryController::class, 'update'])
        ->where('item', '[a-z0-9_]+')->name('machines.checklist.update');

    Route::scopeBindings()->group(function () {
        Route::post('machines/{machine}/notes', [MachineNoteController::class, 'store'])->name('machines.notes.store');
        Route::delete('machines/{machine}/notes/{note}', [MachineNoteController::class, 'destroy'])->name('machines.notes.destroy');
        Route::post('machines/{machine}/photos', [PhotoController::class, 'store'])->name('machines.photos.store');
        Route::get('machines/{machine}/photos/{photo}', [PhotoController::class, 'show'])->name('machines.photos.show');
        Route::delete('machines/{machine}/photos/{photo}', [PhotoController::class, 'destroy'])->name('machines.photos.destroy');
    });
    Route::get('imports/create', [ReportImportController::class, 'create'])->name('imports.create');
    Route::post('imports', [ReportImportController::class, 'store'])->name('imports.store');
});

require __DIR__.'/settings.php';
