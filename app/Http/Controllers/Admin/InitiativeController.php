<?php

namespace App\Http\Controllers\Admin;

use App\Exports\InitiativeWorklogExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInitiativeRequest;
use App\Http\Requests\UpdateInitiativeRequest;
use App\Models\Initiative;
use App\Models\JiraProject;
use App\Services\JiraInitiativeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class InitiativeController extends Controller
{
    protected JiraInitiativeService $initiativeService;

    public function __construct(JiraInitiativeService $initiativeService)
    {
        $this->initiativeService = $initiativeService;
    }

    /**
     * Display a listing of the initiatives.
     */
    public function index(Request $request)
    {
        \Log::info('Admin Initiatives Index accessed');
        
        $query = Initiative::with(['projectFilters.jiraProject', 'users'])
            ->withCount('users');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->active();
            } elseif ($request->input('status') === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $initiatives = $query->paginate(15)->withQueryString();

        return Inertia::render('Admin/Initiatives/Index', [
            'initiatives' => $initiatives,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new initiative.
     */
    public function create()
    {
        $projects = JiraProject::orderBy('name')->get();

        return Inertia::render('Admin/Initiatives/Create', [
            'projects' => $projects,
        ]);
    }

    /**
     * Store a newly created initiative.
     */
    public function store(CreateInitiativeRequest $request)
    {
        $initiative = Initiative::create($request->validated());

        // Create project filters
        if ($request->has('project_filters')) {
            foreach ($request->input('project_filters') as $filter) {
                $initiative->projectFilters()->create([
                    'jira_project_id' => $filter['jira_project_id'],
                    'required_labels' => $filter['required_labels'] ?? null,
                    'epic_key' => $filter['epic_key'] ?? null,
                ]);
            }
        }

        return redirect()->route('admin.initiatives.index')
            ->with('success', 'Initiative created successfully.');
    }

    /**
     * Display the specified initiative.
     */
    public function show(Initiative $initiative)
    {
        $initiative->load(['projectFilters.jiraProject', 'users']);
        
        // Get initiative metrics
        $metrics = $this->initiativeService->getMetricsSummary($initiative);
        
        // Get recent contributing issues
        $contributingIssues = $this->initiativeService->getContributingIssues($initiative)
            ->take(10);

        return Inertia::render('Admin/Initiatives/Show', [
            'initiative' => $initiative,
            'metrics' => $metrics,
            'contributingIssues' => $contributingIssues,
        ]);
    }

    /**
     * Show the form for editing the specified initiative.
     */
    public function edit(Initiative $initiative)
    {
        $initiative->load('projectFilters');
        $projects = JiraProject::orderBy('name')->get();

        return Inertia::render('Admin/Initiatives/Edit', [
            'initiative' => $initiative,
            'projects' => $projects,
        ]);
    }

    /**
     * Update the specified initiative.
     */
    public function update(UpdateInitiativeRequest $request, Initiative $initiative)
    {
        $initiative->update($request->validated());

        // Update project filters
        if ($request->has('project_filters')) {
            // Delete existing filters
            $initiative->projectFilters()->delete();
            
            // Create new filters
            foreach ($request->input('project_filters') as $filter) {
                $initiative->projectFilters()->create([
                    'jira_project_id' => $filter['jira_project_id'],
                    'required_labels' => $filter['required_labels'] ?? null,
                    'epic_key' => $filter['epic_key'] ?? null,
                ]);
            }
        }

        return redirect()->route('admin.initiatives.index')
            ->with('success', 'Initiative updated successfully.');
    }

    /**
     * Remove the specified initiative.
     */
    public function destroy(Initiative $initiative)
    {
        $initiative->delete();

        return redirect()->route('admin.initiatives.index')
            ->with('success', 'Initiative deleted successfully.');
    }

    /**
     * Toggle initiative status.
     */
    public function toggleStatus(Initiative $initiative)
    {
        $initiative->update([
            'is_active' => !$initiative->is_active
        ]);

        $status = $initiative->is_active ? 'activated' : 'deactivated';
        
        return redirect()->back()
            ->with('success', "Initiative {$status} successfully.");
    }

    /**
     * Get initiative metrics data for AJAX requests.
     */
    public function metrics(Initiative $initiative, Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        $metrics = $this->initiativeService->getMetricsSummary($initiative, $startDate, $endDate);
        
        return response()->json($metrics);
    }

    /**
     * Export initiative data for admin users.
     */
    public function export(Request $request, Initiative $initiative)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        
        // Admins can always see costs
        $showCosts = true;

        $fileName = sprintf(
            '%s_initiative_admin_report_%s.xlsx',
            str_replace(' ', '_', strtolower($initiative->name)),
            now()->format('Y-m-d')
        );

        return Excel::download(
            new InitiativeWorklogExport($initiative, $this->initiativeService, $startDate, $endDate, $showCosts),
            $fileName
        );
    }
}
