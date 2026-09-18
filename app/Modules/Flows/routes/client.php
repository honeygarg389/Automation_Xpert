<?php

use App\Modules\Flows\Http\Controllers\Client\WhatsappFlowController;
use App\Modules\Flows\Http\Controllers\Client\WhatsappFlowKeyPairController;
use App\Modules\Flows\Http\Middleware\EnsureFlowsEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app', EnsureFlowsEnabled::class])
    ->prefix('app/flows')
    ->name('client.flows.')
    ->group(function (): void {
        Route::get('/encryption-keys', [WhatsappFlowKeyPairController::class, 'index'])->name('keys.index');
        Route::post('/encryption-keys/{phoneNumber}/generate', [WhatsappFlowKeyPairController::class, 'generate'])->name('keys.generate');
        Route::post('/encryption-keys/{phoneNumber}/upload', [WhatsappFlowKeyPairController::class, 'upload'])->name('keys.upload');
        Route::post('/encryption-keys/{phoneNumber}/rotate', [WhatsappFlowKeyPairController::class, 'rotate'])->name('keys.rotate');
        Route::get('/', [WhatsappFlowController::class, 'index'])->name('index');
        Route::post('/', [WhatsappFlowController::class, 'store'])->name('store');
        Route::post('/sync-status', [WhatsappFlowController::class, 'syncStatus'])->name('sync-status');
        Route::get('/import', [WhatsappFlowController::class, 'importPicker'])->name('import.picker');
        Route::post('/import', [WhatsappFlowController::class, 'import'])->name('import.store');
        Route::get('/{flow}/submissions', [WhatsappFlowController::class, 'submissions'])->name('submissions');
        Route::get('/{flow}', [WhatsappFlowController::class, 'edit'])->name('edit');
        Route::put('/{flow}', [WhatsappFlowController::class, 'update'])->name('update');
        Route::delete('/{flow}', [WhatsappFlowController::class, 'destroy'])->name('destroy');
        Route::get('/{flow}/preview', [WhatsappFlowController::class, 'preview'])->name('preview');
        Route::post('/{flow}/sync', [WhatsappFlowController::class, 'sync'])->name('sync');
        Route::post('/{flow}/publish', [WhatsappFlowController::class, 'publish'])->name('publish');
        Route::post('/{flow}/web-form/enable', [WhatsappFlowController::class, 'enableWebForm'])->name('web-form.enable');
        Route::post('/{flow}/web-form/disable', [WhatsappFlowController::class, 'disableWebForm'])->name('web-form.disable');
        Route::post('/{flow}/web-form/regenerate', [WhatsappFlowController::class, 'regenerateWebFormSlug'])->name('web-form.regenerate');
        Route::put('/{flow}/web-form/recaptcha', [WhatsappFlowController::class, 'updateWebFormRecaptcha'])->name('web-form.recaptcha');
    });
