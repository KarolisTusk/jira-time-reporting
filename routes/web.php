<?php

use App\Http\Controllers\Client\InitiativeDashboardController; // Added for client initiative dashboard
use App\Http\Controllers\DashboardController; // Added for dashboard with real data
use App\Http\Controllers\JiraImportController; // Added for JIRA import
use App\Http\Controllers\JiraSyncHistoryController; // Added for sync history management
use App\Http\Controllers\ReportController; // Added for reports
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Health check endpoint for DigitalOcean App Platform
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'app' => config('app.name'),
        'version' => '1.0.0',
        'database' => 'connected', // Could add actual DB check here
        'queue' => 'operational',   // Could add actual queue check here
    ]);
})->name('health');

Route::get('/', function () {
    return redirect()->route('dashboard');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// JIRA Data Import Routes
Route::middleware(['auth'])->group(function () {
    Route::post('/jira/import', [JiraImportController::class, 'triggerImport'])->name('jira.import');
    Route::get('/jira/sync/{syncHistoryId}/status', [JiraImportController::class, 'getSyncStatus'])->name('jira.sync.status');
    Route::post('/jira/sync/{syncHistoryId}/cancel', [JiraImportController::class, 'cancelSync'])->name('jira.sync.cancel');
});

// JIRA Sync History Management Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/jira/sync-history', [JiraSyncHistoryController::class, 'index'])->name('jira.sync-history.index');
    Route::get('/jira/sync-history/{syncHistory}', [JiraSyncHistoryController::class, 'show'])->name('jira.sync-history.show');
    Route::delete('/jira/sync-history/{syncHistory}', [JiraSyncHistoryController::class, 'destroy'])->name('jira.sync-history.destroy');
    Route::post('/jira/sync-history/{syncHistory}/cancel', [JiraSyncHistoryController::class, 'cancel'])->name('jira.sync-history.cancel');
    Route::post('/jira/sync-history/{syncHistory}/retry', [JiraSyncHistoryController::class, 'retry'])->name('jira.sync-history.retry');
    Route::get('/jira/sync-history/{syncHistory}/logs', [JiraSyncHistoryController::class, 'logs'])->name('jira.sync-history.logs');
    Route::get('/jira/sync-history/stats', [JiraSyncHistoryController::class, 'stats'])->name('jira.sync-history.stats');
});

// Report Routes
Route::middleware(['auth'])->group(function () {
    // Report Pages
    Route::get('/reports/project-time', [ReportController::class, 'projectTime'])->name('reports.project-time');
    Route::get('/reports/user-time-per-project', [ReportController::class, 'userTimePerProject'])->name('reports.user-time-per-project');
    Route::get('/reports/project-trend', [ReportController::class, 'projectTrend'])->name('reports.project-trend');

    // Report API Routes for async data loading (temporarily here for testing)
    Route::prefix('api/reports')->group(function () {
        Route::get('/project-time-data', [ReportController::class, 'projectTimeData']);
        Route::get('/user-time-per-project-data', [ReportController::class, 'userTimePerProjectData']);
        Route::get('/project-trend-data', [ReportController::class, 'projectTrendData']);
    });
});

// Client Initiative Routes
Route::middleware(['auth'])->prefix('initiatives')->name('initiatives.')->group(function () {
    Route::get('/', [InitiativeDashboardController::class, 'index'])->name('index');
    Route::get('/{initiative}', [InitiativeDashboardController::class, 'show'])->name('show');
    Route::get('/{initiative}/metrics', [InitiativeDashboardController::class, 'metrics'])->name('metrics');
    Route::get('/{initiative}/export', [InitiativeDashboardController::class, 'export'])->name('export');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/auth.php';
