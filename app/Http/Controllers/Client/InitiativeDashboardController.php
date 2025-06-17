<?php

namespace App\Http\Controllers\Client;

use App\Exports\InitiativeWorklogExport;
use App\Http\Controllers\Controller;
use App\Models\Initiative;
use App\Services\JiraInitiativeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class InitiativeDashboardController extends Controller
{
    protected JiraInitiativeService $initiativeService;

    public function __construct(JiraInitiativeService $initiativeService)
    {
        $this->initiativeService = $initiativeService;
    }

    /**
     * Display the client initiative dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get initiatives accessible by this user
        $initiatives = $this->initiativeService->getAccessibleInitiatives($user);
        
        // Get metrics for each initiative
        $initiativesWithMetrics = $initiatives->map(function ($initiative) {
            $metrics = $this->initiativeService->getMetricsSummary($initiative);
            return [
                'id' => $initiative->id,
                'name' => $initiative->name,
                'description' => $initiative->description,
                'hourly_rate' => $initiative->hourly_rate,
                'is_active' => $initiative->is_active,
                'project_filters' => $initiative->projectFilters->map(function ($filter) {
                    return [
                        'jira_project' => [
                            'name' => $filter->jiraProject->name,
                            'project_key' => $filter->jiraProject->project_key,
                        ],
                        'required_labels' => $filter->required_labels,
                        'epic_key' => $filter->epic_key,
                        'description' => $filter->description,
                    ];
                }),
                'metrics' => $metrics,
                'access_type' => $user->initiatives()
                    ->where('initiatives.id', $initiative->id)
                    ->first()?->pivot?->access_type ?? 'read',
            ];
        });

        return Inertia::render('Client/InitiativeDashboard', [
            'initiatives' => $initiativesWithMetrics,
        ]);
    }

    /**
     * Display a specific initiative for the client.
     */
    public function show(Request $request, Initiative $initiative)
    {
        $user = $request->user();
        
        // Check if user has access to this initiative
        if (!$user->hasInitiativeAccess($initiative)) {
            abort(403, 'You do not have access to this initiative.');
        }

        $initiative->load(['projectFilters.jiraProject']);
        
        // Get initiative metrics
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        $metrics = $this->initiativeService->getMetricsSummary($initiative, $startDate, $endDate);
        
        // Get contributing issues
        $contributingIssues = $this->initiativeService->getContributingIssues($initiative, $startDate, $endDate)
            ->take(20); // Show more issues for clients

        return Inertia::render('Client/InitiativeDetail', [
            'initiative' => [
                'id' => $initiative->id,
                'name' => $initiative->name,
                'description' => $initiative->description,
                'hourly_rate' => $initiative->hourly_rate,
                'is_active' => $initiative->is_active,
                'project_filters' => $initiative->projectFilters->map(function ($filter) {
                    return [
                        'jira_project' => [
                            'name' => $filter->jiraProject->name,
                            'project_key' => $filter->jiraProject->project_key,
                        ],
                        'required_labels' => $filter->required_labels,
                        'epic_key' => $filter->epic_key,
                        'description' => $filter->description,
                    ];
                }),
            ],
            'metrics' => $metrics,
            'contributingIssues' => $contributingIssues,
            'access_type' => $user->initiatives()
                ->where('initiatives.id', $initiative->id)
                ->first()?->pivot?->access_type ?? 'read',
            'filters' => $request->only(['start_date', 'end_date']),
        ]);
    }

    /**
     * Get initiative metrics data for AJAX requests.
     */
    public function metrics(Request $request, Initiative $initiative)
    {
        $user = $request->user();
        
        // Check if user has access to this initiative
        if (!$user->hasInitiativeAccess($initiative)) {
            abort(403, 'You do not have access to this initiative.');
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        $metrics = $this->initiativeService->getMetricsSummary($initiative, $startDate, $endDate);
        
        return response()->json($metrics);
    }

    /**
     * Export initiative data.
     */
    public function export(Request $request, Initiative $initiative)
    {
        $user = $request->user();
        
        // Check if user has access to this initiative
        if (!$user->hasInitiativeAccess($initiative)) {
            abort(403, 'You do not have access to this initiative.');
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        // Determine if user should see costs
        $userAccess = $user->initiatives()
            ->where('initiatives.id', $initiative->id)
            ->first()?->pivot;
        $showCosts = $userAccess?->access_type === 'admin' || $initiative->hourly_rate !== null;

        $fileName = sprintf(
            '%s_initiative_report_%s.xlsx',
            str_replace(' ', '_', strtolower($initiative->name)),
            now()->format('Y-m-d')
        );

        return Excel::download(
            new InitiativeWorklogExport($initiative, $this->initiativeService, $startDate, $endDate, $showCosts),
            $fileName
        );
    }
}
