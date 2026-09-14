<?php

use App\Modules\Flows\Http\Controllers\Client\WhatsappFlowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])
    ->prefix('app/flows')
    ->name('client.flows.')
    ->group(function (): void {
        Route::get('/', [WhatsappFlowController::class, 'index'])->name('index');
        Route::post('/', [WhatsappFlowController::class, 'store'])->name('store');
        Route::get('/{flow}', [WhatsappFlowController::class, 'edit'])->name('edit');
        Route::put('/{flow}', [WhatsappFlowController::class, 'update'])->name('update');
        Route::delete('/{flow}', [WhatsappFlowController::class, 'destroy'])->name('destroy');
        Route::get('/{flow}/preview', [WhatsappFlowController::class, 'preview'])->name('preview');
        Route::post('/{flow}/sync', [WhatsappFlowController::class, 'sync'])->name('sync');
        Route::post('/{flow}/publish', [WhatsappFlowController::class, 'publish'])->name('publish');
    });
