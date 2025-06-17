import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'

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
  progress_data: {
    current_operation?: string
    estimated_completion?: string | null
    elapsed_time?: number
  }
}

export function useSyncProgress(userId?: number) {
  const activeSyncs = ref<SyncProgress[]>([])
  const isConnected = ref(false)
  const connectionError = ref<string | null>(null)
  let echo: any = null
  let progressInterval: number | null = null

  // Computed properties
  const hasActiveSyncs = computed(() => activeSyncs.value.length > 0)
  const currentSync = computed(() => activeSyncs.value[0] || null)
  const overallProgress = computed(() => {
    if (!currentSync.value) return 0
    return currentSync.value.progress_percentage
  })

  // Methods
  const connectToProgress = () => {
    try {
      // Check if Laravel Echo is available
      if (typeof window !== 'undefined' && (window as any).Echo) {
        echo = (window as any).Echo
        
        // Listen to private channel for sync progress
        const channel = echo.private(`jira-sync.${userId}`)
        
        channel.listen('.jira.sync.progress', (data: SyncProgress) => {
          updateSyncProgress(data)
        })
        
        channel.subscribed(() => {
          isConnected.value = true
          connectionError.value = null
        })
        
        channel.error((error: any) => {
          isConnected.value = false
          connectionError.value = 'WebSocket connection failed'
          console.error('Echo channel error:', error)
        })
      } else {
        // Fallback to polling if Echo is not available
        startPolling()
      }
    } catch (error) {
      console.error('Failed to connect to sync progress:', error)
      connectionError.value = 'Failed to establish real-time connection'
      startPolling()
    }
  }

  const startPolling = () => {
    // Fallback: poll for progress every 2 seconds
    progressInterval = setInterval(async () => {
      await fetchSyncProgress()
    }, 2000)
  }

  const stopPolling = () => {
    if (progressInterval) {
      clearInterval(progressInterval)
      progressInterval = null
    }
  }

  const fetchSyncProgress = async () => {
    try {
      console.log('Fetching sync progress...')
      const response = await fetch('/admin/jira/sync/progress', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      })
      
      console.log('Response status:', response.status)
      
      if (response.ok) {
        const result = await response.json()
        console.log('Progress result:', result)
        
        if (result.success && result.data) {
          const syncs = Array.isArray(result.data) ? result.data : [result.data]
          console.log('Setting active syncs:', syncs)
          activeSyncs.value = syncs
        } else {
          console.log('No active syncs or unsuccessful result')
          activeSyncs.value = []
        }
      } else {
        console.error('Response not ok:', response.status, response.statusText)
      }
    } catch (error) {
      console.error('Failed to fetch sync progress:', error)
    }
  }

  const updateSyncProgress = (progress: SyncProgress) => {
    const existingIndex = activeSyncs.value.findIndex(
      sync => sync.sync_history_id === progress.sync_history_id
    )
    
    if (existingIndex >= 0) {
      activeSyncs.value[existingIndex] = progress
    } else {
      activeSyncs.value.push(progress)
    }
    
    // Remove completed syncs after a short delay
    if (!progress.is_running) {
      setTimeout(() => {
        activeSyncs.value = activeSyncs.value.filter(
          sync => sync.sync_history_id !== progress.sync_history_id
        )
        
        // Refresh page data when sync completes
        router.reload({ only: ['stats', 'recentSyncs', 'projectStatuses'] })
      }, 3000)
    }
  }

  const disconnect = () => {
    if (echo && userId) {
      echo.leave(`jira-sync.${userId}`)
    }
    stopPolling()
    isConnected.value = false
  }

  // Lifecycle
  onMounted(() => {
    // Initial fetch
    fetchSyncProgress()
    
    // Connect to real-time updates
    if (userId) {
      connectToProgress()
    } else {
      startPolling()
    }
  })

  onUnmounted(() => {
    disconnect()
  })

  return {
    activeSyncs,
    isConnected,
    connectionError,
    hasActiveSyncs,
    currentSync,
    overallProgress,
    fetchSyncProgress,
    connectToProgress,
    disconnect,
  }
}