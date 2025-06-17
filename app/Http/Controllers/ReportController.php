<?php

namespace App\Http\Controllers;

use App\Models\JiraProject;
use App\Models\JiraWorklog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Display the project time report page.
     */
    public function projectTime(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        try {
            // Fetch data using our scope
            $reportData = JiraWorklog::totalTimePerProject($startDate, $endDate)->get();

            // Transform data for frontend consumption
            $chartData = $this->transformProjectTimeData($reportData);

            return Inertia::render('Reports/ProjectTime', [
                'reportData' => $chartData,
                'filters' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'availableProjects' => JiraProject::select('id', 'project_key', 'name')->get(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating project time report: '.$e->getMessage());

            return back()->with('error', 'Failed to generate project time report. Please try again.');
        }
    }

    /**
     * Display the user time per project report page.
     */
    public function userTimePerProject(Request $request)
    {
        $projectId = $request->input('project_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        try {
            // Fetch data using our scope
            $reportData = JiraWorklog::totalTimeByUserForProject($projectId, $startDate, $endDate)->get();

            // Transform data for frontend consumption
            $chartData = $this->transformUserTimeData($reportData);

            return Inertia::render('Reports/UserTimePerProject', [
                'reportData' => $chartData,
                'filters' => [
                    'project_id' => $projectId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'availableProjects' => JiraProject::select('id', 'project_key', 'name')->get(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating user time per project report: '.$e->getMessage());

            return back()->with('error', 'Failed to generate user time report. Please try again.');
        }
    }

    /**
     * Display the project time trend report page.
     */
    public function projectTrend(Request $request)
    {
        $projectIds = $request->input('project_ids', []);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $period = $request->input('period', 'weekly');

        // Ensure project_ids is an array
        if (is_string($projectIds)) {
            $projectIds = explode(',', $projectIds);
        }

        try {
            // Fetch data using our scope
            $reportData = JiraWorklog::projectTimeTrend($period, $projectIds, $startDate, $endDate)->get();

            // Transform data for frontend consumption
            $chartData = $this->transformTrendData($reportData, $period);

            return Inertia::render('Reports/ProjectTrend', [
                'reportData' => $chartData,
                'filters' => [
                    'project_ids' => $projectIds,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'period' => $period,
                ],
                'availableProjects' => JiraProject::select('id', 'project_key', 'name')->get(),
                'availablePeriods' => [
                    ['value' => 'daily', 'label' => 'Daily'],
                    ['value' => 'weekly', 'label' => 'Weekly'],
                    ['value' => 'monthly', 'label' => 'Monthly'],
                    ['value' => 'yearly', 'label' => 'Yearly'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating project trend report: '.$e->getMessage());

            return back()->with('error', 'Failed to generate project trend report. Please try again.');
        }
    }

    /**
     * API endpoint for project time data (for async loading).
     */
    public function projectTimeData(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        try {
            $reportData = JiraWorklog::totalTimePerProject($startDate, $endDate)->get();

            return response()->json([
                'success' => true,
                'data' => $this->transformProjectTimeData($reportData),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching project time data: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch project time data.',
            ], 500);
        }
    }

    /**
     * API endpoint for user time per project data (for async loading).
     */
    public function userTimePerProjectData(Request $request)
    {
        $projectId = $request->input('project_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        try {
            $reportData = JiraWorklog::totalTimeByUserForProject($projectId, $startDate, $endDate)->get();

            return response()->json([
                'success' => true,
                'data' => $this->transformUserTimeData($reportData),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user time per project data: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user time data.',
            ], 500);
        }
    }

    /**
     * API endpoint for project trend data (for async loading).
     */
    public function projectTrendData(Request $request)
    {
        $projectIds = $request->input('project_ids', []);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $period = $request->input('period', 'weekly');

        // Ensure project_ids is an array
        if (is_string($projectIds)) {
            $projectIds = explode(',', $projectIds);
        }

        try {
            $reportData = JiraWorklog::projectTimeTrend($period, $projectIds, $startDate, $endDate)->get();

            return response()->json([
                'success' => true,
                'data' => $this->transformTrendData($reportData, $period),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching project trend data: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch project trend data.',
            ], 500);
        }
    }

    /**
     * Transform project time data for frontend consumption.
     */
    private function transformProjectTimeData($reportData)
    {
        return [
            'chart' => [
                'labels' => $reportData->pluck('project_key')->toArray(),
                'datasets' => [
                    [
                        'label' => 'Time Spent (Hours)',
                        'data' => $reportData->map(function ($item) {
                            return round($item->total_time_seconds / 3600, 2); // Convert to hours
                        })->toArray(),
                        'backgroundColor' => [
                            '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
                            '#06B6D4', '#F97316', '#84CC16', '#EC4899', '#6B7280',
                        ],
                    ],
                ],
            ],
            'table' => $reportData->map(function ($item) {
                return [
                    'project_key' => $item->project_key,
                    'project_name' => $item->project_name,
                    'total_time_seconds' => $item->total_time_seconds,
                    'total_time_formatted' => JiraWorklog::formatSeconds($item->total_time_seconds),
                    'worklog_count' => $item->worklog_count,
                ];
            })->toArray(),
            'summary' => [
                'total_projects' => $reportData->count(),
                'total_time_seconds' => $reportData->sum('total_time_seconds'),
                'total_time_formatted' => JiraWorklog::formatSeconds($reportData->sum('total_time_seconds')),
                'total_worklogs' => $reportData->sum('worklog_count'),
            ],
        ];
    }

    /**
     * Transform user time data for frontend consumption.
     */
    private function transformUserTimeData($reportData)
    {
        return [
            'chart' => [
                'labels' => $reportData->pluck('user_name')->toArray(),
                'datasets' => [
                    [
                        'label' => 'Time Spent (Hours)',
                        'data' => $reportData->map(function ($item) {
                            return round($item->total_time_seconds / 3600, 2); // Convert to hours
                        })->toArray(),
                        'backgroundColor' => [
                            '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
                            '#06B6D4', '#F97316', '#84CC16', '#EC4899', '#6B7280',
                        ],
                    ],
                ],
            ],
            'table' => $reportData->map(function ($item) {
                return [
                    'user_name' => $item->user_name,
                    'email_address' => $item->email_address,
                    'project_key' => $item->project_key,
                    'project_name' => $item->project_name,
                    'total_time_seconds' => $item->total_time_seconds,
                    'total_time_formatted' => JiraWorklog::formatSeconds($item->total_time_seconds),
                    'worklog_count' => $item->worklog_count,
                ];
            })->toArray(),
            'summary' => [
                'total_users' => $reportData->count(),
                'total_time_seconds' => $reportData->sum('total_time_seconds'),
                'total_time_formatted' => JiraWorklog::formatSeconds($reportData->sum('total_time_seconds')),
                'total_worklogs' => $reportData->sum('worklog_count'),
            ],
        ];
    }

    /**
     * Transform trend data for frontend consumption.
     */
    private function transformTrendData($reportData, $period)
    {
        // Group data by project
        $groupedData = $reportData->groupBy('project_key');

        // Get all unique periods for the x-axis
        $allPeriods = $reportData->pluck('period')->unique()->sort()->values();

        // Prepare datasets for each project
        $datasets = [];
        $colors = ['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#06B6D4', '#F97316', '#84CC16', '#EC4899', '#6B7280'];
        $colorIndex = 0;

        foreach ($groupedData as $projectKey => $projectData) {
            $data = [];

            // Fill data for each period (fill missing periods with 0)
            foreach ($allPeriods as $period) {
                $periodData = $projectData->where('period', $period)->first();
                $data[] = $periodData ? round($periodData->total_time_seconds / 3600, 2) : 0;
            }

            $datasets[] = [
                'label' => $projectKey,
                'data' => $data,
                'borderColor' => $colors[$colorIndex % count($colors)],
                'backgroundColor' => $colors[$colorIndex % count($colors)].'20', // Add transparency
                'fill' => false,
            ];

            $colorIndex++;
        }

        return [
            'chart' => [
                'labels' => $allPeriods->toArray(),
                'datasets' => $datasets,
            ],
            'table' => $reportData->map(function ($item) {
                return [
                    'project_key' => $item->project_key,
                    'project_name' => $item->project_name,
                    'period' => $item->period,
                    'total_time_seconds' => $item->total_time_seconds,
                    'total_time_formatted' => JiraWorklog::formatSeconds($item->total_time_seconds),
                    'worklog_count' => $item->worklog_count,
                ];
            })->toArray(),
            'summary' => [
                'total_periods' => $allPeriods->count(),
                'total_projects' => $groupedData->count(),
                'total_time_seconds' => $reportData->sum('total_time_seconds'),
                'total_time_formatted' => JiraWorklog::formatSeconds($reportData->sum('total_time_seconds')),
                'total_worklogs' => $reportData->sum('worklog_count'),
            ],
        ];
    }
}
