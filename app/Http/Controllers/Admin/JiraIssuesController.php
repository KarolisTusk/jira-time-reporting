<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JiraIssue;
use App\Models\JiraProject;
use App\Models\JiraAppUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class JiraIssuesController extends Controller
{
    /**
     * Display the JIRA issues table page.
     */
    public function index(): Response
    {
        $projects = JiraProject::orderBy('project_key')->get(['id', 'project_key', 'name']);
        $users = JiraAppUser::orderBy('display_name')->get(['id', 'display_name', 'email_address']);
        
        return Inertia::render('admin/JiraIssues', [
            'projects' => $projects,
            'users' => $users,
            'stats' => $this->getIssuesStats(),
        ]);
    }

    /**
     * Get paginated and filtered issues data.
     */
    public function getData(Request $request): JsonResponse
    {
        $query = JiraIssue::with(['project', 'assignee', 'worklogs.author'])
            ->select([
                'id',
                'jira_id',
                'issue_key',
                'summary',
                'status',
                'jira_project_id',
                'assignee_jira_app_user_id',
                'original_estimate_seconds',
                'created_at',
                'updated_at'
            ]);

        // Apply filters
        if ($request->filled('project_key')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('project_key', $request->project_key);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('assignee_id')) {
            $query->where('assignee_jira_app_user_id', $request->assignee_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('issue_key', 'ilike', "%{$search}%")
                  ->orWhere('summary', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('has_worklogs')) {
            if ($request->boolean('has_worklogs')) {
                $query->has('worklogs');
            } else {
                $query->doesntHave('worklogs');
            }
        }

        if ($request->filled('date_from')) {
            $query->where('updated_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('updated_at', '<=', $request->date_to);
        }

        // Apply sorting
        $sortField = $request->get('sort_field', 'updated_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        
        // Handle relationship sorting
        if ($sortField === 'project_key') {
            $query->join('jira_projects', 'jira_issues.jira_project_id', '=', 'jira_projects.id')
                  ->orderBy('jira_projects.project_key', $sortDirection)
                  ->select('jira_issues.*');
        } elseif ($sortField === 'assignee_name') {
            $query->leftJoin('jira_app_users', 'jira_issues.assignee_jira_app_user_id', '=', 'jira_app_users.id')
                  ->orderBy('jira_app_users.display_name', $sortDirection)
                  ->select('jira_issues.*');
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        // Get paginated results
        $perPage = min($request->get('per_page', 25), 100); // Max 100 items per page
        $issues = $query->paginate($perPage);

        // Transform the data to include computed fields
        $issues->getCollection()->transform(function ($issue) {
            return [
                'id' => $issue->id,
                'jira_id' => $issue->jira_id,
                'issue_key' => $issue->issue_key,
                'summary' => $issue->summary,
                'status' => $issue->status,
                'project' => [
                    'key' => $issue->project->project_key,
                    'name' => $issue->project->name,
                ],
                'assignee' => $issue->assignee ? [
                    'id' => $issue->assignee->id,
                    'display_name' => $issue->assignee->display_name,
                    'email_address' => $issue->assignee->email_address,
                ] : null,
                'original_estimate_hours' => $issue->original_estimate_seconds ? round($issue->original_estimate_seconds / 3600, 2) : null,
                'worklogs_count' => $issue->worklogs->count(),
                'total_logged_hours' => round($issue->worklogs->sum('time_spent_seconds') / 3600, 2),
                'worklog_authors' => $issue->worklogs->pluck('author.display_name')->unique()->values(),
                'created_at' => $issue->created_at,
                'updated_at' => $issue->updated_at,
                'last_sync' => $issue->updated_at->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $issues,
            'stats' => $this->getFilteredStats($request),
        ]);
    }

    /**
     * Get detailed issue information.
     */
    public function show(string $issueKey): JsonResponse
    {
        $issue = JiraIssue::with([
            'project',
            'assignee',
            'worklogs' => function ($query) {
                $query->with('author')->orderBy('started_at', 'desc');
            }
        ])->where('issue_key', $issueKey)->first();

        if (!$issue) {
            return response()->json([
                'success' => false,
                'message' => 'Issue not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $issue->id,
                'jira_id' => $issue->jira_id,
                'issue_key' => $issue->issue_key,
                'summary' => $issue->summary,
                'status' => $issue->status,
                'project' => [
                    'key' => $issue->project->project_key,
                    'name' => $issue->project->name,
                ],
                'assignee' => $issue->assignee ? [
                    'display_name' => $issue->assignee->display_name,
                    'email_address' => $issue->assignee->email_address,
                ] : null,
                'original_estimate_hours' => $issue->original_estimate_seconds ? round($issue->original_estimate_seconds / 3600, 2) : null,
                'worklogs' => $issue->worklogs->map(function ($worklog) {
                    return [
                        'id' => $worklog->id,
                        'jira_id' => $worklog->jira_id,
                        'time_spent_hours' => round($worklog->time_spent_seconds / 3600, 2),
                        'started_at' => $worklog->started_at,
                        'resource_type' => $worklog->resource_type,
                        'author' => [
                            'display_name' => $worklog->author->display_name,
                            'email_address' => $worklog->author->email_address,
                        ],
                    ];
                }),
                'total_logged_hours' => round($issue->worklogs->sum('time_spent_seconds') / 3600, 2),
                'created_at' => $issue->created_at,
                'updated_at' => $issue->updated_at,
            ],
        ]);
    }

    /**
     * Export issues data as CSV.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = JiraIssue::with(['project', 'assignee', 'worklogs.author']);

        // Apply same filters as getData method
        $this->applyFilters($query, $request);

        $filename = 'jira_issues_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($handle, [
                'Issue Key',
                'Summary',
                'Status',
                'Project Key',
                'Project Name',
                'Assignee',
                'Assignee Email',
                'Original Estimate (Hours)',
                'Worklogs Count',
                'Total Logged Hours',
                'Worklog Authors',
                'Created At',
                'Updated At',
            ]);

            // Stream data in chunks
            $query->chunk(1000, function ($issues) use ($handle) {
                foreach ($issues as $issue) {
                    fputcsv($handle, [
                        $issue->issue_key,
                        $issue->summary,
                        $issue->status,
                        $issue->project->project_key,
                        $issue->project->name,
                        $issue->assignee?->display_name,
                        $issue->assignee?->email_address,
                        $issue->original_estimate_seconds ? round($issue->original_estimate_seconds / 3600, 2) : null,
                        $issue->worklogs->count(),
                        round($issue->worklogs->sum('time_spent_seconds') / 3600, 2),
                        $issue->worklogs->pluck('author.display_name')->unique()->implode(', '),
                        $issue->created_at->toISOString(),
                        $issue->updated_at->toISOString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Get overall issues statistics.
     */
    private function getIssuesStats(): array
    {
        return [
            'total_issues' => JiraIssue::count(),
            'issues_with_worklogs' => JiraIssue::has('worklogs')->count(),
            'total_projects' => JiraProject::count(),
            'total_users' => JiraAppUser::count(),
            'total_logged_hours' => round(
                \DB::table('jira_worklogs')->sum('time_spent_seconds') / 3600, 2
            ),
            'last_sync' => JiraIssue::max('updated_at'),
        ];
    }

    /**
     * Get filtered statistics based on current filters.
     */
    private function getFilteredStats(Request $request): array
    {
        $query = JiraIssue::with('worklogs');
        $this->applyFilters($query, $request);

        $issues = $query->get();
        
        return [
            'filtered_count' => $issues->count(),
            'filtered_with_worklogs' => $issues->filter(fn($issue) => $issue->worklogs->count() > 0)->count(),
            'filtered_logged_hours' => round($issues->sum(fn($issue) => $issue->worklogs->sum('time_spent_seconds')) / 3600, 2),
        ];
    }

    /**
     * Apply filters to the query.
     */
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('project_key')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('project_key', $request->project_key);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('assignee_id')) {
            $query->where('assignee_jira_app_user_id', $request->assignee_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('issue_key', 'ilike', "%{$search}%")
                  ->orWhere('summary', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('has_worklogs')) {
            if ($request->boolean('has_worklogs')) {
                $query->has('worklogs');
            } else {
                $query->doesntHave('worklogs');
            }
        }

        if ($request->filled('date_from')) {
            $query->where('updated_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('updated_at', '<=', $request->date_to);
        }
    }
} 