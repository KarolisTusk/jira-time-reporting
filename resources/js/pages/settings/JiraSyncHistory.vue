<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="JIRA Sync History" />

    <SettingsLayout>
      <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
          <HeadingSmall 
            title="JIRA Sync History" 
            description="View and manage JIRA data synchronization history" 
          />
          
          <div class="flex items-center space-x-2">
            <div v-if="isListeningToUpdates" class="flex items-center space-x-1 text-xs text-green-600 dark:text-green-400">
              <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
              <span>Live updates</span>
            </div>
            
            <Button 
              variant="outline" 
              @click="refreshData"
              :disabled="isLoading"
            >
              <RefreshCwIcon class="w-4 h-4 mr-2" :class="{ 'animate-spin': isLoading }" />
              Refresh
            </Button>
            
            <Button @click="router.visit(route('settings.jira'))">
              <PlusIcon class="w-4 h-4 mr-2" />
              New Sync
            </Button>
          </div>
        </div>

        <!-- Status Messages -->
        <div v-if="errorMessage" class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-lg p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <XCircleIcon class="w-5 h-5 text-red-600" />
              <div>
                <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Error</h3>
                <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ errorMessage }}</p>
              </div>
            </div>
            <Button variant="ghost" size="sm" @click="dismissMessages">
              <XIcon class="w-4 h-4" />
            </Button>
          </div>
        </div>

        <div v-if="successMessage" class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 rounded-lg p-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
              <CheckCircleIcon class="w-5 h-5 text-green-600" />
              <div>
                <h3 class="text-sm font-medium text-green-800 dark:text-green-200">Success</h3>
                <p class="mt-1 text-sm text-green-700 dark:text-green-300">{{ successMessage }}</p>
              </div>
            </div>
            <Button variant="ghost" size="sm" @click="dismissMessages">
              <XIcon class="w-4 h-4" />
            </Button>
          </div>
        </div>

        <!-- Active Sync Warning -->
        <div v-if="hasActiveSync" class="bg-blue-50 dark:bg-blue-900/50 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
          <div class="flex items-center space-x-2">
            <LoaderIcon class="w-5 h-5 text-blue-600 animate-spin" />
            <div>
              <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">Sync in Progress</h3>
              <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                A JIRA sync is currently running. The table below will update automatically as the sync progresses.
              </p>
            </div>
          </div>
        </div>

        <!-- Statistics Cards -->
        <div v-if="stats" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardContent class="p-6">
              <div class="flex items-center space-x-2">
                <div class="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                  <DatabaseIcon class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                  <p class="text-sm font-medium text-muted-foreground">Total Syncs</p>
                  <p class="text-2xl font-bold">{{ stats.total_syncs }}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent class="p-6">
              <div class="flex items-center space-x-2">
                <div class="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                  <CheckCircleIcon class="w-4 h-4 text-green-600 dark:text-green-400" />
                </div>
                <div>
                  <p class="text-sm font-medium text-muted-foreground">Completed</p>
                  <p class="text-2xl font-bold">{{ stats.completed_syncs }}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent class="p-6">
              <div class="flex items-center space-x-2">
                <div class="p-2 bg-red-100 dark:bg-red-900 rounded-lg">
                  <XCircleIcon class="w-4 h-4 text-red-600 dark:text-red-400" />
                </div>
                <div>
                  <p class="text-sm font-medium text-muted-foreground">Failed</p>
                  <p class="text-2xl font-bold">{{ stats.failed_syncs }}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent class="p-6">
              <div class="flex items-center space-x-2">
                <div class="p-2 bg-orange-100 dark:bg-orange-900 rounded-lg">
                  <ClockIcon class="w-4 h-4 text-orange-600 dark:text-orange-400" />
                </div>
                <div>
                  <p class="text-sm font-medium text-muted-foreground">Avg Duration</p>
                  <p class="text-2xl font-bold">{{ formatDuration(stats.average_sync_duration) }}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Sync History Table -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center space-x-2">
              <HistoryIcon class="w-4 h-4" />
              <span>Sync History</span>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="space-y-4">
              <div v-if="isLoading" class="flex items-center justify-center p-8">
                <div class="flex items-center space-x-2 text-muted-foreground">
                  <LoaderIcon class="w-4 h-4 animate-spin" />
                  <span>Loading sync history...</span>
                </div>
              </div>

              <div v-else-if="localSyncHistories.data.length === 0" class="text-center p-8">
                <DatabaseIcon class="w-12 h-12 mx-auto text-muted-foreground mb-4" />
                <h3 class="text-lg font-semibold mb-2">No Sync History</h3>
                <p class="text-muted-foreground mb-4">
                  No JIRA sync operations have been performed yet.
                </p>
                <Button @click="router.visit(route('settings.jira'))">
                  Start Your First Sync
                </Button>
              </div>

              <div v-else class="space-y-4">
                <!-- Table -->
                <div class="border rounded-lg overflow-hidden">
                  <table class="w-full">
                    <thead class="bg-muted/50">
                      <tr>
                        <th class="text-left p-4 font-medium">Started</th>
                        <th class="text-left p-4 font-medium">Status</th>
                        <th class="text-left p-4 font-medium">Progress</th>
                        <th class="text-left p-4 font-medium">Duration</th>
                        <th class="text-left p-4 font-medium">User</th>
                        <th class="text-left p-4 font-medium">Type</th>
                        <th class="text-right p-4 font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr 
                        v-for="sync in localSyncHistories.data" 
                        :key="sync.id"
                        class="border-t hover:bg-muted/25 transition-colors"
                        :class="{ 'animate-pulse': sync.status === 'in_progress' }"
                      >
                        <td class="p-4">
                          <div class="text-sm">
                            {{ formatDateTime(sync.started_at) }}
                          </div>
                        </td>
                        <td class="p-4">
                          <div class="flex items-center space-x-2">
                            <LoaderIcon v-if="sync.status === 'in_progress'" class="w-4 h-4 animate-spin text-blue-600" />
                            <ClockIcon v-else-if="sync.status === 'pending'" class="w-4 h-4 text-gray-400" />
                            <CheckCircleIcon v-else-if="sync.status === 'completed'" class="w-4 h-4 text-green-600" />
                            <XCircleIcon v-else-if="sync.status === 'failed'" class="w-4 h-4 text-red-600" />
                            
                            <span class="text-sm font-medium capitalize">
                              {{ sync.status.replace('_', ' ') }}
                            </span>
                            
                            <AlertTriangleIcon 
                              v-if="sync.has_errors" 
                              class="w-3 h-3 text-amber-500" 
                              :title="`${sync.error_count} error(s) occurred`"
                            />
                          </div>
                        </td>
                        <td class="p-4">
                          <div class="space-y-1">
                            <div class="flex items-center justify-between text-xs text-muted-foreground">
                              <span>{{ Math.round(sync.progress_percentage) }}%</span>
                            </div>
                            <div class="w-24 bg-muted rounded-full h-1.5">
                              <div 
                                class="h-1.5 rounded-full transition-all duration-300"
                                :class="{
                                  'bg-blue-600': sync.status === 'in_progress',
                                  'bg-green-600': sync.status === 'completed',
                                  'bg-red-600': sync.status === 'failed',
                                  'bg-gray-400': sync.status === 'pending'
                                }"
                                :style="{ width: `${sync.progress_percentage}%` }"
                              ></div>
                            </div>
                          </div>
                        </td>
                        <td class="p-4">
                          <span class="text-sm">
                            {{ sync.formatted_duration || '-' }}
                          </span>
                        </td>
                        <td class="p-4">
                          <span class="text-sm">
                            {{ sync.user?.name || 'Unknown' }}
                          </span>
                        </td>
                        <td class="p-4">
                          <span class="text-sm capitalize">
                            {{ sync.sync_type }}
                          </span>
                        </td>
                        <td class="p-4">
                          <div class="flex items-center justify-end space-x-2">
                            <Button
                              variant="ghost"
                              size="sm"
                              @click="viewDetails(sync)"
                              title="View details"
                            >
                              <EyeIcon class="w-3 h-3" />
                            </Button>
                            
                            <Button
                              v-if="sync.can_retry"
                              variant="ghost"
                              size="sm"
                              @click="retrySync(sync)"
                              :disabled="isProcessing"
                              title="Retry sync"
                            >
                              <RefreshCwIcon class="w-3 h-3" :class="{ 'animate-spin': isProcessing }" />
                            </Button>
                            
                            <Button
                              v-if="sync.can_cancel"
                              variant="ghost"
                              size="sm"
                              @click="cancelSync(sync)"
                              :disabled="isProcessing"
                              title="Cancel sync"
                            >
                              <XIcon class="w-3 h-3" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>

<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, reactive } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { useJiraSyncProgress } from '@/composables/useJiraSyncProgress';
import echo from '@/echo';
import {
  RefreshCwIcon,
  PlusIcon,
  DatabaseIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  HistoryIcon,
  LoaderIcon,
  EyeIcon,
  XIcon,
  AlertTriangleIcon,
} from 'lucide-vue-next';

interface Props {
  syncHistories: {
    data: Array<{
      id: number;
      started_at: string;
      completed_at: string | null;
      status: 'pending' | 'in_progress' | 'completed' | 'failed';
      progress_percentage: number;
      formatted_duration: string | null;
      sync_type: 'manual' | 'scheduled';
      error_count: number;
      has_errors: boolean;
      is_running: boolean;
      can_retry: boolean;
      can_cancel: boolean;
      user?: {
        id: number;
        name: string;
        email: string;
      };
    }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    prev_page_url: string | null;
    next_page_url: string | null;
  };
  stats?: {
    total_syncs: number;
    completed_syncs: number;
    failed_syncs: number;
    in_progress_syncs: number;
    pending_syncs: number;
    average_sync_duration: number;
  };
}

const props = defineProps<Props>();
const page = usePage();
const userId = computed(() => page.props.auth?.user?.id);

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'JIRA Sync History',
    href: '/jira/sync-history',
  },
];

// State
const isLoading = ref(false);
const isProcessing = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const isListeningToUpdates = ref(false);

// Local reactive copy of sync histories for real-time updates
const localSyncHistories = reactive({ ...props.syncHistories });

// Real-time sync progress tracking
const { hasActiveSync } = useJiraSyncProgress(userId.value);

// Setup real-time updates for sync history changes
onMounted(() => {
  if (userId.value) {
    try {
      const channel = echo.private(`jira-sync.${userId.value}`);
      
      channel.listen('.jira.sync.progress', (data: any) => {
        console.log('Received sync progress update:', data);
        // Update the specific sync record in the table
        updateSyncInTable(data);
        
        // If sync completed or failed, refresh stats
        if (data.status === 'completed' || data.status === 'failed') {
          setTimeout(() => {
            refreshStats();
          }, 1000);
        }
      });

      isListeningToUpdates.value = true;
      console.log(`Started listening to sync history updates for user ${userId.value}`);
    } catch (err) {
      console.error('Failed to start listening to sync updates:', err);
    }
  }
});

onUnmounted(() => {
  if (isListeningToUpdates.value && userId.value) {
    try {
      echo.leave(`jira-sync.${userId.value}`);
      isListeningToUpdates.value = false;
      console.log('Stopped listening to sync history updates');
    } catch (err) {
      console.error('Failed to stop listening:', err);
    }
  }
});

// Methods
const updateSyncInTable = (syncData: any) => {
  const syncIndex = localSyncHistories.data.findIndex(sync => sync.id === syncData.sync_history_id);
  if (syncIndex !== -1) {
    // Update the sync record with new data
    const updatedSync = {
      ...localSyncHistories.data[syncIndex],
      status: syncData.status,
      progress_percentage: syncData.progress_percentage,
      completed_at: syncData.completed_at,
      formatted_duration: syncData.formatted_duration,
      error_count: syncData.error_count,
      has_errors: syncData.has_errors,
      is_running: syncData.is_running,
    };
    
    // Replace the sync in the reactive array
    localSyncHistories.data.splice(syncIndex, 1, updatedSync);
  }
};

const refreshData = () => {
  isLoading.value = true;
  router.reload({ 
    only: ['syncHistories', 'stats'],
    onFinish: () => {
      isLoading.value = false;
      // Update local copy with fresh data
      Object.assign(localSyncHistories, props.syncHistories);
    }
  });
};

const refreshStats = () => {
  router.reload({ only: ['stats'] });
};

const viewDetails = (sync: any) => {
  router.visit(route('jira.sync-history.show', { syncHistory: sync.id }));
};

const retrySync = async (sync: any) => {
  if (!confirm('Are you sure you want to retry this sync?')) return;
  
  isProcessing.value = true;
  errorMessage.value = '';
  successMessage.value = '';
  
  try {
    const response = await fetch(route('jira.sync-history.retry', { syncHistory: sync.id }), {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      }
    });

    if (response.ok) {
      const result = await response.json();
      successMessage.value = result.message || 'Sync retry initiated successfully.';
      setTimeout(() => {
        refreshData();
      }, 1000);
    } else {
      const error = await response.json();
      errorMessage.value = error.error || 'Failed to retry sync';
    }
  } catch (error) {
    console.error('Failed to retry sync:', error);
    errorMessage.value = 'Failed to retry sync. Please check your connection and try again.';
  } finally {
    isProcessing.value = false;
  }
};

const cancelSync = async (sync: any) => {
  if (!confirm('Are you sure you want to cancel this sync?')) return;
  
  isProcessing.value = true;
  errorMessage.value = '';
  successMessage.value = '';
  
  try {
    const response = await fetch(route('jira.sync-history.cancel', { syncHistory: sync.id }), {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      }
    });

    if (response.ok) {
      const result = await response.json();
      successMessage.value = result.message || 'Sync cancelled successfully.';
      setTimeout(() => {
        refreshData();
      }, 1000);
    } else {
      const error = await response.json();
      errorMessage.value = error.error || 'Failed to cancel sync';
    }
  } catch (error) {
    console.error('Failed to cancel sync:', error);
    errorMessage.value = 'Failed to cancel sync. Please check your connection and try again.';
  } finally {
    isProcessing.value = false;
  }
};

const dismissMessages = () => {
  errorMessage.value = '';
  successMessage.value = '';
};

const formatDateTime = (dateString: string) => {
  try {
    const date = new Date(dateString);
    return date.toLocaleString();
  } catch {
    return dateString;
  }
};

const formatDuration = (seconds: number | null) => {
  if (!seconds) return '-';
  
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);
  
  if (hours > 0) {
    return `${hours}h ${minutes}m ${secs}s`;
  } else if (minutes > 0) {
    return `${minutes}m ${secs}s`;
  } else {
    return `${secs}s`;
  }
};
</script> 