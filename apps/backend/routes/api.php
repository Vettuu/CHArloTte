<?php

use App\Http\Controllers\KnowledgeSearchController;
use App\Http\Controllers\KnowledgeRebuildController;
use App\Http\Controllers\RealtimeTokenController;
use App\Http\Controllers\RealtimeToolWebhookController;
use App\Http\Controllers\TenantConfigController;
use App\Http\Controllers\ChatMessageLogController;
use App\Http\Controllers\ChatReportExportController;
use App\Http\Controllers\ChatReportKpiController;
use App\Http\Controllers\ChatReportTenantController;
use App\Http\Controllers\ChatReportSessionsController;
use App\Http\Controllers\ChatReportSessionDetailController;
use App\Http\Controllers\ChatReportRetagController;
use App\Http\Controllers\ChatRespondController;
use Illuminate\Support\Facades\Route;

Route::prefix('realtime')->group(function (): void {
    Route::post('token', RealtimeTokenController::class)->name('realtime.token');
    Route::post('invoke-tool', RealtimeToolWebhookController::class)->name('realtime.tool-webhook');
});

Route::post('knowledge/search', KnowledgeSearchController::class)->name('knowledge.search');
Route::post('knowledge/rebuild', KnowledgeRebuildController::class)->name('knowledge.rebuild');
Route::post('chat/respond', ChatRespondController::class)->name('chat.respond');
Route::get('tenant/config', TenantConfigController::class)->name('tenant.config');

Route::post('report/log', ChatMessageLogController::class)->name('report.log');
Route::get('report/export', ChatReportExportController::class)
    ->middleware('report.auth')
    ->name('report.export');
Route::get('report/kpi', ChatReportKpiController::class)
    ->middleware('report.auth')
    ->name('report.kpi');
Route::get('report/tenants', ChatReportTenantController::class)
    ->middleware('report.auth')
    ->name('report.tenants');
Route::get('report/sessions', ChatReportSessionsController::class)
    ->middleware('report.auth')
    ->name('report.sessions');
Route::get('report/session/{sessionId}', ChatReportSessionDetailController::class)
    ->middleware('report.auth')
    ->name('report.session');
Route::post('report/retag', ChatReportRetagController::class)
    ->middleware('report.auth')
    ->name('report.retag');
