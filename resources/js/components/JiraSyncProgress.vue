<template>
  <div class="space-y-4">
    <!-- Main Progress Card -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm p-6">
      <!-- Header -->
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center space-x-3">
          <div class="flex items-center space-x-2">
            <LoaderIcon v-if="hasActiveSync" class="w-4 h-4 animate-spin text-blue-600" />
            <CheckCircleIcon v-else-if="isCompleted" class="w-4 h-4 text-green-600" />
            <XCircleIcon v-else-if="isFailed" class="w-4 h-4 text-red-600" />
            <ClockIcon v-else class="w-4 h-4 text-gray-400" />
            
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              JIRA Data Sync
            </h3>
          </div>
          
          <div class="flex items-center space-x-2">
            <span v-if="hasActiveSync" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
              In Progress
            </span>
            <span v-else-if="isCompleted" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
              Completed
            </span>
            <span v-else-if="isFailed" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
              Failed
            </span>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-2">
          <Button 
            v-if="hasActiveSync && syncProgress?.sync_history_id" 
            variant="outline" 
            size="sm"
            @click="handleCancel"
            :disabled="isProcessing"
          >
            <XIcon class="w-4 h-4 mr-1" />
            Cancel
          </Button>
          
          <Button 
            v-if="isFailed" 
            variant="outline" 
            size="sm"
            @click="handleRetry"
            :disabled="isProcessing"
          >
            <RefreshCwIcon class="w-4 h-4 mr-1" />
            Retry
          </Button>
          
          <Button 
            variant="outline" 
            size="sm"
            @click="$emit('close')"
          >
            <EyeOffIcon class="w-4 h-4 mr-1" />
            Hide
          </Button>
        </div>
      </div>

      <!-- Error Display -->
      <div v-if="error" class="mb-4 p-3 bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-md">
        <div class="flex">
          <XCircleIcon class="h-5 w-5 text-red-400" />
          <div class="ml-3">
            <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
              Error
            </h3>
            <div class="mt-2 text-sm text-red-700 dark:text-red-300">
              {{ error }}
            </div>
          </div>
        </div>
      </div>

      <!-- Current Operation -->
      <div v-if="hasActiveSync" class="mb-4">
        <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Current Operation</div>
        <div class="text-base font-medium text-gray-900 dark:text-white">{{ currentOperation }}</div>
      </div>

      <!-- Main Progress Bar -->
      <div class="mb-6">
        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
          <span>Overall Progress</span>
          <span>{{ Math.round(progressPercentage) }}%</span>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
          <div 
            class="h-2 rounded-full transition-all duration-300 ease-in-out"
            :class="{
              'bg-blue-600': hasActiveSync,
              'bg-green-600': isCompleted,
              'bg-red-600': isFailed
            }"
            :style="{ width: `${progressPercentage}%` }"
          ></div>
        </div>
      </div>

      <!-- Detailed Progress -->
      <div v-if="syncProgress && progressDetails.length > 0" class="space-y-3">
        <h4 class="text-sm font-medium text-gray-900 dark:text-white">Progress Details</h4>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div v-for="detail in progressDetails" :key="detail.label" class="space-y-2">
            <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400">
              <span>{{ detail.label }}</span>
              <span>{{ detail.processed }} / {{ detail.total }}</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
              <div 
                class="h-1.5 bg-blue-500 rounded-full transition-all duration-300 ease-in-out"
                :style="{ width: `${detail.percentage}%` }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Time Information -->
      <div v-if="syncProgress" class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
          <div v-if="syncProgress.started_at">
            <div class="text-gray-600 dark:text-gray-400">Started</div>
            <div class="font-medium text-gray-900 dark:text-white">
              {{ formatDateTime(syncProgress.started_at) }}
            </div>
          </div>
          
          <div v-if="timeRemaining && hasActiveSync">
            <div class="text-gray-600 dark:text-gray-400">Time Remaining</div>
            <div class="font-medium text-gray-900 dark:text-white">
              {{ timeRemaining }}
            </div>
          </div>
          
          <div v-if="syncProgress.formatted_duration">
            <div class="text-gray-600 dark:text-gray-400">Duration</div>
            <div class="font-medium text-gray-900 dark:text-white">
              {{ syncProgress.formatted_duration }}
            </div>
          </div>
        </div>

        <!-- Error Count -->
        <div v-if="syncProgress.error_count > 0" class="mt-4">
          <div class="flex items-center space-x-2">
            <AlertTriangleIcon class="w-4 h-4 text-amber-500" />
            <span class="text-sm text-amber-700 dark:text-amber-300">
              {{ syncProgress.error_count }} error{{ syncProgress.error_count > 1 ? 's' : '' }} encountered
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Completion Message -->
    <div v-if="isCompleted" class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 rounded-lg p-4">
      <div class="flex">
        <CheckCircleIcon class="h-5 w-5 text-green-400" />
        <div class="ml-3">
          <h3 class="text-sm font-medium text-green-800 dark:text-green-200">
            Sync Completed Successfully
          </h3>
          <div class="mt-2 text-sm text-green-700 dark:text-green-300">
            All JIRA data has been synchronized successfully. 
            <span v-if="syncProgress?.formatted_duration">
              Total time: {{ syncProgress.formatted_duration }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Failure Message -->
    <div v-if="isFailed" class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-lg p-4">
      <div class="flex">
        <XCircleIcon class="h-5 w-5 text-red-400" />
        <div class="ml-3">
          <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
            Sync Failed
          </h3>
          <div class="mt-2 text-sm text-red-700 dark:text-red-300">
            The sync operation encountered errors and could not complete. 
            You can retry the operation or check the sync history for details.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
  LoaderIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  XIcon,
  RefreshCwIcon,
  EyeOffIcon,
  AlertTriangleIcon,
} from 'lucide-vue-next';
import { useJiraSyncProgress, type SyncProgress } from '../composables/useJiraSyncProgress';

interface Props {
  userId?: number;
  initialSyncProgress?: SyncProgress | null;
  autoStart?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  autoStart: true,
});

const emit = defineEmits<{
  close: [];
  syncStarted: [syncId: number];
  syncCompleted: [syncId: number];
  syncFailed: [syncId: number];
  syncCancelled: [syncId: number];
}>();

const isProcessing = ref(false);

// Use the sync progress composable
const {
  syncProgress,
  error,
  hasActiveSync,
  isCompleted,
  isFailed,
  progressPercentage,
  currentOperation,
  timeRemaining,
  progressDetails,
  startListening,
  stopListening,
  fetchSyncStatus,
  cancelSync,
  retrySync,
  clearProgress,
} = useJiraSyncProgress(props.userId);

// Set initial sync progress if provided
if (props.initialSyncProgress) {
  syncProgress.value = props.initialSyncProgress;
}

// Auto-start listening if enabled and userId is provided
if (props.autoStart && props.userId) {
  startListening(props.userId);
}

// Handle cancel action
const handleCancel = async () => {
  isProcessing.value = true;
  try {
    const success = await cancelSync();
    if (success && syncProgress.value) {
      emit('syncCancelled', syncProgress.value.sync_history_id);
    }
  } finally {
    isProcessing.value = false;
  }
};

// Handle retry action
const handleRetry = async () => {
  isProcessing.value = true;
  try {
    const success = await retrySync();
    if (success && syncProgress.value) {
      emit('syncStarted', syncProgress.value.sync_history_id);
    }
  } finally {
    isProcessing.value = false;
  }
};

// Format date time for display
const formatDateTime = (dateString: string) => {
  try {
    const date = new Date(dateString);
    return date.toLocaleString();
  } catch {
    return dateString;
  }
};

// Watch for sync status changes to emit events
const lastStatus = ref(syncProgress.value?.status);
const lastSyncId = ref(syncProgress.value?.sync_history_id);

watch(syncProgress, (newValue) => {
  if (!newValue) return;

  const currentStatus = newValue.status;
  const currentSyncId = newValue.sync_history_id;

  // Emit events when status changes
  if (lastStatus.value !== currentStatus || lastSyncId.value !== currentSyncId) {
    if (currentStatus === 'completed' && lastStatus.value !== 'completed') {
      emit('syncCompleted', currentSyncId);
    } else if (currentStatus === 'failed' && lastStatus.value !== 'failed') {
      emit('syncFailed', currentSyncId);
    }

    lastStatus.value = currentStatus;
    lastSyncId.value = currentSyncId;
  }
}, { deep: true });

// Expose methods for parent components
defineExpose({
  startListening,
  stopListening,
  fetchSyncStatus,
  cancelSync: handleCancel,
  retrySync: handleRetry,
  clearProgress,
  syncProgress,
  hasActiveSync,
  isCompleted,
  isFailed,
});
</script> 