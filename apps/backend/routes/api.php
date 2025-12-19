<?php

use App\Http\Controllers\KnowledgeSearchController;
use App\Http\Controllers\KnowledgeRebuildController;
use App\Http\Controllers\RealtimeTokenController;
use App\Http\Controllers\RealtimeToolWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('realtime')->group(function (): void {
    Route::post('token', RealtimeTokenController::class)->name('realtime.token');
    Route::post('invoke-tool', RealtimeToolWebhookController::class)->name('realtime.tool-webhook');
});

Route::post('knowledge/search', KnowledgeSearchController::class)->name('knowledge.search');
Route::post('knowledge/rebuild', KnowledgeRebuildController::class)->name('knowledge.rebuild');
