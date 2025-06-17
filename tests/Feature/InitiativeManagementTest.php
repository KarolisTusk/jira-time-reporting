<?php

use App\Models\Initiative;
use App\Models\InitiativeProjectFilter;
use App\Models\JiraProject;
use App\Models\User;

describe('Initiative Management', function () {
    
    test('admin can create an initiative', function () {
        $user = User::factory()->create();
        $project = JiraProject::factory()->create();
        
        $response = $this->actingAs($user)->post(route('admin.initiatives.store'), [
            'name' => 'Test Initiative',
            'description' => 'A test initiative',
            'hourly_rate' => 75.00,
            'is_active' => true,
            'project_filters' => [
                [
                    'jira_project_id' => $project->id,
                    'required_labels' => ['test', 'development'],
                    'epic_key' => 'TEST-123'
                ]
            ]
        ]);
        
        $response->assertRedirect(route('admin.initiatives.index'));
        
        $initiative = Initiative::where('name', 'Test Initiative')->first();
        expect($initiative)->not->toBeNull();
        expect($initiative->hourly_rate)->toBe('75.00');
        expect($initiative->projectFilters)->toHaveCount(1);
        expect($initiative->projectFilters->first()->required_labels)->toBe(['test', 'development']);
    });
    
    test('admin can update an initiative', function () {
        $user = User::factory()->create();
        $project = JiraProject::factory()->create();
        $initiative = Initiative::factory()->create(['name' => 'Original Name']);
        
        $response = $this->actingAs($user)->put(route('admin.initiatives.update', $initiative), [
            'name' => 'Updated Initiative',
            'description' => 'Updated description',
            'hourly_rate' => 80.00,
            'is_active' => false,
            'project_filters' => [
                [
                    'jira_project_id' => $project->id,
                    'required_labels' => ['updated'],
                    'epic_key' => null
                ]
            ]
        ]);
        
        $response->assertRedirect(route('admin.initiatives.index'));
        
        $initiative->refresh();
        expect($initiative->name)->toBe('Updated Initiative');
        expect($initiative->hourly_rate)->toBe('80.00');
        expect($initiative->is_active)->toBe(false);
    });
    
    test('admin can delete an initiative', function () {
        $user = User::factory()->create();
        $initiative = Initiative::factory()->create();
        
        $response = $this->actingAs($user)->delete(route('admin.initiatives.destroy', $initiative));
        
        $response->assertRedirect(route('admin.initiatives.index'));
        
        expect(Initiative::find($initiative->id))->toBeNull();
    });
    
    test('initiative creation requires valid data', function () {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->post(route('admin.initiatives.store'), [
            'name' => '', // Invalid: empty name
            'project_filters' => [] // Invalid: no filters
        ]);
        
        $response->assertSessionHasErrors(['name', 'project_filters']);
    });
    
    test('initiative project filter matches issues correctly', function () {
        $project = JiraProject::factory()->create();
        $initiative = Initiative::factory()->create();
        
        $filter = InitiativeProjectFilter::factory()->create([
            'initiative_id' => $initiative->id,
            'jira_project_id' => $project->id,
            'required_labels' => ['frontend', 'urgent'],
            'epic_key' => 'PROJ-456'
        ]);
        
        $matchingIssue = \App\Models\JiraIssue::factory()->create([
            'jira_project_id' => $project->id,
            'labels' => ['frontend', 'urgent', 'bug'],
            'epic_key' => 'PROJ-456'
        ]);
        
        $nonMatchingIssue = \App\Models\JiraIssue::factory()->create([
            'jira_project_id' => $project->id,
            'labels' => ['backend'], // Missing required labels
            'epic_key' => 'PROJ-456'
        ]);
        
        expect($filter->matchesIssue($matchingIssue))->toBe(true);
        expect($filter->matchesIssue($nonMatchingIssue))->toBe(false);
    });
});
