<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocMergeBatchController;
use App\Http\Controllers\DocMergeController;
use App\Http\Controllers\EmailSyncAccountManagementController;
use App\Http\Controllers\EmailSyncController;
use App\Http\Controllers\Form1702ExController;
use App\Http\Controllers\Form1702ExPage1TemplateController;
use App\Http\Controllers\Form1702ExPage2TemplateController;
use App\Http\Controllers\Form1702ExPage3TemplateController;
use App\Http\Controllers\Form1702ExReceiptTemplateController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('staff')->group(function () {
        Route::controller(ClientController::class)
            ->prefix('clients')
            ->name('clients.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{client}', 'show')->name('show');
                Route::post('{client}/forms/1702-ex/send', 'send1702Ex')->name('forms.1702-ex.send');
            });
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
                Route::get('all-emails', 'allEmails')->name('all-emails');
                Route::get('messages', 'emails')->name('emails');
                Route::get('all-emails/messages', 'allEmailMessages')->name('all-emails.messages');
                Route::post('/', 'sync')->name('sync');
                Route::post('backfill', 'backfill')->name('backfill');
                Route::get('{syncedEmail}/rendered', 'renderedMessage')->name('rendered');
                Route::get('{syncedEmail}/attachments/{attachment}/inline', 'inlineAttachment')
                    ->name('attachments.inline');
                Route::get('{syncedEmail}/attachments/{attachment}', 'downloadAttachment')
                    ->name('attachments.download');
            });
        Route::prefix('forms')
            ->name('forms.')
            ->group(function () {
                Route::controller(Form1702ExController::class)
                    ->prefix('1702-ex')
                    ->name('1702-ex.')
                    ->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('alignment', 'alignment')->name('alignment');
                        Route::post('settings', 'updateSettings')->name('settings.update');
                        Route::post('import', 'storeImportDirect')->name('import.store');
                        Route::delete('rows', 'destroyRowsDirect')->name('rows.destroy');
                        Route::get('rows/export', 'downloadRowsList')->name('rows.export');
                        Route::get('rows/export/file', 'downloadRowsListPrepared')->name('rows.export.file');
                        Route::get('rows/{form1702ExBatchRow}/preview', 'previewRowDirect')->name('rows.preview');
                        Route::get('rows/{form1702ExBatchRow}/download', 'downloadRowDirect')->name('rows.download');
                        Route::patch('rows/{form1702ExBatchRow}/recipient', 'updateRecipient')->name('rows.recipient.update');
                        Route::post('rows/{form1702ExBatchRow}/receipt', 'storeReceiptDirect')->name('rows.receipt.store');
                        Route::delete('rows/{form1702ExBatchRow}/receipt', 'destroyReceiptDirect')->name('rows.receipt.destroy');
                        Route::get('rows/{form1702ExBatchRow}/receipt', 'downloadReceiptDirect')->name('rows.receipt.download');
                        Route::post('rows/{form1702ExBatchRow}/regenerate', 'regenerateRowDirect')->name('rows.regenerate');
                        Route::get('completed', 'completed')->name('completed.index');
                        Route::get('completed/download', 'downloadCompleted')->name('completed.download');
                        Route::get('completed/download/file', 'downloadCompletedPrepared')->name('completed.download.file');
                        Route::delete('completed', 'cancelCompletedBulk')->name('completed.cancel.bulk');
                        Route::post('completed/send', 'sendCompletedEmailsBulk')->name('completed.send.bulk');
                        Route::post('completed/{form1702ExBatchRow}/send', 'sendCompletedEmail')->name('completed.send');
                        Route::delete('completed/{form1702ExBatchRow}', 'cancelCompleted')->name('completed.cancel');
                        Route::post('batches', 'storeBatch')->name('batches.store');

                        Route::prefix('batches/{form1702ExBatch}')
                            ->name('batches.')
                            ->group(function () {
                                Route::get('/', 'show')->name('show');
                                Route::post('import', 'storeImport')->name('import.store');
                                Route::post('prefix', 'updatePrefix')->name('prefix.update');
                                Route::post('footer', 'updateFooter')->name('footer.update');
                                Route::delete('rows', 'destroyRows')->name('rows.destroy');
                                Route::get('rows/{form1702ExBatchRow}/preview', 'previewRow')->name('rows.preview');
                                Route::get('rows/{form1702ExBatchRow}/download', 'downloadRow')->name('rows.download');
                                Route::post('rows/{form1702ExBatchRow}/receipt', 'storeReceipt')->name('rows.receipt.store');
                                Route::delete('rows/{form1702ExBatchRow}/receipt', 'destroyReceipt')->name('rows.receipt.destroy');
                                Route::get('rows/{form1702ExBatchRow}/receipt', 'downloadReceipt')->name('rows.receipt.download');
                                Route::post('rows/{form1702ExBatchRow}/regenerate', 'regenerateRow')->name('rows.regenerate');
                            });
                    });

                Route::controller(Form1702ExReceiptTemplateController::class)
                    ->prefix('1702-ex/receipt-template')
                    ->name('1702-ex.receipt-template.')
                    ->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::post('/', 'generate')->name('generate');
                        Route::get('preview', 'preview')->name('preview');
                        Route::get('download', 'download')->name('download');
                    });

                Route::controller(Form1702ExPage1TemplateController::class)
                    ->prefix('1702-ex/page-1-template')
                    ->name('1702-ex.page-1-template.')
                    ->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::post('/', 'generate')->name('generate');
                        Route::get('preview', 'preview')->name('preview');
                        Route::get('download', 'download')->name('download');
                    });

                Route::controller(Form1702ExPage2TemplateController::class)
                    ->prefix('1702-ex/page-2-template')
                    ->name('1702-ex.page-2-template.')
                    ->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::post('/', 'generate')->name('generate');
                        Route::get('preview', 'preview')->name('preview');
                        Route::get('download', 'download')->name('download');
                    });

                Route::controller(Form1702ExPage3TemplateController::class)
                    ->prefix('1702-ex/page-3-template')
                    ->name('1702-ex.page-3-template.')
                    ->group(function () {
                        Route::get('/', 'show')->name('show');
                        Route::post('/', 'generate')->name('generate');
                        Route::get('preview', 'preview')->name('preview');
                        Route::get('download', 'download')->name('download');
                    });
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
    Route::middleware('superadmin')
        ->controller(EmailSyncAccountManagementController::class)
        ->prefix('mailbox-accounts')
        ->name('mailbox-accounts.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::delete('/', 'destroyMany')->name('destroy-many');
            Route::put('{emailSyncAccount}', 'update')->name('update');
            Route::delete('{emailSyncAccount}', 'destroy')->name('destroy');
        });
});

require __DIR__.'/settings.php';
