<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocMergeController;
use App\Http\Controllers\EmailSyncController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('doc-merge', [DocMergeController::class, 'index'])->name('doc-merge.index');
    Route::post('doc-merge', [DocMergeController::class, 'store'])->name('doc-merge.store');
    Route::get('doc-merge/{mergedPdf}', [DocMergeController::class, 'download'])->name('doc-merge.download');
    Route::get('email-sync', [EmailSyncController::class, 'index'])->name('email-sync.index');
    Route::get('email-sync/messages', [EmailSyncController::class, 'emails'])->name('email-sync.emails');
    Route::post('email-sync', [EmailSyncController::class, 'sync'])->name('email-sync.sync');
    Route::post('email-sync/backfill', [EmailSyncController::class, 'backfill'])->name('email-sync.backfill');
    Route::get('email-sync/{syncedEmail}/rendered', [EmailSyncController::class, 'renderedMessage'])
        ->name('email-sync.rendered');
    Route::get('email-sync/{syncedEmail}/attachments/{attachment}/inline', [EmailSyncController::class, 'inlineAttachment'])
        ->name('email-sync.attachments.inline');
    Route::get('email-sync/{syncedEmail}/attachments/{attachment}', [EmailSyncController::class, 'downloadAttachment'])
        ->name('email-sync.attachments.download');
});

require __DIR__.'/settings.php';
