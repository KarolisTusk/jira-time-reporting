<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { BarChart3, TrendingUp, Users, Settings, RefreshCw, Clock } from 'lucide-vue-next';

interface DashboardStats {
    totalProjects: number;
    totalTime: string;
    totalTimeHours: number;
    activeUsers: number;
    lastSync: string;
    totalWorklogs: number;
    totalIssues: number;
}

interface LastSyncDetails {
    id: number;
    started_at: string;
    completed_at: string;
    duration: string;
    total_projects_processed: number;
    total_issues_processed: number;
    total_worklogs_processed: number;
}

interface Props {
    stats: DashboardStats;
    lastSyncDetails?: LastSyncDetails | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6">
            <!-- Welcome Section -->
            <div>
                <h1 class="text-3xl font-bold tracking-tight">JIRA Time Reporting Dashboard</h1>
                <p class="text-muted-foreground mt-2">
                    Track and analyze time spent on JIRA projects and issues
                </p>
            </div>

            <!-- Quick Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Total Projects</CardTitle>
                        <BarChart3 class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ props.stats.totalProjects }}</div>
                        <p class="text-xs text-muted-foreground">Tracked projects</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Total Time Logged</CardTitle>
                        <Clock class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ props.stats.totalTime }}</div>
                        <p class="text-xs text-muted-foreground">Across all projects</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Active Users</CardTitle>
                        <Users class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ props.stats.activeUsers }}</div>
                        <p class="text-xs text-muted-foreground">Logging time</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Last Sync</CardTitle>
                        <RefreshCw class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ props.stats.lastSync }}</div>
                        <p class="text-xs text-muted-foreground">From JIRA</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Additional Stats (if we have real data) -->
            <div v-if="props.stats.totalProjects > 0" class="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <BarChart3 class="h-5 w-5" />
                            Data Summary
                        </CardTitle>
                        <CardDescription>
                            Overview of imported JIRA data
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Total Issues:</span>
                            <span class="font-medium">{{ props.stats.totalIssues }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Total Worklogs:</span>
                            <span class="font-medium">{{ props.stats.totalWorklogs }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Total Time:</span>
                            <span class="font-medium">{{ props.stats.totalTimeHours.toLocaleString() }} hours</span>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="props.lastSyncDetails">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <RefreshCw class="h-5 w-5" />
                            Last Sync Details
                        </CardTitle>
                        <CardDescription>
                            Latest successful synchronization
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Duration:</span>
                            <span class="font-medium">{{ props.lastSyncDetails.duration }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Issues Processed:</span>
                            <span class="font-medium">{{ props.lastSyncDetails.total_issues_processed }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Worklogs Processed:</span>
                            <span class="font-medium">{{ props.lastSyncDetails.total_worklogs_processed }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-muted-foreground">Completed:</span>
                            <span class="font-medium">{{ props.lastSyncDetails.completed_at }}</span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Quick Actions -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <BarChart3 class="h-5 w-5" />
                            Project Time Report
                        </CardTitle>
                        <CardDescription>
                            View total time spent on each project
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Link :href="route('reports.project-time')">
                            <Button class="w-full">View Report</Button>
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Users class="h-5 w-5" />
                            User Time Report
                        </CardTitle>
                        <CardDescription>
                            Analyze time spent by users on projects
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Link :href="route('reports.user-time-per-project')">
                            <Button class="w-full">View Report</Button>
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <TrendingUp class="h-5 w-5" />
                            Project Trends
                        </CardTitle>
                        <CardDescription>
                            Track project time trends over periods
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Link :href="route('reports.project-trend')">
                            <Button class="w-full">View Report</Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>

            <!-- Setup Section -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Settings class="h-5 w-5" />
                        Getting Started
                    </CardTitle>
                    <CardDescription>
                        Configure JIRA integration to start tracking time
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-medium">1. Configure JIRA Settings</h4>
                            <p class="text-sm text-muted-foreground">Add your JIRA host, API token, and project keys</p>
                        </div>
                        <Link :href="route('settings.jira.show')">
                            <Button variant="outline">Configure</Button>
                        </Link>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-medium">2. Sync JIRA Data</h4>
                            <p class="text-sm text-muted-foreground">Import projects, issues, and worklogs from JIRA</p>
                        </div>
                        <Link :href="route('settings.jira.show')">
                            <Button variant="outline">
                                <RefreshCw class="h-4 w-4 mr-2" />
                                Sync Now
                            </Button>
                        </Link>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-medium">3. View Reports</h4>
                            <p class="text-sm text-muted-foreground">Analyze time tracking data with interactive charts</p>
                        </div>
                        <Link :href="route('reports.project-time')">
                            <Button variant="outline">View Reports</Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
