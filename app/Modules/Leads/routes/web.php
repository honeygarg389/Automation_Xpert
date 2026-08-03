<?php

use App\Modules\Leads\Http\Controllers\LeadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/leads')->name('client.leads.')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::post('/scrape', [LeadController::class, 'scrape'])->name('scrape')->middleware('limit:lead_credits_per_month,lead_credits');
    Route::post('/push-to-contacts', [LeadController::class, 'pushToContacts'])->name('push-to-contacts');
    Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
});
