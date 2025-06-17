<?php

use App\Http\Controllers\Admin\EnhancedJiraSyncController;
use App\Http\Controllers\Admin\InitiativeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Admin routes for enhanced JIRA synchronization and management.
| These routes require authentication and admin-level permissions.
|
*/

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    
    // Enhanced JIRA Sync Routes
    Route::prefix('jira')->name('jira.')->group(function () {
        
        // Main enhanced sync page
        Route::get('/sync', [EnhancedJiraSyncController::class, 'index'])
            ->name('sync.index');
        
        // Sync operations
        Route::post('/sync/start', [EnhancedJiraSyncController::class, 'startSync'])
            ->name('sync.start');
            
        Route::post('/sync/cancel', [EnhancedJiraSyncController::class, 'cancelSync'])
            ->name('sync.cancel');
            
        Route::get('/sync/progress', [EnhancedJiraSyncController::class, 'getSyncProgress'])
            ->name('sync.progress');
        
        // Individual sync management
        Route::post('/sync/{syncId}/retry', [EnhancedJiraSyncController::class, 'retrySync'])
            ->name('sync.retry')
            ->where('syncId', '[0-9]+');
            
        Route::get('/sync/{syncId}/details', [EnhancedJiraSyncController::class, 'getSyncDetails'])
            ->name('sync.details')
            ->where('syncId', '[0-9]+');
        
        // Project-specific operations
        Route::post('/sync/project/{projectKey}/retry', [EnhancedJiraSyncController::class, 'retryProject'])
            ->name('sync.project.retry')
            ->where('projectKey', '[A-Z0-9_-]+');
        
        // Connection and diagnostics
        Route::post('/test-connection', [EnhancedJiraSyncController::class, 'testConnection'])
            ->name('test-connection');
        
        // Metrics and reporting
        Route::get('/metrics', [EnhancedJiraSyncController::class, 'getMetrics'])
            ->name('metrics');
        
        // Advanced operations
        Route::post('/validate-data', [EnhancedJiraSyncController::class, 'validateData'])
            ->name('validate-data');
            
        Route::post('/reclassify-resources', [EnhancedJiraSyncController::class, 'reclassifyResources'])
            ->name('reclassify-resources');
            
        Route::post('/cleanup', [EnhancedJiraSyncController::class, 'performCleanup'])
            ->name('cleanup');
        
        // Error handling and diagnostics
        Route::get('/sync/{syncHistory}/errors/download', [EnhancedJiraSyncController::class, 'downloadErrorLog'])
            ->name('sync.errors.download');
            
        Route::get('/sync/{syncHistory}/errors/details', [EnhancedJiraSyncController::class, 'getErrorDetails'])
            ->name('sync.errors.details');
            
        // JIRA Issues Management
        Route::prefix('issues')->name('issues.')->group(function () {
            Route::get('/', [App\Http\Controllers\Admin\JiraIssuesController::class, 'index'])
                ->name('index');
            Route::get('/data', [App\Http\Controllers\Admin\JiraIssuesController::class, 'getData'])
                ->name('data');
            Route::get('/export', [App\Http\Controllers\Admin\JiraIssuesController::class, 'export'])
                ->name('export');
            Route::get('/{issueKey}', [App\Http\Controllers\Admin\JiraIssuesController::class, 'show'])
                ->name('show');
        });
    });
    
    // Initiative Management Routes
    Route::prefix('initiatives')->name('initiatives.')->group(function () {
        Route::get('/', [InitiativeController::class, 'index'])->name('index');
        Route::get('/create', [InitiativeController::class, 'create'])->name('create');
        Route::post('/', [InitiativeController::class, 'store'])->name('store');
        Route::get('/{initiative}', [InitiativeController::class, 'show'])->name('show');
        Route::get('/{initiative}/edit', [InitiativeController::class, 'edit'])->name('edit');
        Route::put('/{initiative}', [InitiativeController::class, 'update'])->name('update');
        Route::delete('/{initiative}', [InitiativeController::class, 'destroy'])->name('destroy');
        Route::patch('/{initiative}/toggle-status', [InitiativeController::class, 'toggleStatus'])->name('toggle-status');
        Route::get('/{initiative}/metrics', [InitiativeController::class, 'metrics'])->name('metrics');
        Route::get('/{initiative}/export', [InitiativeController::class, 'export'])->name('export');
    });
});