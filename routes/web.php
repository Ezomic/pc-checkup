<?php

declare(strict_types=1);

use App\Http\Controllers\ChecklistEntryController;
use App\Http\Controllers\CheckupScriptController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientShareLinkController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\LoginLinkRequestController;
use App\Http\Controllers\MachineBeforeAfterController;
use App\Http\Controllers\MachineClientController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\MachineHistoryController;
use App\Http\Controllers\MachineNoteController;
use App\Http\Controllers\MachineRustDeskController;
use App\Http\Controllers\MachineStatusController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReportImportController;
use App\Http\Controllers\RustDeskInstallerController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScriptVersionController;
use App\Http\Controllers\SharedClientController;
use App\Http\Controllers\ToolController;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HardenSharePage;
use App\Http\Middleware\SetShareLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::get('health', HealthController::class)->name('health');

Route::get('privacy', PrivacyController::class)->middleware(SetShareLocale::class)->name('privacy');

Route::get('scripts/{script}', CheckupScriptController::class)->name('scripts.download');

Route::get('scripts/version/{script}', ScriptVersionController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, HandleAppearance::class, HandleInertiaRequests::class, AddLinkHeadersForPreloadedAssets::class])
    ->name('scripts.version');

Route::get('tools/rustdesk/windows', RustDeskInstallerController::class)
    ->middleware('throttle:20,1')->name('tools.rustdesk.download');

Route::middleware(['throttle:share', SetShareLocale::class, HardenSharePage::class])->prefix('c/{token}')->group(function () {
    Route::get('/', [SharedClientController::class, 'show'])->name('share.show');
    Route::get('machines/{machine}', [SharedClientController::class, 'machine'])->name('share.machine');
    Route::get('machines/{machine}/before-after', [SharedClientController::class, 'beforeAfter'])->name('share.before-after');
});

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
    Route::redirect('dashboard', '/machines')->name('dashboard');

    Route::resource('notes', NoteController::class)->except('show');

    Route::resource('clients', ClientController::class)->except('edit');
    Route::post('clients/{client}/share-link', [ClientShareLinkController::class, 'store'])->name('clients.share-link.store');
    Route::delete('clients/{client}/share-link', [ClientShareLinkController::class, 'destroy'])->name('clients.share-link.destroy');

    Route::get('tools', [ToolController::class, 'index'])->name('tools.index');

    Route::get('machines', [MachineController::class, 'index'])->name('machines.index');
    Route::get('machines/create', [MachineController::class, 'create'])->name('machines.create');
    Route::post('machines', [MachineController::class, 'store'])->name('machines.store');
    Route::get('machines/{machine}', [MachineController::class, 'show'])->name('machines.show');
    Route::delete('machines/{machine}', [MachineController::class, 'destroy'])->withTrashed()->name('machines.destroy');
    Route::get('machines/{machine}/history', MachineHistoryController::class)->name('machines.history');
    Route::get('machines/{machine}/summary', [MachineController::class, 'summary'])->name('machines.summary');
    Route::get('machines/{machine}/before-after', [MachineBeforeAfterController::class, 'show'])->name('machines.before-after');
    Route::put('machines/{machine}/client', [MachineClientController::class, 'update'])->name('machines.client.update');
    Route::put('machines/{machine}/rustdesk', [MachineRustDeskController::class, 'update'])->name('machines.rustdesk.update');
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
    Route::get('scans/current', [ScanController::class, 'show'])->name('scans.show');
    Route::get('scans/status', [ScanController::class, 'status'])->name('scans.status');
    Route::post('scans', [ScanController::class, 'store'])->name('scans.store');
    Route::get('imports/create', [ReportImportController::class, 'create'])->name('imports.create');
    Route::post('imports', [ReportImportController::class, 'store'])->name('imports.store');
});

require __DIR__.'/settings.php';
