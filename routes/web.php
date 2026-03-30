<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocMergeBatchController;
use App\Http\Controllers\DocMergeController;
use App\Http\Controllers\EmailSyncController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('staff')->group(function () {
        Route::controller(DocMergeController::class)
            ->prefix('doc-merge')
            ->name('doc-merge.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('bulk', 'storeBulk')->name('bulk.store');
                Route::post('bulk-folders', 'storeBulkFolders')->name('bulk-folders.store');
                Route::delete('/', 'destroyMany')->name('destroy-many');
                Route::post('confirmation-template', 'storeConfirmationTemplate')
                    ->name('confirmation-template.store');
                Route::get('confirmation-template', 'downloadConfirmationTemplate')
                    ->name('confirmation-template.download');
                Route::controller(DocMergeBatchController::class)
                    ->prefix('batches')
                    ->name('batches.')
                    ->group(function () {
                        Route::get('list', 'batches')->name('list');
                        Route::post('/', 'store')->name('store');
                        Route::get('{docMergeBatch}', 'show')->name('show');
                        Route::get('{docMergeBatch}/results', 'results')->name('results');
                        Route::post('{docMergeBatch}/page-folders', 'storePageFolders')
                            ->name('page-folders.store');
                        Route::post('{docMergeBatch}/zip', 'storeZip')->name('zip.store');
                        Route::delete('{docMergeBatch}/source-files/{sourceFile}', 'destroySourceFile')
                            ->name('source-files.destroy');
                        Route::delete('{docMergeBatch}/page-folders/{pageFolderNumber}', 'destroyPageFolder')
                            ->whereNumber('pageFolderNumber')
                            ->name('page-folders.destroy');
                        Route::post('{docMergeBatch}/process', 'process')->name('process');
                        Route::get('{docMergeBatch}/download', 'download')->name('download');
                        Route::delete('{docMergeBatch}', 'destroy')->name('destroy');
                    });
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
    Route::middleware('superadmin')
        ->controller(UserManagementController::class)
        ->prefix('users')
        ->name('users.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('list', 'users')->name('list');
            Route::post('/', 'store')->name('store');
            Route::delete('/', 'destroyMany')->name('destroy-many');
            Route::put('{user}', 'update')->name('update');
            Route::delete('{user}', 'destroy')->name('destroy');
        });
});

require __DIR__.'/settings.php';
