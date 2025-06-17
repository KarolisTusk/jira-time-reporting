<?php

namespace App\Http\Controllers;

use App\Models\JiraProject;
use App\Models\JiraIssue;
use App\Models\JiraWorklog;
use App\Models\JiraAppUser;
use App\Models\JiraSyncHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the main dashboard with real JIRA data statistics.
     */
    public function index(Request $request)
    {
        try {
            // Get total projects
            $totalProjects = JiraProject::count();

            // Get total time logged (in seconds)
            $totalTimeSeconds = JiraWorklog::sum('time_spent_seconds') ?? 0;
            
            // Convert to hours and format
            $totalTimeHours = round($totalTimeSeconds / 3600, 1);
            $totalTimeFormatted = $this->formatHours($totalTimeHours);

            // Get active users (users who have logged time)
            $activeUsers = JiraAppUser::whereHas('worklogs')->count();

            // Get last sync information
            $lastSync = JiraSyncHistory::where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->first();

            $lastSyncFormatted = $lastSync 
                ? $lastSync->completed_at->diffForHumans() 
                : 'Never';

            // Get additional dashboard metrics
            $totalWorklogs = JiraWorklog::count();
            $totalIssues = JiraIssue::count();

            return Inertia::render('Dashboard', [
                'stats' => [
                    'totalProjects' => $totalProjects,
                    'totalTime' => $totalTimeFormatted,
                    'totalTimeHours' => $totalTimeHours,
                    'activeUsers' => $activeUsers,
                    'lastSync' => $lastSyncFormatted,
                    'totalWorklogs' => $totalWorklogs,
                    'totalIssues' => $totalIssues,
                ],
                'lastSyncDetails' => $lastSync ? [
                    'id' => $lastSync->id,
                    'started_at' => $lastSync->started_at->format('M j, Y g:i A'),
                    'completed_at' => $lastSync->completed_at->format('M j, Y g:i A'),
                    'duration' => $this->formatDuration($lastSync->duration_seconds),
                    'total_projects_processed' => $lastSync->total_projects_processed,
                    'total_issues_processed' => $lastSync->total_issues_processed,
                    'total_worklogs_processed' => $lastSync->total_worklogs_processed,
                ] : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error loading dashboard data: ' . $e->getMessage());

            // Return default values if there's an error
            return Inertia::render('Dashboard', [
                'stats' => [
                    'totalProjects' => 0,
                    'totalTime' => '0 hrs',
                    'totalTimeHours' => 0,
                    'activeUsers' => 0,
                    'lastSync' => 'Error loading data',
                    'totalWorklogs' => 0,
                    'totalIssues' => 0,
                ],
                'lastSyncDetails' => null,
            ]);
        }
    }

    /**
     * Format hours into a human-readable string.
     */
    private function formatHours(float $hours): string
    {
        if ($hours < 1) {
            $minutes = round($hours * 60);
            return $minutes . ' min' . ($minutes !== 1 ? 's' : '');
        }

        if ($hours < 1000) {
            return number_format($hours, 1) . ' hrs';
        }

        return number_format($hours, 0) . ' hrs';
    }

    /**
     * Format duration in seconds to human-readable format.
     */
    private function formatDuration(?int $seconds): string
    {
        if (!$seconds) {
            return 'Unknown';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }
}