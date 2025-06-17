<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head :title="`Sync Details - ${syncHistory?.id}`" />

    <SettingsLayout>
      <div class="flex flex-col space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-4">
            <Button variant="ghost" size="sm" @click="router.visit(route('jira.sync-history.index'))">
              <ArrowLeftIcon class="w-4 h-4 mr-2" />
              Back to History
            </Button>
            
            <div class="h-6 w-px bg-border"></div>
            
            <HeadingSmall 
              :title="`Sync #${syncHistory?.id}`"
              :description="`Started ${formatDateTime(syncHistory?.started_at)}`"
            />
          </div>
          
          <div class="flex items-center space-x-2">
            <Button 
              variant="outline" 
              @click="refreshData"
              :disabled="isLoading"
            >
              <RefreshCwIcon class="w-4 h-4 mr-2" :class="{ 'animate-spin': isLoading }" />
              Refresh
            </Button>
            
            <Button
              v-if="syncHistory?.can_retry"
              @click="retrySync"
              :disabled="isProcessing"
            >
              <RefreshCwIcon class="w-4 h-4 mr-2" />
              Retry Sync
            </Button>
            
            <Button
              v-if="syncHistory?.can_cancel"
              variant="destructive"
              @click="cancelSync"
              :disabled="isProcessing"
            >
              <XIcon class="w-4 h-4 mr-2" />
              Cancel Sync
            </Button>
          </div>
        </div>

        <!-- Status Overview -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center space-x-2">
              <div class="flex items-center space-x-2">
                <LoaderIcon v-if="syncHistory?.status === 'in_progress'" class="w-5 h-5 animate-spin text-blue-600" />
                <ClockIcon v-else-if="syncHistory?.status === 'pending'" class="w-5 h-5 text-gray-400" />
                <CheckCircleIcon v-else-if="syncHistory?.status === 'completed'" class="w-5 h-5 text-green-600" />
                <XCircleIcon v-else-if="syncHistory?.status === 'failed'" class="w-5 h-5 text-red-600" />
                
                <span>Sync Status</span>
              </div>
              
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="{
                'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200': syncHistory?.status === 'in_progress',
                'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200': syncHistory?.status === 'pending',
                'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200': syncHistory?.status === 'completed',
                'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200': syncHistory?.status === 'failed'
              }">
                {{ syncHistory?.status?.replace('_', ' ') }}
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
              <div>
                <div class="text-sm font-medium text-muted-foreground">Progress</div>
                <div class="mt-2">
                  <div class="flex items-center justify-between text-sm mb-1">
                    <span>{{ Math.round(syncHistory?.progress_percentage || 0) }}%</span>
                  </div>
                  <div class="w-full bg-muted rounded-full h-2">
                    <div 
                      class="h-2 rounded-full transition-all duration-300" 
                      :class="{
                        'bg-blue-600': syncHistory?.status === 'in_progress',
                        'bg-green-600': syncHistory?.status === 'completed',
                        'bg-red-600': syncHistory?.status === 'failed',
                        'bg-gray-400': syncHistory?.status === 'pending'
                      }"
                      :style="{ width: `${syncHistory?.progress_percentage || 0}%` }"
                    ></div>
                  </div>
                </div>
              </div>
              
              <div>
                <div class="text-sm font-medium text-muted-foreground">Duration</div>
                <div class="mt-1 text-lg font-semibold">
                  {{ syncHistory?.formatted_duration || '-' }}
                </div>
              </div>
              
              <div>
                <div class="text-sm font-medium text-muted-foreground">Triggered By</div>
                <div class="mt-1 text-lg font-semibold">
                  {{ syncHistory?.user?.name || 'Unknown' }}
                </div>
              </div>
              
              <div>
                <div class="text-sm font-medium text-muted-foreground">Type</div>
                <div class="mt-1 text-lg font-semibold capitalize">
                  {{ syncHistory?.sync_type || '-' }}
                </div>
              </div>
            </div>

            <!-- Error Summary -->
            <div v-if="syncHistory?.error_count && syncHistory.error_count > 0" class="mt-6 p-4 bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-lg">
              <div class="flex items-center space-x-2">
                <AlertTriangleIcon class="w-5 h-5 text-red-600" />
                <div>
                  <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                    {{ syncHistory.error_count }} Error{{ syncHistory.error_count > 1 ? 's' : '' }} Encountered
                  </h3>
                  <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                    Review the logs below for detailed error information and troubleshooting guidance.
                  </p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Logs Section -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center space-x-2">
              <FileTextIcon class="w-4 h-4" />
              <span>Sync Logs</span>
              <span v-if="logs?.total" class="text-sm font-normal text-muted-foreground">
                ({{ logs.total }} entries)
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <!-- Log Entries -->
            <div class="space-y-4">
              <div v-if="isLoadingLogs" class="flex items-center justify-center p-8">
                <div class="flex items-center space-x-2 text-muted-foreground">
                  <LoaderIcon class="w-4 h-4 animate-spin" />
                  <span>Loading logs...</span>
                </div>
              </div>

              <div v-else-if="!logs?.data?.length" class="text-center p-8">
                <FileTextIcon class="w-12 h-12 mx-auto text-muted-foreground mb-4" />
                <h3 class="text-lg font-semibold mb-2">No Logs Found</h3>
                <p class="text-muted-foreground">
                  No log entries found for this sync.
                </p>
              </div>

              <div v-else class="space-y-2">
                <div 
                  v-for="log in logs.data" 
                  :key="log.id"
                  class="border rounded-lg p-4 hover:bg-muted/25 transition-colors"
                >
                  <div class="flex items-start justify-between">
                    <div class="flex items-center space-x-2 mb-2">
                      <div class="flex items-center space-x-2">
                        <InfoIcon v-if="log.level === 'info'" class="w-4 h-4 text-blue-600" />
                        <AlertTriangleIcon v-else-if="log.level === 'warning'" class="w-4 h-4 text-yellow-600" />
                        <XCircleIcon v-else-if="log.level === 'error'" class="w-4 h-4 text-red-600" />
                        
                        <span class="text-sm font-medium capitalize" :class="{
                          'text-blue-700 dark:text-blue-300': log.level === 'info',
                          'text-yellow-700 dark:text-yellow-300': log.level === 'warning',
                          'text-red-700 dark:text-red-300': log.level === 'error'
                        }">
                          {{ log.level }}
                        </span>
                      </div>
                      
                      <div class="text-xs text-muted-foreground">
                        {{ formatDateTime(log.timestamp) }}
                      </div>
                      
                      <div v-if="log.entity_type" class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-muted">
                        {{ log.entity_type }}
                        <span v-if="log.entity_id" class="ml-1 opacity-75">#{{ log.entity_id }}</span>
                      </div>
                      
                      <div v-if="log.operation" class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-muted">
                        {{ log.operation }}
                      </div>
                    </div>
                  </div>
                  
                  <div class="text-sm">
                    {{ log.message }}
                  </div>
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
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import {
  ArrowLeftIcon,
  RefreshCwIcon,
  XIcon,
  LoaderIcon,
  ClockIcon,
  CheckCircleIcon,
  XCircleIcon,
  AlertTriangleIcon,
  FileTextIcon,
  InfoIcon,
} from 'lucide-vue-next';

interface Props {
  syncHistory: {
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
  };
  logs: {
    data: Array<{
      id: number;
      timestamp: string;
      level: 'info' | 'warning' | 'error';
      message: string;
      context: Record<string, any>;
      entity_type: string | null;
      entity_id: string | null;
      operation: string | null;
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
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'JIRA Sync History',
    href: '/jira/sync-history',
  },
  {
    title: `Sync #${props.syncHistory?.id}`,
    href: `/jira/sync-history/${props.syncHistory?.id}`,
  },
];

// State
const isLoading = ref(false);
const isLoadingLogs = ref(false);
const isProcessing = ref(false);

// Methods
const refreshData = () => {
  router.reload();
};

const retrySync = async () => {
  if (!confirm('Are you sure you want to retry this sync?')) return;
  
  isProcessing.value = true;
  try {
    const response = await fetch(route('jira.sync-history.retry', { syncHistory: props.syncHistory.id }), {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      }
    });

    if (response.ok) {
      router.visit(route('settings.jira'));
    } else {
      const error = await response.json();
      alert(error.error || 'Failed to retry sync');
    }
  } catch (error) {
    console.error('Failed to retry sync:', error);
    alert('Failed to retry sync');
  } finally {
    isProcessing.value = false;
  }
};

const cancelSync = async () => {
  if (!confirm('Are you sure you want to cancel this sync?')) return;
  
  isProcessing.value = true;
  try {
    const response = await fetch(route('jira.sync-history.cancel', { syncHistory: props.syncHistory.id }), {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      }
    });

    if (response.ok) {
      refreshData();
    } else {
      const error = await response.json();
      alert(error.error || 'Failed to cancel sync');
    }
  } catch (error) {
    console.error('Failed to cancel sync:', error);
    alert('Failed to cancel sync');
  } finally {
    isProcessing.value = false;
  }
};

const formatDateTime = (dateString: string) => {
  try {
    const date = new Date(dateString);
    return date.toLocaleString();
  } catch {
    return dateString;
  }
};
</script> 