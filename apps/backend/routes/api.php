<?php

use App\Http\Controllers\RealtimeTokenController;
use App\Http\Controllers\RealtimeToolWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('realtime')->group(function (): void {
    Route::post('token', RealtimeTokenController::class)->name('realtime.token');
    Route::post('invoke-tool', RealtimeToolWebhookController::class)->name('realtime.tool-webhook');
});
