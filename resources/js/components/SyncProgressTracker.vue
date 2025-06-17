<template>
  <div v-if="hasActiveSyncs || showCompleted" class="space-y-4">
    <!-- Active Sync Progress -->
    <Card v-for="sync in displaySyncs" :key="sync.sync_history_id" class="border-l-4" 
          :class="getStatusBorderClass(sync.status)">
      <CardHeader class="pb-3">
        <div class="flex items-center justify-between">
          <div>
            <CardTitle class="text-base flex items-center gap-2">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full" :class="getStatusIndicatorClass(sync.status)"></div>
                Sync #{{ sync.sync_history_id }}
                <Badge :variant="getStatusVariant(sync.status)" class="text-xs">
                  {{ sync.status }}
                </Badge>
              </div>
            </CardTitle>
            <CardDescription class="text-sm">
              {{ sync.progress_data.current_operation || 'Processing...' }}
            </CardDescription>
          </div>
          <div class="text-right">
            <div class="text-lg font-semibold">{{ Math.round(sync.progress_percentage) }}%</div>
            <div class="text-xs text-muted-foreground">
              {{ sync.formatted_duration }}
            </div>
          </div>
        </div>
      </CardHeader>
      
      <CardContent class="space-y-4">
        <!-- Overall Progress Bar -->
        <div class="space-y-2">
          <div class="flex justify-between text-sm">
            <span>Overall Progress</span>
            <span>{{ Math.round(sync.progress_percentage) }}%</span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2">
            <div 
              class="h-2 rounded-full transition-all duration-300"
              :class="getProgressBarClass(sync.status)"
              :style="{ width: sync.progress_percentage + '%' }"
            ></div>
          </div>
        </div>

        <!-- Detailed Progress -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <!-- Projects -->
          <div class="space-y-1">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Projects</span>
              <span class="font-medium">{{ sync.processed.projects }}/{{ sync.totals.projects }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1">
              <div 
                class="bg-blue-500 h-1 rounded-full transition-all duration-300"
                :style="{ width: sync.project_progress_percentage + '%' }"
              ></div>
            </div>
          </div>

          <!-- Issues -->
          <div class="space-y-1">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Issues</span>
              <span class="font-medium">{{ sync.processed.issues }}/{{ sync.totals.issues }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1">
              <div 
                class="bg-green-500 h-1 rounded-full transition-all duration-300"
                :style="{ width: sync.issue_progress_percentage + '%' }"
              ></div>
            </div>
          </div>

          <!-- Worklogs -->
          <div class="space-y-1">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Worklogs</span>
              <span class="font-medium">{{ sync.processed.worklogs }}/{{ sync.totals.worklogs }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1">
              <div 
                class="bg-purple-500 h-1 rounded-full transition-all duration-300"
                :style="{ width: sync.worklog_progress_percentage + '%' }"
              ></div>
            </div>
          </div>

          <!-- Users -->
          <div class="space-y-1">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Users</span>
              <span class="font-medium">{{ sync.processed.users }}/{{ sync.totals.users }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1">
              <div 
                class="bg-orange-500 h-1 rounded-full transition-all duration-300"
                :style="{ width: sync.user_progress_percentage + '%' }"
              ></div>
            </div>
          </div>
        </div>

        <!-- Error Indicator -->
        <div v-if="sync.has_errors" class="space-y-2">
          <div class="flex items-center justify-between p-3 bg-red-50 border border-red-200 rounded-lg">
            <div class="flex items-center gap-2">
              <AlertCircle class="w-4 h-4 text-red-500" />
              <span class="text-sm text-red-700 font-medium">
                {{ sync.error_count }} error{{ sync.error_count !== 1 ? 's' : '' }} encountered
              </span>
            </div>
            <div class="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                @click="viewErrorDetails(sync.sync_history_id)"
                class="text-xs text-red-700 border-red-300 hover:bg-red-100"
              >
                <AlertCircle class="w-3 h-3 mr-1" />
                View Details
              </Button>
              <Button
                variant="outline"
                size="sm"
                @click="downloadErrorLog(sync.sync_history_id)"
                class="text-xs text-red-700 border-red-300 hover:bg-red-100"
              >
                <Download class="w-3 h-3 mr-1" />
                Download
              </Button>
            </div>
          </div>

          <!-- Error Details Modal/Expandable -->
          <div v-if="showErrorDetails[sync.sync_history_id]" class="bg-red-50 border border-red-200 rounded-lg p-3">
            <div class="text-xs text-red-800 space-y-2">
              <div class="font-medium">Recent Errors:</div>
              <div v-if="errorDetails[sync.sync_history_id]" class="space-y-1 max-h-32 overflow-y-auto">
                <div v-for="(error, index) in errorDetails[sync.sync_history_id]?.slice(0, 5)" 
                     :key="index" 
                     class="p-2 bg-white rounded border text-xs">
                  <div class="font-medium text-red-700">{{ error.type || 'Unknown Error' }}</div>
                  <div class="text-red-600 mt-1">{{ error.message || 'No message available' }}</div>
                  <div v-if="error.context" class="text-red-500 mt-1">Context: {{ error.context }}</div>
                </div>
                <div v-if="errorDetails[sync.sync_history_id]?.length > 5" class="text-center py-1">
                  <span class="text-red-600">...and {{ errorDetails[sync.sync_history_id].length - 5 }} more errors</span>
                </div>
              </div>
              <div v-else class="text-red-600">Loading error details...</div>
            </div>
          </div>
        </div>

        <!-- Recently Synced Issues -->
        <div v-if="sync.recent_issues && sync.recent_issues.length > 0" class="space-y-2">
          <div class="text-sm font-medium text-muted-foreground">Recently Processed Issues</div>
          <div class="space-y-1 max-h-32 overflow-y-auto">
            <div v-for="issue in sync.recent_issues" :key="issue.key" 
                 class="flex items-center justify-between text-xs p-2 bg-gray-50 rounded">
              <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full" 
                     :class="getIssueOperationClass(issue.operation)"></div>
                <span class="font-mono">{{ issue.key }}</span>
              </div>
              <span class="text-muted-foreground">
                {{ formatRelativeTime(issue.processed_at) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Enhanced Progress Info -->
        <div v-if="sync.detailed_stats" class="grid grid-cols-2 gap-4 text-xs">
          <div v-if="sync.estimated_completion_human" class="text-muted-foreground">
            <span class="font-medium">ETA:</span> {{ sync.estimated_completion_human }}
          </div>
          <div v-if="sync.duration_human" class="text-muted-foreground">
            <span class="font-medium">Duration:</span> {{ sync.duration_human }}
          </div>
        </div>

        <!-- Estimated Completion -->
        <div v-if="sync.is_running && sync.progress_data.estimated_completion" 
             class="text-xs text-muted-foreground">
          Estimated completion: {{ formatEstimatedTime(sync.progress_data.estimated_completion) }}
        </div>

        <!-- Action Buttons -->
        <div v-if="sync.is_running" class="flex gap-2 pt-2">
          <Button 
            variant="outline" 
            size="sm" 
            @click="$emit('cancelSync', sync.sync_history_id)"
          >
            <X class="w-3 h-3 mr-1" />
            Cancel
          </Button>
          <Button 
            variant="outline" 
            size="sm" 
            @click="$emit('refreshProgress')"
          >
            <RefreshCw class="w-3 h-3 mr-1" />
            Refresh
          </Button>
        </div>
      </CardContent>
    </Card>

    <!-- Connection Status -->
    <div v-if="showConnectionStatus" class="flex items-center gap-2 text-xs text-muted-foreground">
      <div class="w-2 h-2 rounded-full" :class="isConnected ? 'bg-green-500' : 'bg-red-500'"></div>
      {{ isConnected ? 'Real-time updates active' : 'Using polling updates' }}
      <span v-if="connectionError">({{ connectionError }})</span>
    </div>
  </div>

  <!-- No Active Syncs -->
  <div v-else-if="!hasActiveSyncs && !loading" class="text-center py-6 text-muted-foreground">
    <Activity class="w-8 h-8 mx-auto mb-2 opacity-50" />
    <p class="text-sm">No active sync operations</p>
  </div>

  <!-- Loading State -->
  <div v-else-if="loading" class="text-center py-6">
    <RefreshCw class="w-6 h-6 mx-auto animate-spin mb-2" />
    <p class="text-sm text-muted-foreground">Loading sync status...</p>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, reactive } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { 
  Activity, 
  AlertCircle, 
  RefreshCw, 
  X,
  Download,
} from 'lucide-vue-next'

interface RecentIssue {
  key: string
  processed_at: string
  operation: string
}

interface SyncProgress {
  sync_history_id: number
  status: string
  progress_percentage: number
  project_progress_percentage: number
  issue_progress_percentage: number
  worklog_progress_percentage: number
  user_progress_percentage: number
  totals: {
    projects: number
    issues: number
    worklogs: number
    users: number
  }
  processed: {
    projects: number
    issues: number
    worklogs: number
    users: number
  }
  error_count: number
  has_errors: boolean
  is_running: boolean
  started_at: string | null
  completed_at: string | null
  formatted_duration: string
  recent_issues?: RecentIssue[]
  detailed_stats?: any
  estimated_completion_human?: string
  duration_human?: string
  progress_data: {
    current_operation?: string
    estimated_completion?: string | null
    elapsed_time?: number
  }
}

interface Props {
  activeSyncs: SyncProgress[]
  isConnected: boolean
  connectionError: string | null
  loading?: boolean
  showCompleted?: boolean
  showConnectionStatus?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  loading: false,
  showCompleted: false,
  showConnectionStatus: true,
})

defineEmits<{
  cancelSync: [syncId: number]
  refreshProgress: []
}>()

// Error handling state
const showErrorDetails = ref<Record<number, boolean>>({})
const errorDetails = reactive<Record<number, any[]>>({})
const loadingErrors = ref<Record<number, boolean>>({})

// Error handling methods
const viewErrorDetails = async (syncId: number) => {
  // Toggle error details visibility
  showErrorDetails.value[syncId] = !showErrorDetails.value[syncId]
  
  // If showing details and we don't have error data, fetch it
  if (showErrorDetails.value[syncId] && !errorDetails[syncId]) {
    await loadErrorDetails(syncId)
  }
}

const loadErrorDetails = async (syncId: number) => {
  if (loadingErrors.value[syncId]) return
  
  loadingErrors.value[syncId] = true
  
  try {
    const response = await fetch(`/admin/jira/sync/${syncId}/errors/details`, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`)
    }
    
    const data = await response.json()
    
    if (data.success && data.data.errors) {
      errorDetails[syncId] = data.data.errors
    } else {
      errorDetails[syncId] = [{
        type: 'Fetch Error',
        message: 'Failed to load error details',
        context: data.error || 'Unknown error'
      }]
    }
  } catch (error) {
    console.error('Failed to load error details:', error)
    errorDetails[syncId] = [{
      type: 'Network Error',
      message: error instanceof Error ? error.message : 'Failed to fetch error details',
      context: 'Check your network connection and try again'
    }]
  } finally {
    loadingErrors.value[syncId] = false
  }
}

const downloadErrorLog = async (syncId: number) => {
  try {
    // Create a link to download the error log
    const link = document.createElement('a')
    link.href = `/admin/jira/sync/${syncId}/errors/download`
    link.download = `jira-sync-${syncId}-errors.txt`
    
    // Add authentication headers via fetch and create blob
    const response = await fetch(`/admin/jira/sync/${syncId}/errors/download`, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    
    if (!response.ok) {
      throw new Error(`Failed to download: ${response.statusText}`)
    }
    
    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    
    link.href = url
    document.body.appendChild(link)
    link.click()
    
    // Cleanup
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
    
  } catch (error) {
    console.error('Failed to download error log:', error)
    alert('Failed to download error log. Please try again.')
  }
}

// Computed properties
const hasActiveSyncs = computed(() => props.activeSyncs.length > 0)

const displaySyncs = computed(() => {
  if (props.showCompleted) {
    return props.activeSyncs
  }
  return props.activeSyncs.filter(sync => sync.is_running)
})

// Methods
const getStatusVariant = (status: string) => {
  switch (status) {
    case 'completed':
      return 'default'
    case 'failed':
      return 'destructive'
    case 'in_progress':
      return 'secondary'
    case 'pending':
      return 'outline'
    default:
      return 'outline'
  }
}

const getStatusBorderClass = (status: string) => {
  switch (status) {
    case 'completed':
      return 'border-l-green-500'
    case 'failed':
      return 'border-l-red-500'
    case 'in_progress':
      return 'border-l-blue-500'
    case 'pending':
      return 'border-l-yellow-500'
    default:
      return 'border-l-gray-500'
  }
}

const getStatusIndicatorClass = (status: string) => {
  switch (status) {
    case 'completed':
      return 'bg-green-500'
    case 'failed':
      return 'bg-red-500'
    case 'in_progress':
      return 'bg-blue-500 animate-pulse'
    case 'pending':
      return 'bg-yellow-500 animate-pulse'
    default:
      return 'bg-gray-500'
  }
}

const getProgressBarClass = (status: string) => {
  switch (status) {
    case 'completed':
      return 'bg-green-500'
    case 'failed':
      return 'bg-red-500'
    case 'in_progress':
      return 'bg-blue-500'
    default:
      return 'bg-gray-500'
  }
}

const formatEstimatedTime = (timeString: string | null) => {
  if (!timeString) return 'Unknown'
  
  try {
    const time = new Date(timeString)
    return time.toLocaleTimeString()
  } catch {
    return 'Unknown'
  }
}

const getIssueOperationClass = (operation: string) => {
  switch (operation) {
    case 'completed':
      return 'bg-green-500'
    case 'worklogs':
      return 'bg-purple-500'
    case 'processing':
      return 'bg-blue-500'
    default:
      return 'bg-gray-500'
  }
}

const formatRelativeTime = (timeString: string) => {
  try {
    const time = new Date(timeString)
    const now = new Date()
    const diffMs = now.getTime() - time.getTime()
    const diffSecs = Math.floor(diffMs / 1000)
    
    if (diffSecs < 60) return `${diffSecs}s ago`
    if (diffSecs < 3600) return `${Math.floor(diffSecs / 60)}m ago`
    return `${Math.floor(diffSecs / 3600)}h ago`
  } catch {
    return 'Unknown'
  }
}
</script>