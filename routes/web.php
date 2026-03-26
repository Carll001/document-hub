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
    Route::controller(DocMergeController::class)
        ->prefix('doc-merge')
        ->name('doc-merge.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('bulk', 'storeBulk')->name('bulk.store');
            Route::post('bulk-folders', 'storeBulkFolders')->name('bulk-folders.store');
            Route::delete('/', 'destroyMany')->name('destroy-many');
            Route::get('{mergedPdf}/preview', 'preview')->name('preview');
            Route::post('{mergedPdf}/receipt', 'storeReceipt')->name('receipt.store');
            Route::delete('{mergedPdf}/receipt', 'destroyReceipt')->name('receipt.destroy');
            Route::get('{mergedPdf}/receipt', 'downloadReceipt')->name('receipt.download');
            Route::post('{mergedPdf}/send-email', 'sendEmail')->name('send-email');
            Route::get('{mergedPdf}', 'download')->name('download');
        });
    Route::controller(EmailSyncController::class)
        ->prefix('email-sync')
        ->name('email-sync.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('messages', 'emails')->name('emails');
            Route::post('/', 'sync')->name('sync');
            Route::post('backfill', 'backfill')->name('backfill');
            Route::get('{syncedEmail}/rendered', 'renderedMessage')->name('rendered');
            Route::get('{syncedEmail}/attachments/{attachment}/inline', 'inlineAttachment')
                ->name('attachments.inline');
            Route::get('{syncedEmail}/attachments/{attachment}', 'downloadAttachment')
                ->name('attachments.download');
        });
});

require __DIR__.'/settings.php';
