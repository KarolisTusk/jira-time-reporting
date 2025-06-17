import { ref, computed, onMounted, onUnmounted } from 'vue';
import echo from '../echo';

export interface SyncProgress {
    sync_history_id: number;
    status: 'pending' | 'in_progress' | 'completed' | 'failed';
    progress_percentage: number;
    project_progress_percentage: number;
    issue_progress_percentage: number;
    worklog_progress_percentage: number;
    user_progress_percentage: number;
    totals: {
        projects: number;
        issues: number;
        worklogs: number;
        users: number;
    };
    processed: {
        projects: number;
        issues: number;
        worklogs: number;
        users: number;
    };
    error_count: number;
    has_errors: boolean;
    is_running: boolean;
    started_at: string | null;
    completed_at: string | null;
    formatted_duration: string | null;
    progress_data?: {
        current_operation?: string;
        estimated_completion?: string;
        items_per_second?: number;
        time_remaining_seconds?: number;
    };
}

export function useJiraSyncProgress(userId?: number) {
    const syncProgress = ref<SyncProgress | null>(null);
    const isListening = ref(false);
    const error = ref<string | null>(null);
    const isLoading = ref(false);

    // Computed properties for UI display
    const hasActiveSync = computed(() => {
        return syncProgress.value && ['pending', 'in_progress'].includes(syncProgress.value.status);
    });

    const isCompleted = computed(() => {
        return syncProgress.value?.status === 'completed';
    });

    const isFailed = computed(() => {
        return syncProgress.value?.status === 'failed';
    });

    const progressPercentage = computed(() => {
        return syncProgress.value?.progress_percentage || 0;
    });

    const currentOperation = computed(() => {
        return syncProgress.value?.progress_data?.current_operation || 'Initializing...';
    });

    const estimatedCompletion = computed(() => {
        return syncProgress.value?.progress_data?.estimated_completion;
    });

    const timeRemaining = computed(() => {
        const seconds = syncProgress.value?.progress_data?.time_remaining_seconds;
        if (!seconds || seconds <= 0) return null;

        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.floor(seconds % 60);

        if (minutes > 0) {
            return `${minutes}m ${remainingSeconds}s`;
        }
        return `${remainingSeconds}s`;
    });

    const progressDetails = computed(() => {
        if (!syncProgress.value) return [];

        return [
            {
                label: 'Projects',
                processed: syncProgress.value.processed.projects,
                total: syncProgress.value.totals.projects,
                percentage: syncProgress.value.project_progress_percentage,
            },
            {
                label: 'Issues',
                processed: syncProgress.value.processed.issues,
                total: syncProgress.value.totals.issues,
                percentage: syncProgress.value.issue_progress_percentage,
            },
            {
                label: 'Worklogs',
                processed: syncProgress.value.processed.worklogs,
                total: syncProgress.value.totals.worklogs,
                percentage: syncProgress.value.worklog_progress_percentage,
            },
            {
                label: 'Users',
                processed: syncProgress.value.processed.users,
                total: syncProgress.value.totals.users,
                percentage: syncProgress.value.user_progress_percentage,
            },
        ];
    });

    // Start listening to sync progress updates
    const startListening = (syncUserId: number) => {
        if (isListening.value) {
            stopListening();
        }

        try {
            const channel = echo.private(`jira-sync.${syncUserId}`);
            
            channel.listen('.jira.sync.progress', (data: SyncProgress) => {
                console.log('Received sync progress update:', data);
                syncProgress.value = data;
                error.value = null;
            });

            channel.error((err: any) => {
                console.error('Broadcasting channel error:', err);
                error.value = 'Connection error occurred';
            });

            isListening.value = true;
            console.log(`Started listening to sync progress for user ${syncUserId}`);
        } catch (err) {
            console.error('Failed to start listening:', err);
            error.value = 'Failed to connect to real-time updates';
        }
    };

    // Stop listening to sync progress updates
    const stopListening = () => {
        if (isListening.value && userId) {
            try {
                echo.leave(`jira-sync.${userId}`);
                isListening.value = false;
                console.log('Stopped listening to sync progress');
            } catch (err) {
                console.error('Failed to stop listening:', err);
            }
        }
    };

    // Fetch current sync status from API
    const fetchSyncStatus = async (syncHistoryId: number) => {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await fetch(route('jira.sync.status', { syncHistoryId }), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            syncProgress.value = data;
        } catch (err) {
            console.error('Failed to fetch sync status:', err);
            error.value = err instanceof Error ? err.message : 'Failed to fetch sync status';
        } finally {
            isLoading.value = false;
        }
    };

    // Cancel the current sync
    const cancelSync = async () => {
        if (!syncProgress.value?.sync_history_id) {
            error.value = 'No active sync to cancel';
            return false;
        }

        try {
            const response = await fetch(route('jira.sync-history.cancel', { syncHistory: syncProgress.value.sync_history_id }), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                }
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || `HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            console.log('Sync cancelled:', result.message);
            return true;
        } catch (err) {
            console.error('Failed to cancel sync:', err);
            error.value = err instanceof Error ? err.message : 'Failed to cancel sync';
            return false;
        }
    };

    // Retry a failed sync
    const retrySync = async () => {
        if (!syncProgress.value?.sync_history_id) {
            error.value = 'No sync to retry';
            return false;
        }

        try {
            const response = await fetch(route('jira.sync-history.retry', { syncHistory: syncProgress.value.sync_history_id }), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                }
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || `HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            console.log('Sync retry initiated:', result.message);

            // Start listening for the new sync
            if (result.new_sync_id && userId) {
                await fetchSyncStatus(result.new_sync_id);
                startListening(userId);
            }

            return true;
        } catch (err) {
            console.error('Failed to retry sync:', err);
            error.value = err instanceof Error ? err.message : 'Failed to retry sync';
            return false;
        }
    };

    // Clear current sync progress
    const clearProgress = () => {
        syncProgress.value = null;
        error.value = null;
    };

    // Initialize if userId is provided
    onMounted(() => {
        if (userId) {
            startListening(userId);
        }
    });

    // Cleanup on unmount
    onUnmounted(() => {
        stopListening();
    });

    return {
        // State
        syncProgress,
        isListening,
        error,
        isLoading,

        // Computed
        hasActiveSync,
        isCompleted,
        isFailed,
        progressPercentage,
        currentOperation,
        estimatedCompletion,
        timeRemaining,
        progressDetails,

        // Methods
        startListening,
        stopListening,
        fetchSyncStatus,
        cancelSync,
        retrySync,
        clearProgress,
    };
} 