<?php

use App\Http\Controllers\Api\JiraWorklogSyncController;
use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Report API Routes for async data loading
Route::middleware('auth')->prefix('reports')->group(function () {
    Route::get('/project-time-data', [ReportController::class, 'projectTimeData']);
    Route::get('/user-time-per-project-data', [ReportController::class, 'userTimePerProjectData']);
    Route::get('/project-trend-data', [ReportController::class, 'projectTrendData']);
});

// JIRA Worklog Sync API Routes
Route::middleware(['web', 'auth'])->prefix('jira/sync')->group(function () {
    Route::post('/worklogs', [JiraWorklogSyncController::class, 'startWorklogSync']);
    Route::get('/worklogs/status', [JiraWorklogSyncController::class, 'getWorklogSyncStatus']);
    Route::get('/worklogs/stats', [JiraWorklogSyncController::class, 'getWorklogSyncStats']);
    Route::get('/worklogs/validation', [JiraWorklogSyncController::class, 'getWorklogValidationResults']);
    Route::get('/progress/{syncHistoryId}', [JiraWorklogSyncController::class, 'getSyncProgress']);
});
