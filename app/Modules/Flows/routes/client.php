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
        Route::get('/{flow}', [WhatsappFlowController::class, 'edit'])->name('edit');
        Route::put('/{flow}', [WhatsappFlowController::class, 'update'])->name('update');
        Route::delete('/{flow}', [WhatsappFlowController::class, 'destroy'])->name('destroy');
        Route::get('/{flow}/preview', [WhatsappFlowController::class, 'preview'])->name('preview');
        Route::post('/{flow}/sync', [WhatsappFlowController::class, 'sync'])->name('sync');
        Route::post('/{flow}/publish', [WhatsappFlowController::class, 'publish'])->name('publish');
    });
