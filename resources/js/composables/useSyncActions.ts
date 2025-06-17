import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

interface SyncConfig {
  project_keys: string[]
  sync_type: string
  only_issues_with_worklogs: boolean
  reclassify_resources: boolean
  validate_data: boolean
  cleanup_orphaned: boolean
  batch_config?: Record<string, any>
}

interface SyncResponse {
  success: boolean
  message: string
  data?: {
    sync_history_id: number
    estimated_projects: number
    sync_type: string
    status: string
  }
  code?: string
}

export function useSyncActions() {
  const isStartingSync = ref(false)
  const isCancellingSync = ref(false)
  const lastSyncRequest = ref<number>(0)
  const DEBOUNCE_DELAY = 2000 // 2 seconds

  // Prevent duplicate requests within debounce period
  const canStartSync = computed(() => {
    const now = Date.now()
    return !isStartingSync.value && (now - lastSyncRequest.value) > DEBOUNCE_DELAY
  })

  const startSync = async (config: SyncConfig): Promise<SyncResponse> => {
    // Check debounce
    if (!canStartSync.value) {
      console.warn('Sync request ignored - too soon after last request')
      return {
        success: false,
        message: 'Please wait before starting another sync operation',
        code: 'DEBOUNCE_BLOCK'
      }
    }

    // Validate config
    if (!config.project_keys || config.project_keys.length === 0) {
      return {
        success: false,
        message: 'No projects selected for sync',
        code: 'VALIDATION_ERROR'
      }
    }

    isStartingSync.value = true
    lastSyncRequest.value = Date.now()

    try {
      console.log('Starting sync with config:', config)
      
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (!csrfToken) {
        throw new Error('CSRF token not found')
      }

      const response = await fetch('/admin/jira/sync/start', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(config),
      })

      if (!response.ok) {
        let errorMessage = `HTTP ${response.status}: ${response.statusText}`
        
        try {
          const errorText = await response.text()
          if (errorText) {
            const errorData = JSON.parse(errorText)
            errorMessage = errorData.message || errorMessage
          }
        } catch (parseError) {
          console.warn('Could not parse error response:', parseError)
        }
        
        throw new Error(errorMessage)
      }

      const contentType = response.headers.get('content-type')
      if (!contentType || !contentType.includes('application/json')) {
        const textResponse = await response.text()
        console.error('Server returned non-JSON response:', textResponse)
        throw new Error('Server returned an unexpected response format')
      }

      const result: SyncResponse = await response.json()

      if (result.success) {
        console.log('Sync started successfully:', result.data)
        
        // Refresh page data after successful start
        setTimeout(() => {
          router.reload({ only: ['stats', 'recentSyncs', 'projectStatuses'] })
        }, 1000)
      }

      return result

    } catch (error) {
      console.error('Error starting sync:', error)
      
      return {
        success: false,
        message: `Failed to start sync: ${(error as Error).message}`,
        code: 'REQUEST_ERROR'
      }
    } finally {
      // Add a minimum delay before allowing another request
      setTimeout(() => {
        isStartingSync.value = false
      }, 1000)
    }
  }

  const cancelSync = async (syncId?: number): Promise<SyncResponse> => {
    if (isCancellingSync.value) {
      console.warn('Cancel request ignored - already cancelling')
      return {
        success: false,
        message: 'Cancel request already in progress',
        code: 'ALREADY_CANCELLING'
      }
    }

    isCancellingSync.value = true

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (!csrfToken) {
        throw new Error('CSRF token not found')
      }

      const payload = syncId ? { sync_id: syncId } : {}

      const response = await fetch('/admin/jira/sync/cancel', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      })

      const result: SyncResponse = await response.json()

      if (result.success) {
        console.log('Sync cancelled successfully')
        
        // Refresh page data after successful cancellation
        setTimeout(() => {
          router.reload({ only: ['stats', 'recentSyncs', 'projectStatuses'] })
        }, 500)
      }

      return result

    } catch (error) {
      console.error('Error cancelling sync:', error)
      
      return {
        success: false,
        message: `Failed to cancel sync: ${(error as Error).message}`,
        code: 'CANCEL_ERROR'
      }
    } finally {
      setTimeout(() => {
        isCancellingSync.value = false
      }, 500)
    }
  }

  const testConnection = async (): Promise<SyncResponse> => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (!csrfToken) {
        throw new Error('CSRF token not found')
      }

      const response = await fetch('/admin/jira/test-connection', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
      })

      const result: SyncResponse = await response.json()
      return result

    } catch (error) {
      console.error('Error testing connection:', error)
      
      return {
        success: false,
        message: `Connection test failed: ${(error as Error).message}`,
        code: 'CONNECTION_ERROR'
      }
    }
  }

  return {
    isStartingSync: computed(() => isStartingSync.value),
    isCancellingSync: computed(() => isCancellingSync.value),
    canStartSync,
    startSync,
    cancelSync,
    testConnection,
  }
} 