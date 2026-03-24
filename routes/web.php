<?php

use App\Http\Controllers\EmailSyncController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('email-sync', [EmailSyncController::class, 'index'])->name('email-sync.index');
    Route::get('email-sync/messages', [EmailSyncController::class, 'emails'])->name('email-sync.emails');
    Route::post('email-sync', [EmailSyncController::class, 'sync'])->name('email-sync.sync');
    Route::post('email-sync/backfill', [EmailSyncController::class, 'backfill'])->name('email-sync.backfill');
    Route::get('email-sync/{syncedEmail}/attachments/{attachment}', [EmailSyncController::class, 'downloadAttachment'])
        ->name('email-sync.attachments.download');
});

require __DIR__.'/settings.php';
