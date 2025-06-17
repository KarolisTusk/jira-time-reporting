import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

interface SyncConfig {
  selectedProjects: string[]
  dateRange: {
    type: string
    custom: {
      start: string
      end: string
    }
  }
  options: {
    onlyIssuesWithWorklogs: boolean
    reclassifyResources: boolean
    validateData: boolean
    cleanupOrphaned: boolean
    issueBatchSize: string
    rateLimit: string
    maxRetryAttempts: string
  }
}

interface SyncHistory {
  id: number
  status: string
  progress_percentage: number
  current_operation: string
  started_at: string
  estimated_completion: string | null
  total_projects: number
  processed_projects: number
  total_issues: number
  processed_issues: number
  total_worklogs: number
  processed_worklogs: number
  error_count: number
  is_stale: boolean
}

export function useEnhancedJiraSync() {
  // Reactive state
  const syncConfig = ref<SyncConfig>({
    selectedProjects: [],
    dateRange: {
      type: 'incremental',
      custom: {
        start: '',
        end: ''
      }
    },
    options: {
      onlyIssuesWithWorklogs: false,
      reclassifyResources: false,
      validateData: true,
      cleanupOrphaned: false,
      issueBatchSize: '50',
      rateLimit: '300',
      maxRetryAttempts: '3'
    }
  })

  const isSyncing = ref(false)
  const currentSync = ref<SyncHistory | null>(null)
  const syncProgressInterval = ref<number | null>(null)

  // Computed properties
  const canStartSync = computed(() => 
    syncConfig.value.selectedProjects.length > 0 && !isSyncing.value
  )

  const syncProgress = computed(() => {
    if (!currentSync.value) return 0
    return currentSync.value.progress_percentage
  })

  const estimatedTimeRemaining = computed(() => {
    if (!currentSync.value?.estimated_completion) return null
    const now = new Date()
    const completion = new Date(currentSync.value.estimated_completion)
    const diffMs = completion.getTime() - now.getTime()
    
    if (diffMs <= 0) return null
    
    const minutes = Math.ceil(diffMs / (1000 * 60))
    if (minutes < 60) return `${minutes}m`
    
    const hours = Math.floor(minutes / 60)
    const remainingMinutes = minutes % 60
    return `${hours}h ${remainingMinutes}m`
  })

  // Methods
  const startSync = async (config?: Partial<SyncConfig>) => {
    try {
      isSyncing.value = true
      
      // Merge provided config with current syncConfig
      const finalConfig = config ? { ...syncConfig.value, ...config } : syncConfig.value
      
      // Build request payload
      const payload = {
        project_keys: finalConfig.selectedProjects,
        sync_type: finalConfig.dateRange.type,
        date_range: finalConfig.dateRange.type === 'custom' ? finalConfig.dateRange.custom : null,
        only_issues_with_worklogs: finalConfig.options.onlyIssuesWithWorklogs,
        reclassify_resources: finalConfig.options.reclassifyResources,
        validate_data: finalConfig.options.validateData,
        cleanup_orphaned: finalConfig.options.cleanupOrphaned,
        batch_config: {
          issue_batch_size: parseInt(finalConfig.options.issueBatchSize),
          rate_limit: parseInt(finalConfig.options.rateLimit),
          max_retry_attempts: parseInt(finalConfig.options.maxRetryAttempts)
        }
      }

      // Start the sync
      await router.post('/admin/jira/sync/start', payload, {
        preserveState: true,
        onSuccess: () => {
          // Start polling for progress
          startProgressPolling()
        },
        onError: () => {
          isSyncing.value = false
        }
      })

    } catch (error) {
      isSyncing.value = false
      throw error
    }
  }

  const cancelSync = async (syncId?: number) => {
    try {
      const payload = syncId ? { sync_id: syncId } : {}
      
      await router.post('/admin/jira/sync/cancel', payload, {
        preserveState: true,
        onSuccess: () => {
          isSyncing.value = false
          currentSync.value = null
          stopProgressPolling()
        }
      })

    } catch (error) {
      throw error
    }
  }

  const startProgressPolling = () => {
    if (syncProgressInterval.value) {
      clearInterval(syncProgressInterval.value)
    }

    syncProgressInterval.value = window.setInterval(async () => {
      try {
        const response = await fetch('/admin/jira/sync/progress')
        const data = await response.json()

        if (data.success && data.data.length > 0) {
          const activeSyncs = data.data.filter((sync: SyncHistory) => 
            ['pending', 'in_progress'].includes(sync.status)
          )

          if (activeSyncs.length > 0) {
            currentSync.value = activeSyncs[0]
            
            // Check if sync is stale
            if (currentSync.value.is_stale) {
              console.warn('Sync appears to be stale')
            }
          } else {
            // No active syncs, stop polling
            isSyncing.value = false
            currentSync.value = null
            stopProgressPolling()
          }
        } else {
          // No active syncs
          isSyncing.value = false
          currentSync.value = null
          stopProgressPolling()
        }

      } catch (error) {
        console.error('Failed to fetch sync progress:', error)
      }
    }, 2000) // Poll every 2 seconds
  }

  const stopProgressPolling = () => {
    if (syncProgressInterval.value) {
      clearInterval(syncProgressInterval.value)
      syncProgressInterval.value = null
    }
  }

  const retrySync = async (syncId: number) => {
    try {
      await router.post(`/admin/jira/sync/${syncId}/retry`, {}, {
        preserveState: true,
        onSuccess: () => {
          isSyncing.value = true
          startProgressPolling()
        }
      })
    } catch (error) {
      throw error
    }
  }

  const getSyncDetails = async (syncId: number) => {
    try {
      const response = await fetch(`/admin/jira/sync/${syncId}/details`)
      const data = await response.json()
      return data
    } catch (error) {
      throw error
    }
  }

  const testConnection = async () => {
    try {
      const response = await fetch('/admin/jira/test-connection', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        }
      })
      const data = await response.json()
      return data
    } catch (error) {
      throw error
    }
  }

  const getMetrics = async (filters?: {
    project_key?: string
    date_from?: string
    date_to?: string
  }) => {
    try {
      const params = new URLSearchParams()
      if (filters?.project_key) params.append('project_key', filters.project_key)
      if (filters?.date_from) params.append('date_from', filters.date_from)
      if (filters?.date_to) params.append('date_to', filters.date_to)

      const response = await fetch(`/admin/jira/metrics?${params.toString()}`)
      const data = await response.json()
      return data
    } catch (error) {
      throw error
    }
  }

  const validateData = async (config: {
    project_keys?: string[]
    validation_types: string[]
  }) => {
    try {
      const response = await fetch('/admin/jira/validate-data', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(config)
      })
      const data = await response.json()
      return data
    } catch (error) {
      throw error
    }
  }

  const reclassifyResources = async (config: {
    project_keys?: string[]
    force_reclassify?: boolean
  }) => {
    try {
      const response = await fetch('/admin/jira/reclassify-resources', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(config)
      })
      const data = await response.json()
      return data
    } catch (error) {
      throw error
    }
  }

  // Cleanup on unmount
  const cleanup = () => {
    stopProgressPolling()
  }

  return {
    // State
    syncConfig,
    isSyncing,
    currentSync,
    
    // Computed
    canStartSync,
    syncProgress,
    estimatedTimeRemaining,
    
    // Methods
    startSync,
    cancelSync,
    retrySync,
    getSyncDetails,
    testConnection,
    getMetrics,
    validateData,
    reclassifyResources,
    startProgressPolling,
    stopProgressPolling,
    cleanup
  }
}