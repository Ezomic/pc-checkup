<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BenchmarkController;
use App\Http\Controllers\Api\ChecklistEntryController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\MachineNoteController;
use App\Http\Controllers\Api\MachineStatusController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScanReportController;
use App\Http\Middleware\AuthenticateBenchmarkClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('scans', [ScanReportController::class, 'store'])->name('api.scans.store');

Route::middleware([AuthenticateBenchmarkClient::class, 'throttle:bench'])->prefix('bench')->group(function () {
    Route::get('download', [BenchmarkController::class, 'download'])->name('api.bench.download');
    Route::post('upload', [BenchmarkController::class, 'upload'])->name('api.bench.upload');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', function (Request $request) {
        return $request->user()?->only(['id', 'name', 'email']);
    })->name('api.user');

    Route::middleware('abilities:read')->group(function () {
        Route::get('machines', [MachineController::class, 'index'])->name('api.machines.index');
        Route::get('machines/{ownedMachine}', [MachineController::class, 'show'])
            ->whereNumber('ownedMachine')->name('api.machines.show');
    });

    Route::middleware('abilities:write')->group(function () {
        Route::post('reports', [ReportController::class, 'store'])->name('api.reports.store');
        Route::put('machines/{ownedMachine}/status', [MachineStatusController::class, 'update'])
            ->whereNumber('ownedMachine')->name('api.machines.status.update');
        Route::put('machines/{ownedMachine}/checklist/{item}', [ChecklistEntryController::class, 'update'])
            ->whereNumber('ownedMachine')->where('item', '[a-z0-9_]+')->name('api.machines.checklist.update');
        Route::post('machines/{ownedMachine}/notes', [MachineNoteController::class, 'store'])
            ->whereNumber('ownedMachine')->name('api.machines.notes.store');
    });
});
