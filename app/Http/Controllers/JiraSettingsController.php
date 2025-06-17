<?php

namespace App\Http\Controllers;

use App\Models\JiraSetting;
use App\Services\JiraApiService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class JiraSettingsController extends Controller
{
    protected JiraApiService $jiraApiService;

    public function __construct(JiraApiService $jiraApiService)
    {
        $this->jiraApiService = $jiraApiService;
    }

    /**
     * Display the JIRA settings page.
     */
    public function show()
    {
        $settings = JiraSetting::first() ?? new JiraSetting;
        // Ensure project_keys is an array, even if null in DB, for consistent frontend handling.
        $projectKeys = $settings->project_keys ?? [];

        // Try to fetch available projects if settings exist
        $availableProjects = [];
        if ($settings->jira_host && $settings->api_token) {
            try {
                $projects = $this->jiraApiService->getAllProjects();
                $availableProjects = array_map(function ($project) {
                    // Handle both object and array formats
                    if (is_array($project)) {
                        return [
                            'key' => $project['key'] ?? '',
                            'name' => $project['name'] ?? '',
                            'id' => $project['id'] ?? '',
                        ];
                    } else {
                        return [
                            'key' => $project->key ?? '',
                            'name' => $project->name ?? '',
                            'id' => $project->id ?? '',
                        ];
                    }
                }, $projects);
            } catch (Exception $e) {
                // Log error but don't fail the page load
                \Log::warning('Failed to fetch JIRA projects: '.$e->getMessage());
            }
        }

        return Inertia::render('settings/Jira', [
            'jiraSettings' => [
                'jira_host' => $settings->jira_host,
                'jira_email' => $settings->jira_email,
                // Do not pass the api_token to the frontend directly for security.
                // We'll only indicate if it's set or not, or handle updates separately.
                'is_api_token_set' => ! empty($settings->api_token),
                'project_keys' => $projectKeys, // Send as array for checkbox selection
            ],
            'availableProjects' => $availableProjects,
            'status' => session('status'),
        ]);
    }

    /**
     * Store the JIRA settings.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'jira_host' => 'required|string|url:http,https',
            'jira_email' => 'required|email',
            'api_token' => 'nullable|string|min:20', // Token is optional if already set and not being changed
            'project_keys' => 'nullable|array', // Now expecting an array
            'project_keys.*' => 'string', // Each project key should be a string
        ]);

        try {
            $settings = JiraSetting::firstOrNew([]);
            $settings->jira_host = rtrim($validated['jira_host'], '/');
            $settings->jira_email = $validated['jira_email'];

            if (! empty($validated['api_token'])) {
                $settings->api_token = $validated['api_token'];
            }

            $settings->project_keys = $validated['project_keys'] ?? [];
            $settings->save();

            // Test connection after saving
            $connectionResult = $this->jiraApiService->testConnection();
            if (! $connectionResult['success']) {
                return Redirect::route('settings.jira.show')
                    ->with('status', 'error')
                    ->with('message', 'Settings saved, but JIRA connection test failed: '.$connectionResult['message']);
            }

            return Redirect::route('settings.jira.show')
                ->with('status', 'success')
                ->with('message', 'JIRA settings saved successfully. Connection test successful.');

        } catch (Exception $e) {
            return Redirect::route('settings.jira.show')
                ->with('status', 'error')
                ->with('message', 'Failed to save JIRA settings: '.$e->getMessage());
        }
    }

    /**
     * Test the JIRA connection.
     */
    public function testConnection(Request $request)
    {
        try {
            $result = $this->jiraApiService->testConnection();
            if ($result['success']) {
                return Redirect::route('settings.jira.show')
                    ->with('status', 'success')
                    ->with('message', $result['message']);
            }

            return Redirect::route('settings.jira.show')
                ->with('status', 'error')
                ->with('message', $result['message']);
        } catch (Exception $e) {
            return Redirect::route('settings.jira.show')
                ->with('status', 'error')
                ->with('message', 'JIRA connection test failed: '.$e->getMessage());
        }
    }

    /**
     * Fetch available JIRA projects (AJAX endpoint).
     */
    public function fetchProjects(Request $request)
    {
        try {
            $projects = $this->jiraApiService->getAllProjects();
            $availableProjects = array_map(function ($project) {
                // Handle both object and array formats
                if (is_array($project)) {
                    return [
                        'key' => $project['key'] ?? '',
                        'name' => $project['name'] ?? '',
                        'id' => $project['id'] ?? '',
                    ];
                } else {
                    return [
                        'key' => $project->key ?? '',
                        'name' => $project->name ?? '',
                        'id' => $project->id ?? '',
                    ];
                }
            }, $projects);

            return response()->json([
                'success' => true,
                'projects' => $availableProjects,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch projects: '.$e->getMessage(),
            ], 500);
        }
    }
}
