<template>
  <div class="space-y-4">
    <!-- Error Summary Card -->
    <Card class="border-red-200 dark:border-red-800">
      <CardHeader class="bg-red-50 dark:bg-red-900/50">
        <div class="flex items-start justify-between">
          <div class="flex items-center space-x-2">
            <XCircleIcon class="w-5 h-5 text-red-600 mt-0.5" />
            <div>
              <CardTitle class="text-red-800 dark:text-red-200">
                Sync Failed
              </CardTitle>
              <CardDescription class="text-red-700 dark:text-red-300">
                {{ error.primary_message || 'The JIRA sync operation encountered errors and could not complete successfully.' }}
              </CardDescription>
            </div>
          </div>
          
          <div class="flex items-center space-x-2">
            <Button
              v-if="canRetry"
              variant="destructive"
              size="sm"
              @click="$emit('retry')"
              :disabled="isProcessing"
            >
              <RefreshCwIcon class="w-3 h-3 mr-1" :class="{ 'animate-spin': isProcessing }" />
              Retry Sync
            </Button>
            
            <Button
              variant="outline"
              size="sm"
              @click="toggleExpanded"
            >
              <ChevronDownIcon 
                class="w-3 h-3 mr-1 transition-transform" 
                :class="{ 'rotate-180': isExpanded }"
              />
              {{ isExpanded ? 'Hide' : 'Show' }} Details
            </Button>
          </div>
        </div>
      </CardHeader>
      
      <CardContent v-if="isExpanded" class="space-y-6">
        <!-- Error Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-red-50 dark:bg-red-900/25 rounded-lg p-4">
            <div class="flex items-center space-x-2">
              <XCircleIcon class="w-4 h-4 text-red-600" />
              <span class="text-sm font-medium text-red-800 dark:text-red-200">Total Errors</span>
            </div>
            <p class="text-xl font-bold text-red-900 dark:text-red-100 mt-1">
              {{ error.total_errors }}
            </p>
          </div>
          
          <div class="bg-orange-50 dark:bg-orange-900/25 rounded-lg p-4">
            <div class="flex items-center space-x-2">
              <AlertTriangleIcon class="w-4 h-4 text-orange-600" />
              <span class="text-sm font-medium text-orange-800 dark:text-orange-200">Warnings</span>
            </div>
            <p class="text-xl font-bold text-orange-900 dark:text-orange-100 mt-1">
              {{ error.warning_count || 0 }}
            </p>
          </div>
          
          <div class="bg-blue-50 dark:bg-blue-900/25 rounded-lg p-4">
            <div class="flex items-center space-x-2">
              <InfoIcon class="w-4 h-4 text-blue-600" />
              <span class="text-sm font-medium text-blue-800 dark:text-blue-200">Progress Made</span>
            </div>
            <p class="text-xl font-bold text-blue-900 dark:text-blue-100 mt-1">
              {{ Math.round(error.progress_percentage || 0) }}%
            </p>
          </div>
        </div>

        <!-- Error Categories -->
        <div v-if="errorCategories.length > 0" class="space-y-4">
          <h3 class="text-lg font-semibold">Error Categories</h3>
          
          <div class="space-y-3">
            <div 
              v-for="category in errorCategories" 
              :key="category.type"
              class="border rounded-lg"
            >
              <button
                @click="toggleCategory(category.type)"
                class="w-full flex items-center justify-between p-4 text-left hover:bg-muted/50 transition-colors"
              >
                <div class="flex items-center space-x-3">
                  <component 
                    :is="getCategoryIcon(category.type)" 
                    class="w-4 h-4"
                    :class="getCategoryIconColor(category.type)"
                  />
                  <div>
                    <p class="font-medium">{{ getCategoryTitle(category.type) }}</p>
                    <p class="text-sm text-muted-foreground">
                      {{ category.count }} error{{ category.count > 1 ? 's' : '' }}
                    </p>
                  </div>
                </div>
                
                <ChevronRightIcon 
                  class="w-4 h-4 transition-transform" 
                  :class="{ 'rotate-90': expandedCategories.has(category.type) }"
                />
              </button>
              
              <div v-if="expandedCategories.has(category.type)" class="border-t bg-muted/25">
                <div class="p-4 space-y-3">
                  <div 
                    v-for="errorItem in category.errors" 
                    :key="errorItem.id"
                    class="bg-background rounded-lg p-3 border-l-4"
                    :class="getErrorBorderColor(errorItem.level)"
                  >
                    <div class="flex items-start justify-between mb-2">
                      <div class="flex items-center space-x-2">
                        <span class="text-xs px-2 py-1 rounded-full" :class="getErrorLevelColor(errorItem.level)">
                          {{ errorItem.level }}
                        </span>
                        <span v-if="errorItem.entity_type" class="text-xs text-muted-foreground">
                          {{ errorItem.entity_type }}{{ errorItem.entity_id ? ` #${errorItem.entity_id}` : '' }}
                        </span>
                      </div>
                      <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(errorItem.timestamp) }}
                      </span>
                    </div>
                    
                    <p class="text-sm">{{ errorItem.message }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Suggested Actions -->
        <div class="space-y-4">
          <h3 class="text-lg font-semibold">Suggested Actions</h3>
          
          <div class="space-y-3">
            <div v-for="action in suggestedActions" :key="action.id" class="flex items-start space-x-3 p-3 bg-blue-50 dark:bg-blue-900/25 rounded-lg">
              <component :is="action.icon" class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" />
              <div class="flex-1">
                <h4 class="font-medium text-blue-900 dark:text-blue-100">{{ action.title }}</h4>
                <p class="text-sm text-blue-800 dark:text-blue-200 mt-1">{{ action.description }}</p>
                <div v-if="action.actions" class="mt-2 flex space-x-2">
                  <Button
                    v-for="actionBtn in action.actions"
                    :key="actionBtn.label"
                    variant="outline"
                    size="sm"
                    @click="handleAction(actionBtn)"
                    class="text-xs"
                  >
                    <component :is="actionBtn.icon" class="w-3 h-3 mr-1" />
                    {{ actionBtn.label }}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="flex items-center justify-between pt-4 border-t">
          <div class="flex items-center space-x-2">
            <Button
              variant="outline"
              size="sm"
              @click="exportErrorReport"
              :disabled="isProcessing"
            >
              <DownloadIcon class="w-3 h-3 mr-1" />
              Export Report
            </Button>
            
            <Button
              v-if="error.sync_history_id"
              variant="outline"
              size="sm"
              @click="viewSyncDetails"
            >
              <EyeIcon class="w-3 h-3 mr-1" />
              View Full Sync
            </Button>
          </div>
          
          <div class="flex items-center space-x-2">
            <Button
              v-if="canRetry"
              variant="destructive"
              @click="$emit('retry')"
              :disabled="isProcessing"
            >
              <RefreshCwIcon class="w-4 h-4 mr-2" :class="{ 'animate-spin': isProcessing }" />
              Retry Sync
            </Button>
            
            <Button
              variant="ghost"
              @click="$emit('dismiss')"
            >
              Dismiss
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  XCircleIcon,
  AlertTriangleIcon,
  InfoIcon,
  RefreshCwIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  DownloadIcon,
  EyeIcon,
  WifiOffIcon,
  SettingsIcon,
  KeyIcon,
  DatabaseIcon,
  ClockIcon,
  HelpCircleIcon,
} from 'lucide-vue-next';

interface ErrorLog {
  id: number;
  timestamp: string;
  level: 'info' | 'warning' | 'error';
  message: string;
  context: Record<string, any>;
  entity_type?: string;
  entity_id?: string;
  operation?: string;
}

interface SuggestedAction {
  id: string;
  title: string;
  description: string;
  icon: any;
  actions?: Array<{
    label: string;
    icon: any;
    action: string;
    url?: string;
  }>;
}

interface Props {
  error: {
    sync_history_id?: number;
    primary_message?: string;
    total_errors: number;
    warning_count?: number;
    progress_percentage?: number;
    error_logs: ErrorLog[];
    failed_at?: string;
    error_categories?: Record<string, number>;
  };
  canRetry?: boolean;
  isProcessing?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  canRetry: true,
  isProcessing: false,
});

const emit = defineEmits<{
  retry: [];
  dismiss: [];
}>();

// State
const isExpanded = ref(false);
const expandedCategories = ref(new Set<string>());

// Computed
const errorCategories = computed(() => {
  const categories = new Map<string, ErrorLog[]>();
  
  props.error.error_logs.forEach(log => {
    const type = getErrorType(log);
    if (!categories.has(type)) {
      categories.set(type, []);
    }
    categories.get(type)!.push(log);
  });
  
  return Array.from(categories.entries()).map(([type, errors]) => ({
    type,
    count: errors.length,
    errors,
  })).sort((a, b) => b.count - a.count);
});

const suggestedActions = computed((): SuggestedAction[] => {
  const actions: SuggestedAction[] = [];
  
  // Network/Connection issues
  if (hasErrorType('connection')) {
    actions.push({
      id: 'connection',
      title: 'Connection Issues Detected',
      description: 'Network connectivity problems may have caused the sync to fail. Check your internet connection and JIRA server availability.',
      icon: WifiOffIcon,
      actions: [
        { label: 'Test Connection', icon: WifiOffIcon, action: 'test-connection' },
        { label: 'View Network Settings', icon: SettingsIcon, action: 'network-settings' },
      ],
    });
  }
  
  // Authentication issues
  if (hasErrorType('authentication')) {
    actions.push({
      id: 'authentication',
      title: 'Authentication Problems',
      description: 'Your JIRA credentials may be expired or invalid. Please verify your API token and permissions.',
      icon: KeyIcon,
      actions: [
        { label: 'Update Credentials', icon: KeyIcon, action: 'update-credentials', url: route('settings.jira') },
        { label: 'Test Auth', icon: KeyIcon, action: 'test-auth' },
      ],
    });
  }
  
  // Rate limiting
  if (hasErrorType('rate_limit')) {
    actions.push({
      id: 'rate_limit',
      title: 'Rate Limiting Encountered',
      description: 'JIRA API rate limits were exceeded. Consider reducing sync frequency or using smaller batch sizes.',
      icon: ClockIcon,
      actions: [
        { label: 'Adjust Settings', icon: SettingsIcon, action: 'adjust-settings', url: route('settings.jira') },
        { label: 'Retry Later', icon: ClockIcon, action: 'retry-later' },
      ],
    });
  }
  
  // Data issues
  if (hasErrorType('data')) {
    actions.push({
      id: 'data',
      title: 'Data Processing Issues',
      description: 'Some JIRA data could not be processed correctly. This may be due to missing fields or invalid data formats.',
      icon: DatabaseIcon,
      actions: [
        { label: 'View Data Logs', icon: EyeIcon, action: 'view-logs' },
        { label: 'Skip Invalid Data', icon: DatabaseIcon, action: 'skip-invalid' },
      ],
    });
  }
  
  // General retry suggestion
  if (actions.length === 0) {
    actions.push({
      id: 'general',
      title: 'Temporary Issues',
      description: 'The sync may have failed due to temporary issues. Retrying the operation often resolves transient problems.',
      icon: RefreshCwIcon,
      actions: [
        { label: 'Retry Now', icon: RefreshCwIcon, action: 'retry' },
        { label: 'Get Help', icon: HelpCircleIcon, action: 'get-help' },
      ],
    });
  }
  
  return actions;
});

// Methods
const toggleExpanded = () => {
  isExpanded.value = !isExpanded.value;
};

const toggleCategory = (type: string) => {
  if (expandedCategories.value.has(type)) {
    expandedCategories.value.delete(type);
  } else {
    expandedCategories.value.add(type);
  }
};

const getErrorType = (log: ErrorLog): string => {
  const message = log.message.toLowerCase();
  const context = log.context || {};
  
  if (message.includes('connection') || message.includes('network') || message.includes('timeout')) {
    return 'connection';
  }
  if (message.includes('authentication') || message.includes('unauthorized') || message.includes('forbidden')) {
    return 'authentication';
  }
  if (message.includes('rate limit') || message.includes('429') || context.status_code === 429) {
    return 'rate_limit';
  }
  if (message.includes('data') || message.includes('parse') || message.includes('format')) {
    return 'data';
  }
  if (log.entity_type) {
    return log.entity_type;
  }
  
  return 'general';
};

const hasErrorType = (type: string): boolean => {
  return errorCategories.value.some(category => category.type === type);
};

const getCategoryIcon = (type: string) => {
  const icons: Record<string, any> = {
    connection: WifiOffIcon,
    authentication: KeyIcon,
    rate_limit: ClockIcon,
    data: DatabaseIcon,
    project: DatabaseIcon,
    issue: InfoIcon,
    worklog: ClockIcon,
    user: InfoIcon,
    general: AlertTriangleIcon,
  };
  return icons[type] || AlertTriangleIcon;
};

const getCategoryIconColor = (type: string): string => {
  const colors: Record<string, string> = {
    connection: 'text-red-600',
    authentication: 'text-orange-600',
    rate_limit: 'text-yellow-600',
    data: 'text-blue-600',
    project: 'text-purple-600',
    issue: 'text-green-600',
    worklog: 'text-indigo-600',
    user: 'text-pink-600',
    general: 'text-gray-600',
  };
  return colors[type] || 'text-gray-600';
};

const getCategoryTitle = (type: string): string => {
  const titles: Record<string, string> = {
    connection: 'Connection Errors',
    authentication: 'Authentication Errors',
    rate_limit: 'Rate Limiting',
    data: 'Data Processing Errors',
    project: 'Project Errors',
    issue: 'Issue Errors',
    worklog: 'Worklog Errors',
    user: 'User Errors',
    general: 'General Errors',
  };
  return titles[type] || 'Unknown Errors';
};

const getErrorLevelColor = (level: string): string => {
  const colors: Record<string, string> = {
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    warning: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    error: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
  };
  return colors[level] || colors.error;
};

const getErrorBorderColor = (level: string): string => {
  const colors: Record<string, string> = {
    info: 'border-l-blue-500',
    warning: 'border-l-yellow-500',
    error: 'border-l-red-500',
  };
  return colors[level] || colors.error;
};

const handleAction = (action: any) => {
  switch (action.action) {
    case 'retry':
      emit('retry');
      break;
    case 'update-credentials':
    case 'adjust-settings':
    case 'network-settings':
      if (action.url) {
        router.visit(action.url);
      }
      break;
    case 'test-connection':
    case 'test-auth':
      // Implement test functionality
      console.log('Test action:', action.action);
      break;
    case 'view-logs':
      if (props.error.sync_history_id) {
        viewSyncDetails();
      }
      break;
    case 'get-help':
      // Open help documentation or support
      window.open('/docs/jira-sync-troubleshooting', '_blank');
      break;
    default:
      console.log('Unknown action:', action.action);
  }
};

const exportErrorReport = () => {
  const report = {
    timestamp: new Date().toISOString(),
    sync_id: props.error.sync_history_id,
    error_summary: {
      total_errors: props.error.total_errors,
      warning_count: props.error.warning_count,
      progress_percentage: props.error.progress_percentage,
    },
    error_logs: props.error.error_logs,
    suggested_actions: suggestedActions.value.map(a => ({
      title: a.title,
      description: a.description,
    })),
  };
  
  const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `jira-sync-error-report-${props.error.sync_history_id || 'latest'}.json`;
  a.click();
  window.URL.revokeObjectURL(url);
};

const viewSyncDetails = () => {
  if (props.error.sync_history_id) {
    router.visit(route('jira.sync-history.show', { syncHistory: props.error.sync_history_id }));
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