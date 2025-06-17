<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInitiativeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Add proper authorization logic
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $initiativeId = $this->route('initiative')->id ?? null;
        
        return [
            'name' => "required|string|max:255|unique:initiatives,name,{$initiativeId}",
            'description' => 'nullable|string|max:1000',
            'hourly_rate' => 'nullable|numeric|min:0|max:9999.99',
            'is_active' => 'boolean',
            'project_filters' => 'required|array|min:1',
            'project_filters.*.jira_project_id' => 'required|exists:jira_projects,id',
            'project_filters.*.required_labels' => 'nullable|array',
            'project_filters.*.required_labels.*' => 'string|max:100',
            'project_filters.*.epic_key' => 'nullable|string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Initiative name is required.',
            'name.unique' => 'An initiative with this name already exists.',
            'project_filters.required' => 'At least one project filter is required.',
            'project_filters.min' => 'At least one project filter is required.',
            'project_filters.*.jira_project_id.required' => 'Project is required for each filter.',
            'project_filters.*.jira_project_id.exists' => 'Selected project does not exist.',
            'hourly_rate.min' => 'Hourly rate must be a positive number.',
            'hourly_rate.max' => 'Hourly rate cannot exceed 9999.99.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'project_filters.*.jira_project_id' => 'project',
            'project_filters.*.required_labels' => 'labels',
            'project_filters.*.epic_key' => 'epic key',
        ];
    }
}
