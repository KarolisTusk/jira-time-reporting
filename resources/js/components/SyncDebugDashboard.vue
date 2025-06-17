<template>
  <div class="bg-white shadow-sm rounded-lg border">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <div>
          <h3 class="text-lg font-medium text-gray-900 flex items-center">
            <Bug class="w-5 h-5 mr-2 text-orange-500" />
            Sync Debug Dashboard
          </h3>
          <p class="mt-1 text-sm text-gray-500">
            Monitor, debug, and troubleshoot sync processes
          </p>
        </div>
        <div class="flex items-center space-x-2">
          <Button 
            variant="outline" 
            size="sm"
            @click="refreshData"
            :disabled="isRefreshing"
          >
            <RefreshCw class="w-4 h-4 mr-2" :class="{ 'animate-spin': isRefreshing }" />
            Refresh
          </Button>
          <Button 
            variant="outline" 
            size="sm"
            @click="showAdvancedTools = !showAdvancedTools"
          >
            <Settings class="w-4 h-4 mr-2" />
            Tools
          </Button>
        </div>
      </div>
    </div>

    <!-- Status Overview -->
    <div class="p-6 border-b border-gray-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 p-4 rounded-lg">
          <div class="flex items-center">
            <Activity class="w-6 h-6 text-blue-600" />
            <div class="ml-3">
              <p class="text-sm font-medium text-blue-600">Active Syncs</p>
              <p class="text-2xl font-bold text-blue-900">{{ activeCount }}</p>
            </div>
          </div>
        </div>
        
        <div class="bg-red-50 p-4 rounded-lg">
          <div class="flex items-center">
            <AlertTriangle class="w-6 h-6 text-red-600" />
            <div class="ml-3">
              <p class="text-sm font-medium text-red-600">Failed Syncs</p>
              <p class="text-2xl font-bold text-red-900">{{ failedCount }}</p>
            </div>
          </div>
        </div>
        
        <div class="bg-yellow-50 p-4 rounded-lg">
          <div class="flex items-center">
            <Clock class="w-6 h-6 text-yellow-600" />
            <div class="ml-3">
              <p class="text-sm font-medium text-yellow-600">Stuck Syncs</p>
              <p class="text-2xl font-bold text-yellow-900">{{ stuckCount }}</p>
            </div>
          </div>
        </div>
        
        <div class="bg-green-50 p-4 rounded-lg">
          <div class="flex items-center">
            <CheckCircle class="w-6 h-6 text-green-600" />
            <div class="ml-3">
              <p class="text-sm font-medium text-green-600">Completed Today</p>
              <p class="text-2xl font-bold text-green-900">{{ completedTodayCount }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Advanced Tools (collapsible) -->
    <div v-if="showAdvancedTools" class="p-6 border-b border-gray-200 bg-gray-50">
      <h4 class="text-sm font-medium text-gray-900 mb-4">Advanced Tools</h4>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Button
          variant="outline"
          @click="cleanupStuckSyncs"
          :disabled="isProcessing"
          class="justify-start"
        >
          <Trash2 class="w-4 h-4 mr-2" />
          Cleanup Stuck Syncs
        </Button>
        
        <Button
          variant="outline"
          @click="recoverFailedSyncs"
          :disabled="isProcessing"
          class="justify-start"
        >
          <RotateCcw class="w-4 h-4 mr-2" />
          Recover Failed Syncs
        </Button>
        
        <Button
          variant="outline"
          @click="runDiagnostics"
          :disabled="isProcessing"
          class="justify-start"
        >
          <Zap class="w-4 h-4 mr-2" />
          Run Diagnostics
        </Button>
      </div>
    </div>

    <!-- Sync List -->
    <div class="p-6">
      <div class="flex items-center justify-between mb-4">
        <h4 class="text-sm font-medium text-gray-900">Recent Sync Processes</h4>
        <div class="flex items-center space-x-2">
          <select 
            v-model="statusFilter" 
            class="text-sm border-gray-300 rounded-md"
            @change="filterSyncs"
          >
            <option value="all">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="failed">Failed</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>

      <div class="space-y-4">
        <div 
          v-for="sync in filteredSyncs" 
          :key="sync.id"
          class="border rounded-lg p-4"
          :class="getSyncBorderClass(sync)"
        >
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <!-- Sync Header -->
              <div class="flex items-center space-x-3 mb-2">
                <span class="text-lg font-medium">
                  Sync #{{ sync.id }}
                </span>
                <Badge :variant="getStatusVariant(sync.status)">
                  {{ sync.status }}
                </Badge>
                <span v-if="isStuck(sync)" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                  <AlertTriangle class="w-3 h-3 mr-1" />
                  STUCK
                </span>
              </div>

              <!-- Progress Bar -->
              <div class="mb-3">
                <div class="flex justify-between text-sm text-gray-600 mb-1">
                  <span>{{ sync.current_operation || 'No operation specified' }}</span>
                  <span>{{ sync.progress_percentage }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                  <div 
                    class="h-2 rounded-full transition-all duration-300"
                    :class="getProgressBarClass(sync.status)"
                    :style="{ width: `${sync.progress_percentage}%` }"
                  ></div>
                </div>
              </div>

              <!-- Sync Details -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                  <span class="text-gray-500">Started:</span>
                  <span class="ml-2">{{ formatDate(sync.started_at) }}</span>
                </div>
                <div>
                  <span class="text-gray-500">Duration:</span>
                  <span class="ml-2">{{ getDuration(sync) }}</span>
                </div>
                <div>
                  <span class="text-gray-500">Projects:</span>
                  <span class="ml-2">{{ sync.processed_projects }}/{{ sync.total_projects }}</span>
                </div>
                <div v-if="sync.error_count > 0">
                  <span class="text-gray-500">Errors:</span>
                  <span class="ml-2 text-red-600 font-medium">{{ sync.error_count }}</span>
                </div>
              </div>

              <!-- Error Summary -->
              <div v-if="sync.error_summary" class="mt-3 p-3 bg-red-50 border border-red-200 rounded-md">
                <div class="flex items-start">
                  <AlertTriangle class="w-4 h-4 text-red-500 mt-0.5 mr-2" />
                  <div class="flex-1">
                    <p class="text-sm font-medium text-red-800">Recent Error</p>
                    <p class="text-sm text-red-700 mt-1">{{ sync.error_summary.message }}</p>
                    <p class="text-xs text-red-600 mt-1">
                      Category: {{ sync.error_summary.category }} | 
                      Severity: {{ sync.error_summary.severity }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Detailed View (expandable) -->
              <div v-if="expandedSync === sync.id" class="mt-4 border-t pt-4">
                <SyncDetailView :sync="sync" @close="expandedSync = null" />
              </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-col space-y-2 ml-4">
              <Button
                variant="outline"
                size="sm"
                @click="toggleExpanded(sync.id)"
              >
                <Eye class="w-3 h-3 mr-1" />
                {{ expandedSync === sync.id ? 'Hide' : 'Details' }}
              </Button>
              
              <Button
                v-if="sync.status === 'failed'"
                variant="outline"
                size="sm"
                @click="retrySync(sync.id)"
                :disabled="isProcessing"
              >
                <RotateCcw class="w-3 h-3 mr-1" />
                Retry
              </Button>
              
              <Button
                v-if="['pending', 'in_progress'].includes(sync.status)"
                variant="destructive"
                size="sm"
                @click="cancelSync(sync.id)"
                :disabled="isProcessing"
              >
                <X class="w-3 h-3 mr-1" />
                Cancel
              </Button>
              
              <Button
                v-if="isStuck(sync)"
                variant="outline"
                size="sm"
                @click="recoverSync(sync.id)"
                :disabled="isProcessing"
              >
                <Wrench class="w-3 h-3 mr-1" />
                Recover
              </Button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="filteredSyncs.length === 0" class="text-center py-8 text-gray-500">
        <Activity class="w-12 h-12 mx-auto mb-4 text-gray-300" />
        <p>No sync processes found matching the current filter.</p>
      </div>
    </div>

    <!-- Diagnostics Modal -->
    <Dialog v-model:open="showDiagnostics">
      <DialogContent class="max-w-4xl max-h-[80vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>System Diagnostics</DialogTitle>
          <DialogDescription>
            Comprehensive system health check and troubleshooting information
          </DialogDescription>
        </DialogHeader>
        
        <div v-if="diagnosticsResults" class="space-y-6">
          <div class="grid grid-cols-2 gap-4">
            <div class="text-center p-4 bg-green-50 rounded-lg">
              <CheckCircle class="w-8 h-8 mx-auto mb-2 text-green-600" />
              <p class="text-sm font-medium text-green-800">Tests Passed</p>
              <p class="text-2xl font-bold text-green-900">{{ diagnosticsResults.passed }}</p>
            </div>
            <div class="text-center p-4 bg-red-50 rounded-lg">
              <AlertTriangle class="w-8 h-8 mx-auto mb-2 text-red-600" />
              <p class="text-sm font-medium text-red-800">Tests Failed</p>
              <p class="text-2xl font-bold text-red-900">{{ diagnosticsResults.failed }}</p>
            </div>
          </div>

          <div class="space-y-3">
            <div 
              v-for="(result, test) in diagnosticsResults.tests" 
              :key="test"
              class="flex items-start space-x-3 p-3 rounded-lg"
              :class="result.status === 'pass' ? 'bg-green-50' : 'bg-red-50'"
            >
              <CheckCircle v-if="result.status === 'pass'" class="w-5 h-5 text-green-600 mt-0.5" />
              <X v-else class="w-5 h-5 text-red-600 mt-0.5" />
              <div>
                <p class="font-medium" :class="result.status === 'pass' ? 'text-green-800' : 'text-red-800'">
                  {{ test }}
                </p>
                <p class="text-sm" :class="result.status === 'pass' ? 'text-green-600' : 'text-red-600'">
                  {{ result.message }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" @click="showDiagnostics = false">
            Close
          </Button>
          <Button @click="runDiagnostics" :disabled="isProcessing">
            <RefreshCw class="w-4 h-4 mr-2" :class="{ 'animate-spin': isProcessing }" />
            Run Again
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  Activity,
  AlertTriangle,
  Bug,
  CheckCircle,
  Clock,
  Eye,
  RefreshCw,
  RotateCcw,
  Settings,
  Trash2,
  Wrench,
  X,
  Zap,
} from 'lucide-vue-next'

interface SyncProcess {
  id: number
  status: string
  progress_percentage: number
  current_operation: string
  started_at: string
  completed_at?: string
  total_projects: number
  processed_projects: number
  total_issues: number
  processed_issues: number
  error_count: number
  error_summary?: {
    message: string
    category: string
    severity: string
  }
}

interface DiagnosticsResult {
  passed: number
  failed: number
  tests: Record<string, { status: string; message: string }>
}

// Props
interface Props {
  autoRefresh?: boolean
  refreshInterval?: number
}

const props = withDefaults(defineProps<Props>(), {
  autoRefresh: true,
  refreshInterval: 30000,
})

// Reactive state
const syncs = ref<SyncProcess[]>([])
const isRefreshing = ref(false)
const isProcessing = ref(false)
const showAdvancedTools = ref(false)
const statusFilter = ref('all')
const expandedSync = ref<number | null>(null)
const showDiagnostics = ref(false)
const diagnosticsResults = ref<DiagnosticsResult | null>(null)

let refreshTimer: number | null = null

// Computed properties
const activeCount = computed(() => 
  syncs.value.filter(sync => ['pending', 'in_progress'].includes(sync.status)).length
)

const failedCount = computed(() => 
  syncs.value.filter(sync => sync.status === 'failed').length
)

const stuckCount = computed(() => 
  syncs.value.filter(sync => isStuck(sync)).length
)

const completedTodayCount = computed(() => {
  const today = new Date().toDateString()
  return syncs.value.filter(sync => 
    sync.status === 'completed' && 
    sync.completed_at && 
    new Date(sync.completed_at).toDateString() === today
  ).length
})

const filteredSyncs = computed(() => {
  if (statusFilter.value === 'all') {
    return syncs.value
  }
  return syncs.value.filter(sync => sync.status === statusFilter.value)
})

// Methods
const refreshData = async () => {
  if (isRefreshing.value) return
  
  isRefreshing.value = true
  try {
    const response = await fetch('/admin/jira/sync/debug', {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    
    if (response.ok) {
      const data = await response.json()
      syncs.value = data.syncs || []
    }
  } catch (error) {
    console.error('Failed to refresh sync data:', error)
  } finally {
    isRefreshing.value = false
  }
}

const cleanupStuckSyncs = async () => {
  if (!confirm('This will mark stuck syncs as failed. Continue?')) return
  
  isProcessing.value = true
  try {
    const response = await fetch('/admin/jira/sync/cleanup', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      await refreshData()
      alert('Stuck syncs cleaned up successfully')
    }
  } catch (error) {
    console.error('Failed to cleanup syncs:', error)
    alert('Failed to cleanup syncs')
  } finally {
    isProcessing.value = false
  }
}

const recoverFailedSyncs = async () => {
  if (!confirm('This will attempt to restart failed syncs. Continue?')) return
  
  isProcessing.value = true
  try {
    const response = await fetch('/admin/jira/sync/recover', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      await refreshData()
      alert('Recovery initiated for failed syncs')
    }
  } catch (error) {
    console.error('Failed to recover syncs:', error)
    alert('Failed to recover syncs')
  } finally {
    isProcessing.value = false
  }
}

const runDiagnostics = async () => {
  isProcessing.value = true
  showDiagnostics.value = true
  diagnosticsResults.value = null
  
  try {
    const response = await fetch('/admin/jira/sync/diagnostics', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      const data = await response.json()
      diagnosticsResults.value = data
    }
  } catch (error) {
    console.error('Failed to run diagnostics:', error)
  } finally {
    isProcessing.value = false
  }
}

const retrySync = async (syncId: number) => {
  isProcessing.value = true
  try {
    const response = await fetch(`/admin/jira/sync/${syncId}/retry`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      await refreshData()
      alert('Sync retry initiated')
    }
  } catch (error) {
    console.error('Failed to retry sync:', error)
    alert('Failed to retry sync')
  } finally {
    isProcessing.value = false
  }
}

const cancelSync = async (syncId: number) => {
  if (!confirm('Are you sure you want to cancel this sync?')) return
  
  isProcessing.value = true
  try {
    const response = await fetch(`/admin/jira/sync/${syncId}/cancel`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      await refreshData()
      alert('Sync cancelled')
    }
  } catch (error) {
    console.error('Failed to cancel sync:', error)
    alert('Failed to cancel sync')
  } finally {
    isProcessing.value = false
  }
}

const recoverSync = async (syncId: number) => {
  isProcessing.value = true
  try {
    const response = await fetch(`/admin/jira/sync/${syncId}/recover`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
    })
    
    if (response.ok) {
      await refreshData()
      alert('Sync recovery initiated')
    }
  } catch (error) {
    console.error('Failed to recover sync:', error)
    alert('Failed to recover sync')
  } finally {
    isProcessing.value = false
  }
}

const toggleExpanded = (syncId: number) => {
  expandedSync.value = expandedSync.value === syncId ? null : syncId
}

const isStuck = (sync: SyncProcess): boolean => {
  if (!['pending', 'in_progress'].includes(sync.status)) return false
  
  const stuckThreshold = Date.now() - (10 * 60 * 1000) // 10 minutes
  const lastUpdate = new Date(sync.started_at).getTime()
  
  return lastUpdate < stuckThreshold
}

const getSyncBorderClass = (sync: SyncProcess): string => {
  if (isStuck(sync)) return 'border-red-300 bg-red-50'
  if (sync.status === 'failed') return 'border-red-200'
  if (sync.status === 'in_progress') return 'border-blue-200'
  if (sync.status === 'completed') return 'border-green-200'
  return 'border-gray-200'
}

const getStatusVariant = (status: string) => {
  switch (status) {
    case 'completed': return 'default'
    case 'failed': return 'destructive'
    case 'in_progress': return 'secondary'
    case 'pending': return 'outline'
    default: return 'outline'
  }
}

const getProgressBarClass = (status: string): string => {
  switch (status) {
    case 'completed': return 'bg-green-500'
    case 'failed': return 'bg-red-500'
    case 'in_progress': return 'bg-blue-500'
    default: return 'bg-gray-500'
  }
}

const formatDate = (dateString: string): string => {
  return new Date(dateString).toLocaleString()
}

const getDuration = (sync: SyncProcess): string => {
  const start = new Date(sync.started_at)
  const end = sync.completed_at ? new Date(sync.completed_at) : new Date()
  const diff = end.getTime() - start.getTime()
  
  const minutes = Math.floor(diff / 60000)
  const seconds = Math.floor((diff % 60000) / 1000)
  
  if (minutes > 0) {
    return `${minutes}m ${seconds}s`
  }
  return `${seconds}s`
}

const filterSyncs = () => {
  // Trigger reactivity for filtered syncs
}

// Lifecycle
onMounted(() => {
  refreshData()
  
  if (props.autoRefresh) {
    refreshTimer = window.setInterval(refreshData, props.refreshInterval)
  }
})

onUnmounted(() => {
  if (refreshTimer) {
    clearInterval(refreshTimer)
  }
})
</script> 