<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import JiraSyncProgress from '@/components/JiraSyncProgress.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { useJiraSyncProgress } from '@/composables/useJiraSyncProgress';
import { 
    DatabaseIcon, 
    RefreshCwIcon, 
    Settings2Icon, 
    CheckCircleIcon, 
    XCircleIcon,
    HistoryIcon,
    AlertTriangleIcon 
} from 'lucide-vue-next';

interface Props {
    jiraSettings: {
        jira_host?: string;
        jira_email?: string;
        is_api_token_set: boolean;
        project_keys: string[];
    };
    availableProjects?: Array<{
        key: string;
        name: string;
        id: string;
    }>;
    status?: string;
    activeSyncId?: number;
}

const props = defineProps<Props>();
const page = usePage();
const userId = computed(() => page.props.auth?.user?.id);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'JIRA settings',
        href: '/settings/jira',
    },
];

const form = useForm({
    jira_host: props.jiraSettings.jira_host || '',
    jira_email: props.jiraSettings.jira_email || '',
    api_token: '',
    project_keys: props.jiraSettings.project_keys || [],
});

// Create a separate reactive reference for selected projects
const selectedProjects = ref<string[]>(props.jiraSettings.project_keys || []);

const loadingProjects = ref(false);
const availableProjects = ref(props.availableProjects || []);
const showSyncProgress = ref(false);
const syncMessage = ref('');
const syncMessageType = ref<'success' | 'error' | 'info'>('info');

// Initialize sync progress composable
const {
    hasActiveSync,
    fetchSyncStatus,
    startListening,
    clearProgress,
} = useJiraSyncProgress(userId.value);

// Check for active sync on page load
onMounted(async () => {
    console.log('Component mounted with props:', {
        jiraSettings: props.jiraSettings,
        availableProjects: props.availableProjects,
        activeSyncId: props.activeSyncId
    });
    
    // Load projects automatically if settings are configured
    if (props.jiraSettings.jira_host && props.jiraSettings.jira_email && props.jiraSettings.is_api_token_set) {
        if (!props.availableProjects || props.availableProjects.length === 0) {
            console.log('Auto-loading projects on mount');
            await fetchProjects();
        }
    }
    
    if (props.activeSyncId && userId.value) {
        try {
            await fetchSyncStatus(props.activeSyncId);
            if (hasActiveSync.value) {
                showSyncProgress.value = true;
                startListening(userId.value);
            }
        } catch (error) {
            console.error('Failed to fetch sync status:', error);
        }
    }
});

const submit = () => {
    // Ensure form data is synced with selected projects before submission
    form.project_keys = selectedProjects.value;
    console.log('Submitting form with project_keys:', form.project_keys);
    console.log('Selected projects before submit:', selectedProjects.value);
    
    form.post(route('settings.jira.store'), {
        preserveScroll: true,
        onSuccess: () => {
            syncMessage.value = 'JIRA settings saved successfully.';
            syncMessageType.value = 'success';
            console.log('Settings saved successfully');
            // Reload available projects to ensure consistency
            if (form.jira_host && form.jira_email) {
                fetchProjects();
            }
        },
        onError: (errors) => {
            console.error('Save errors:', errors);
            syncMessage.value = 'Failed to save JIRA settings. Please check your input.';
            syncMessageType.value = 'error';
        }
    });
};

const testConnection = () => {
    const testForm = useForm({
        jira_host: form.jira_host,
        api_token: form.api_token || 'existing',
    });
    
    testForm.post(route('settings.jira.test'), {
        preserveScroll: true,
        onSuccess: () => {
            syncMessage.value = 'JIRA connection test successful.';
            syncMessageType.value = 'success';
        },
        onError: () => {
            syncMessage.value = 'JIRA connection test failed. Please check your settings.';
            syncMessageType.value = 'error';
        }
    });
};

const syncData = () => {
    console.log('Sync data button clicked');
    console.log('Can sync:', canSync.value);
    console.log('Selected projects for sync:', selectedProjects.value);
    
    if (!canSync.value) {
        console.error('Cannot sync - requirements not met');
        syncMessage.value = 'Cannot start sync. Please ensure you have selected projects and configured your API token.';
        syncMessageType.value = 'error';
        return;
    }
    
    const syncForm = useForm({});
    
    syncForm.post(route('jira.import'), {
        preserveScroll: true,
        onSuccess: (response) => {
            console.log('Sync started successfully:', response);
            // Start listening for sync progress
            if (userId.value) {
                showSyncProgress.value = true;
                startListening(userId.value);
                syncMessage.value = 'JIRA data sync started successfully.';
                syncMessageType.value = 'info';
            }
        },
        onError: (errors) => {
            console.error('Sync start failed:', errors);
            syncMessage.value = 'Failed to start JIRA data sync. Please try again.';
            syncMessageType.value = 'error';
        }
    });
};

const fetchProjects = async () => {
    if (!form.jira_host || !form.jira_email || (!form.api_token && !props.jiraSettings.is_api_token_set)) {
        syncMessage.value = 'Please configure JIRA host, email, and API token first.';
        syncMessageType.value = 'error';
        return;
    }
    
    loadingProjects.value = true;
    syncMessage.value = '';
    
    try {
        const response = await fetch(route('settings.jira.projects'));
        const data = await response.json();
        
        if (data.success) {
            availableProjects.value = data.projects;
            syncMessage.value = `Loaded ${data.projects.length} available projects.`;
            syncMessageType.value = 'success';
        } else {
            syncMessage.value = 'Failed to fetch projects. Please check your connection settings.';
            syncMessageType.value = 'error';
        }
    } catch (error) {
        console.error('Failed to fetch projects:', error);
        syncMessage.value = 'Failed to fetch projects. Please check your connection settings.';
        syncMessageType.value = 'error';
    } finally {
        loadingProjects.value = false;
    }
};

const toggleProject = (projectKey: string, checked: boolean | string) => {
    console.log(`toggleProject called for ${projectKey}:`, checked);
    
    // Convert to boolean - treat anything truthy as true, but specifically handle "indeterminate"
    const isChecked = checked === true || (checked !== false && checked !== "indeterminate");
    
    if (isChecked) {
        // Add to selected projects if not already there
        if (!selectedProjects.value.includes(projectKey)) {
            selectedProjects.value = [...selectedProjects.value, projectKey];
        }
    } else {
        // Remove from selected projects
        selectedProjects.value = selectedProjects.value.filter(key => key !== projectKey);
    }
    
    // Sync with form data
    form.project_keys = selectedProjects.value;
    console.log('Updated selectedProjects:', selectedProjects.value);
    console.log('Synced form.project_keys:', form.project_keys);
};

const selectAllProjects = () => {
    selectedProjects.value = availableProjects.value.map(project => project.key);
    form.project_keys = selectedProjects.value;
    console.log('Selected all projects:', selectedProjects.value);
};

const deselectAllProjects = () => {
    selectedProjects.value = [];
    form.project_keys = selectedProjects.value;
    console.log('Deselected all projects');
};

const areAllProjectsSelected = computed(() => {
    return availableProjects.value.length > 0 && 
           selectedProjects.value.length === availableProjects.value.length;
});

const areSomeProjectsSelected = computed(() => {
    return selectedProjects.value.length > 0 && selectedProjects.value.length < availableProjects.value.length;
});

const isProjectSelected = (projectKey: string) => {
    const isSelected = selectedProjects.value.includes(projectKey);
    console.log(`isProjectSelected for ${projectKey}:`, isSelected);
    return isSelected;
};

const selectedProjectsCount = computed(() => {
    const count = selectedProjects.value.length;
    console.log('selectedProjectsCount computed:', { keys: selectedProjects.value, count });
    return count;
});

const canSync = computed(() => {
    const result = props.jiraSettings.is_api_token_set && 
           selectedProjects.value.length > 0 && 
           !hasActiveSync.value &&
           !form.processing;
    console.log('canSync computed:', { 
        apiTokenSet: props.jiraSettings.is_api_token_set,
        projectCount: selectedProjects.value.length,
        hasActiveSync: hasActiveSync.value,
        processing: form.processing,
        result 
    });
    return result;
});

const connectionStatus = computed(() => {
    if (!form.jira_host || !form.jira_email) {
        return { type: 'warning', message: 'Configuration incomplete' };
    }
    if (!props.jiraSettings.is_api_token_set && !form.api_token) {
        return { type: 'warning', message: 'API token not set' };
    }
    return { type: 'success', message: 'Ready to sync' };
});

// Handle sync progress events
const handleSyncCompleted = () => {
    syncMessage.value = 'JIRA data sync completed successfully!';
    syncMessageType.value = 'success';
};

const handleSyncFailed = () => {
    syncMessage.value = 'JIRA data sync failed. Please check the sync history for details.';
    syncMessageType.value = 'error';
};

const handleSyncCancelled = () => {
    syncMessage.value = 'JIRA data sync was cancelled.';
    syncMessageType.value = 'info';
};

const closeSyncProgress = () => {
    showSyncProgress.value = false;
    clearProgress();
};

const viewSyncHistory = () => {
    window.open(route('jira.sync-history.index'), '_blank');
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="JIRA settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall 
                    title="JIRA integration settings" 
                    description="Configure your JIRA connection and sync data automatically" 
                />

                <!-- Status Messages -->
                <div v-if="syncMessage" class="rounded-md p-4" :class="{
                    'bg-green-50 border border-green-200 text-green-800 dark:bg-green-900/50 dark:border-green-800 dark:text-green-200': syncMessageType === 'success',
                    'bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/50 dark:border-red-800 dark:text-red-200': syncMessageType === 'error',
                    'bg-blue-50 border border-blue-200 text-blue-800 dark:bg-blue-900/50 dark:border-blue-800 dark:text-blue-200': syncMessageType === 'info'
                }">
                    <div class="flex items-center space-x-2">
                        <CheckCircleIcon v-if="syncMessageType === 'success'" class="w-4 h-4" />
                        <XCircleIcon v-else-if="syncMessageType === 'error'" class="w-4 h-4" />
                        <AlertTriangleIcon v-else class="w-4 h-4" />
                        <span>{{ syncMessage }}</span>
                    </div>
                </div>

                <!-- Sync Progress -->
                <div v-if="showSyncProgress">
                    <JiraSyncProgress
                        :userId="userId"
                        @close="closeSyncProgress"
                        @syncCompleted="handleSyncCompleted"
                        @syncFailed="handleSyncFailed"
                        @syncCancelled="handleSyncCancelled"
                    />
                </div>

                <!-- Connection Status Card -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center space-x-2">
                            <Settings2Icon class="w-5 h-5" />
                            <span>Connection Settings</span>
                        </CardTitle>
                        <CardDescription>
                            Configure your JIRA instance connection details
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form @submit.prevent="submit" class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="jira_host">JIRA Host URL</Label>
                                <Input 
                                    id="jira_host" 
                                    class="mt-1 block w-full" 
                                    v-model="form.jira_host" 
                                    required 
                                    placeholder="https://yourcompany.atlassian.net" 
                                />
                                <InputError class="mt-2" :message="form.errors.jira_host" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="jira_email">JIRA Email</Label>
                                <Input 
                                    id="jira_email" 
                                    type="email"
                                    class="mt-1 block w-full" 
                                    v-model="form.jira_email" 
                                    required
                                    placeholder="your-email@company.com"
                                />
                                <InputError class="mt-2" :message="form.errors.jira_email" />
                                <p class="text-sm text-muted-foreground">
                                    The email address associated with your JIRA account
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="api_token">API Token</Label>
                                <Input 
                                    id="api_token" 
                                    type="password" 
                                    class="mt-1 block w-full" 
                                    v-model="form.api_token" 
                                    :placeholder="jiraSettings.is_api_token_set ? 'Token is set (leave blank to keep current)' : 'Enter your JIRA API token'"
                                />
                                <InputError class="mt-2" :message="form.errors.api_token" />
                                <p class="text-sm text-muted-foreground">
                                    Generate an API token from your JIRA account settings
                                </p>
                            </div>

                            <!-- Connection Status Indicator -->
                            <div class="flex items-center space-x-2 p-3 rounded-md" :class="{
                                'bg-green-50 dark:bg-green-900/50': connectionStatus.type === 'success',
                                'bg-yellow-50 dark:bg-yellow-900/50': connectionStatus.type === 'warning',
                                'bg-red-50 dark:bg-red-900/50': connectionStatus.type === 'error'
                            }">
                                <CheckCircleIcon v-if="connectionStatus.type === 'success'" class="w-4 h-4 text-green-600" />
                                <AlertTriangleIcon v-else class="w-4 h-4 text-yellow-600" />
                                <span class="text-sm font-medium" :class="{
                                    'text-green-800 dark:text-green-200': connectionStatus.type === 'success',
                                    'text-yellow-800 dark:text-yellow-200': connectionStatus.type === 'warning',
                                    'text-red-800 dark:text-red-200': connectionStatus.type === 'error'
                                }">
                                    {{ connectionStatus.message }}
                                </span>
                            </div>

                            <div class="flex items-center gap-4">
                                <Button :disabled="form.processing" @click="submit">
                                    <Settings2Icon class="w-4 h-4 mr-2" />
                                    Save Settings
                                </Button>
                                
                                <Button 
                                    type="button" 
                                    variant="outline" 
                                    @click="testConnection"
                                    :disabled="!form.jira_host || form.processing"
                                >
                                    <CheckCircleIcon class="w-4 h-4 mr-2" />
                                    Test Connection
                                </Button>

                                <Transition
                                    enter-active-class="transition ease-in-out"
                                    enter-from-class="opacity-0"
                                    leave-active-class="transition ease-in-out"
                                    leave-to-class="opacity-0"
                                >
                                    <p v-show="form.recentlySuccessful" class="text-sm text-green-600">Saved successfully.</p>
                                </Transition>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <!-- Project Selection Card -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center space-x-2">
                            <DatabaseIcon class="w-5 h-5" />
                            <span>Project Selection</span>
                        </CardTitle>
                        <CardDescription>
                            Choose which JIRA projects to sync and track
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <Label>Projects to Track</Label>
                                <Button 
                                    type="button" 
                                    variant="outline" 
                                    size="sm"
                                    @click="fetchProjects"
                                    :disabled="loadingProjects || !form.jira_host || !form.jira_email"
                                >
                                    <RefreshCwIcon class="w-4 h-4 mr-2" :class="{ 'animate-spin': loadingProjects }" />
                                    {{ loadingProjects ? 'Loading...' : 'Refresh Projects' }}
                                </Button>
                            </div>
                            
                            <div v-if="availableProjects.length > 0" class="space-y-3">
                                <!-- Select All Controls -->
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-md border">
                                    <div class="flex items-center space-x-2">
                                        <Checkbox 
                                            id="select-all-projects"
                                            :model-value="areAllProjectsSelected"
                                            :indeterminate="areSomeProjectsSelected"
                                            @update:model-value="(checked) => checked ? selectAllProjects() : deselectAllProjects()"
                                        />
                                        <Label for="select-all-projects" class="text-sm font-medium cursor-pointer">
                                            {{ areAllProjectsSelected ? 'Deselect All' : 'Select All' }}
                                        </Label>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ selectedProjectsCount }} of {{ availableProjects.length }} selected
                                    </div>
                                </div>
                                
                                <!-- Project List -->
                                <div class="space-y-2 max-h-64 overflow-y-auto border rounded-md p-4">
                                    <div v-for="project in availableProjects" :key="project.key" class="flex items-center space-x-2">
                                        <Checkbox 
                                            :id="`project-${project.key}`"
                                            :model-value="isProjectSelected(project.key)"
                                            @update:model-value="(checked) => toggleProject(project.key, checked)"
                                        />
                                        <Label 
                                            :for="`project-${project.key}`" 
                                            class="text-sm font-normal cursor-pointer flex-1"
                                        >
                                            <span class="font-medium">{{ project.key }}</span> - {{ project.name }}
                                        </Label>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-sm text-muted-foreground p-4 border rounded-md">
                                No projects loaded. Save your settings and click "Refresh Projects" to load available projects.
                            </div>
                            <InputError class="mt-2" :message="form.errors.project_keys" />
                        </div>
                    </CardContent>
                </Card>

                <!-- Data Sync Card -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center space-x-2">
                            <RefreshCwIcon class="w-5 h-5" />
                            <span>Data Synchronization</span>
                        </CardTitle>
                        <CardDescription>
                            Sync JIRA data for selected projects and view sync history
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-4">
                            <Button 
                                type="button" 
                                @click="syncData"
                                :disabled="!canSync"
                                :class="{ 'animate-pulse': hasActiveSync, 'opacity-50 cursor-not-allowed': !canSync }"
                            >
                                <RefreshCwIcon class="w-4 h-4 mr-2" :class="{ 'animate-spin': hasActiveSync }" />
                                {{ hasActiveSync ? 'Sync in Progress...' : 'Sync JIRA Data' }}
                            </Button>

                            <Button 
                                type="button" 
                                variant="outline"
                                @click="viewSyncHistory"
                            >
                                <HistoryIcon class="w-4 h-4 mr-2" />
                                View Sync History
                            </Button>
                        </div>

                        <div v-if="!canSync && !hasActiveSync" class="mt-4 text-sm text-muted-foreground">
                            <p v-if="!props.jiraSettings.is_api_token_set">
                                ⚠️ Please set your API token first
                            </p>
                            <p v-else-if="selectedProjects.length === 0">
                                ⚠️ Please select at least one project to sync
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>