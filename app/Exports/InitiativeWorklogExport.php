<?php

namespace App\Exports;

use App\Models\Initiative;
use App\Services\JiraInitiativeService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InitiativeWorklogExport implements WithMultipleSheets
{
    protected Initiative $initiative;
    protected JiraInitiativeService $initiativeService;
    protected ?string $startDate;
    protected ?string $endDate;
    protected bool $showCosts;

    public function __construct(
        Initiative $initiative, 
        JiraInitiativeService $initiativeService,
        ?string $startDate = null, 
        ?string $endDate = null,
        bool $showCosts = true
    ) {
        $this->initiative = $initiative;
        $this->initiativeService = $initiativeService;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->showCosts = $showCosts;
    }

    public function sheets(): array
    {
        return [
            'Summary' => new InitiativeSummarySheet($this->initiative, $this->initiativeService, $this->startDate, $this->endDate, $this->showCosts),
            'Monthly Breakdown' => new InitiativeMonthlySheet($this->initiative, $this->initiativeService, $this->startDate, $this->endDate, $this->showCosts),
            'Contributing Issues' => new InitiativeIssuesSheet($this->initiative, $this->initiativeService, $this->startDate, $this->endDate, $this->showCosts),
        ];
    }
}

class InitiativeSummarySheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles
{
    protected Initiative $initiative;
    protected JiraInitiativeService $initiativeService;
    protected ?string $startDate;
    protected ?string $endDate;
    protected bool $showCosts;

    public function __construct(
        Initiative $initiative, 
        JiraInitiativeService $initiativeService,
        ?string $startDate = null, 
        ?string $endDate = null,
        bool $showCosts = true
    ) {
        $this->initiative = $initiative;
        $this->initiativeService = $initiativeService;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->showCosts = $showCosts;
    }

    public function collection()
    {
        $metrics = $this->initiativeService->getMetricsSummary($this->initiative, $this->startDate, $this->endDate);
        
        return collect([
            [
                'initiative' => $this->initiative->name,
                'description' => $this->initiative->description,
                'hourly_rate' => $this->showCosts ? $this->initiative->hourly_rate : 'Hidden',
                'total_hours' => $metrics['total_hours'],
                'total_cost' => $this->showCosts ? $metrics['total_cost'] : 'Hidden',
                'total_issues' => $metrics['total_issues'],
                'period_from' => $this->startDate ?: 'All time',
                'period_to' => $this->endDate ?: 'Present',
                'project_filters' => $this->initiative->projectFilters->map(function ($filter) {
                    $parts = [$filter->jiraProject->name];
                    if ($filter->required_labels) {
                        $parts[] = 'Labels: ' . implode(', ', $filter->required_labels);
                    }
                    if ($filter->epic_key) {
                        $parts[] = 'Epic: ' . $filter->epic_key;
                    }
                    return implode(' | ', $parts);
                })->implode('; '),
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    public function headings(): array
    {
        return [
            'Initiative Name',
            'Description',
            'Hourly Rate',
            'Total Hours',
            'Total Cost',
            'Total Issues',
            'Period From',
            'Period To',
            'Project Filters',
            'Generated At',
        ];
    }

    public function map($row): array
    {
        return [
            $row['initiative'],
            $row['description'],
            $row['hourly_rate'],
            $row['total_hours'],
            $row['total_cost'],
            $row['total_issues'],
            $row['period_from'],
            $row['period_to'],
            $row['project_filters'],
            $row['generated_at'],
        ];
    }

    public function title(): string
    {
        return 'Summary';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

class InitiativeMonthlySheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles
{
    protected Initiative $initiative;
    protected JiraInitiativeService $initiativeService;
    protected ?string $startDate;
    protected ?string $endDate;
    protected bool $showCosts;

    public function __construct(
        Initiative $initiative, 
        JiraInitiativeService $initiativeService,
        ?string $startDate = null, 
        ?string $endDate = null,
        bool $showCosts = true
    ) {
        $this->initiative = $initiative;
        $this->initiativeService = $initiativeService;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->showCosts = $showCosts;
    }

    public function collection()
    {
        $monthlyBreakdown = $this->initiativeService->getMonthlyBreakdown($this->initiative, $this->startDate, $this->endDate);
        
        return collect($monthlyBreakdown)->map(function ($data, $month) {
            return [
                'month' => $month,
                'year' => $data['year'],
                'month_name' => date('F', mktime(0, 0, 0, $data['month'], 1)),
                'hours' => $data['hours'],
                'cost' => $this->showCosts ? $data['cost'] : 'Hidden',
                'worklog_count' => $data['worklog_count'],
            ];
        })->sortBy([['year', 'asc'], ['month', 'asc']])->values();
    }

    public function headings(): array
    {
        return [
            'Period',
            'Year',
            'Month',
            'Hours',
            'Cost',
            'Worklog Entries',
        ];
    }

    public function map($row): array
    {
        return [
            $row['month'],
            $row['year'],
            $row['month_name'],
            $row['hours'],
            $row['cost'],
            $row['worklog_count'],
        ];
    }

    public function title(): string
    {
        return 'Monthly Breakdown';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

class InitiativeIssuesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles
{
    protected Initiative $initiative;
    protected JiraInitiativeService $initiativeService;
    protected ?string $startDate;
    protected ?string $endDate;
    protected bool $showCosts;

    public function __construct(
        Initiative $initiative, 
        JiraInitiativeService $initiativeService,
        ?string $startDate = null, 
        ?string $endDate = null,
        bool $showCosts = true
    ) {
        $this->initiative = $initiative;
        $this->initiativeService = $initiativeService;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->showCosts = $showCosts;
    }

    public function collection()
    {
        return $this->initiativeService->getContributingIssues($this->initiative, $this->startDate, $this->endDate);
    }

    public function headings(): array
    {
        return [
            'Issue Key',
            'Summary',
            'Project',
            'Status',
            'Epic',
            'Labels',
            'Hours',
            'Cost',
            'Worklog Entries',
            'First Worklog',
            'Last Worklog',
        ];
    }

    public function map($issue): array
    {
        return [
            $issue['issue_key'],
            $issue['summary'],
            $issue['project_name'],
            $issue['status'],
            $issue['epic_key'] ?: 'None',
            is_array($issue['labels']) ? implode(', ', $issue['labels']) : '',
            $issue['hours'],
            $this->showCosts ? $issue['cost'] : 'Hidden',
            $issue['worklog_count'],
            $issue['first_worklog'],
            $issue['last_worklog'],
        ];
    }

    public function title(): string
    {
        return 'Contributing Issues';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}