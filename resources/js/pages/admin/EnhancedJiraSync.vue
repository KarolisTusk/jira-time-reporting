<template>
  <AppLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">Enhanced JIRA Sync</h1>
          <p class="mt-1 text-sm text-gray-600">
            Advanced synchronization controls with incremental sync, resource classification, and real-time progress tracking.
          </p>
        </div>
        <div class="flex items-center space-x-3">
          <Badge 
            :variant="connectionStatus.connected ? 'default' : 'destructive'"
            class="text-xs"
          >
            {{ connectionStatus.connected ? 'Connected' : 'Disconnected' }}
          </Badge>
          <Button
            variant="outline"
            size="sm"
            @click="testConnection"
            :disabled="testingConnection"
          >
            <Wifi class="w-4 h-4 mr-2" />
            {{ testingConnection ? 'Testing...' : 'Test Connection' }}
          </Button>
        </div>
      </div>
    </template>

    <div class="space-y-6">
      <!-- Status Overview Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Projects</CardTitle>
            <Folders class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.totalProjects }}</div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Last Sync</CardTitle>
            <Clock class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.lastSyncFormatted }}</div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Hours</CardTitle>
            <Calendar class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.totalHours }}</div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Active Syncs</CardTitle>
            <Activity class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.activeSyncs }}</div>
          </CardContent>
        </Card>
      </div>

      <!-- Sync Control Panel -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <RefreshCw class="h-5 w-5" />
            Enhanced Sync Controls
          </CardTitle>
          <CardDescription>
            Start a new synchronization with advanced options and real-time progress tracking.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-6">
          <!-- Project Selection -->
          <div>
            <label class="text-sm font-medium">Projects to Sync</label>
            <div v-if="availableProjects.length > 0" class="mt-2">
              <Button
                variant="ghost"
                size="sm"
                @click="toggleAllProjects"
                class="mb-2 text-blue-600 hover:text-blue-800"
              >
                {{ selectedProjects.length === availableProjects.length ? 'Deselect All' : 'Select All' }}
              </Button>
              <div class="max-h-32 overflow-y-auto space-y-1 border rounded p-2">
                <div v-for="project in availableProjects" :key="project.project_key" class="flex items-center space-x-2">
                  <input 
                    type="checkbox" 
                    :id="project.project_key"
                    v-model="selectedProjects"
                    :value="project.project_key"
                    class="rounded border-gray-300"
                  />
                  <label :for="project.project_key" class="text-sm">
                    {{ project.project_key }} - {{ project.name }}
                  </label>
                </div>
              </div>
            </div>
            <div v-else class="mt-2 p-4 border border-dashed border-gray-300 rounded-lg text-center">
              <div class="text-sm text-gray-500 mb-2">
                No projects configured for synchronization
              </div>
              <div class="text-xs text-gray-400 mb-3">
                Please configure projects in JIRA Settings first
              </div>
              <a 
                href="/settings/jira" 
                class="inline-flex items-center px-3 py-1 text-xs font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-md hover:bg-blue-100"
              >
                Configure JIRA Settings
              </a>
            </div>
          </div>

          <!-- SIMPLIFIED Sync Type Selection -->
          <div>
            <label class="text-sm font-medium">Sync Type</label>
            <div class="mt-2 space-y-3">
              <!-- Main Options -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <Button
                  :variant="syncType === 'force_full' ? 'default' : 'outline'"
                  size="sm"
                  @click="syncType = 'force_full'"
                  class="justify-start"
                >
                  <RefreshCw class="w-4 h-4 mr-2" />
                  Force Full Sync (Recommended)
                </Button>
                <Button
                  :variant="syncType === 'last7days' ? 'default' : 'outline'"
                  size="sm"
                  @click="syncType = 'last7days'"
                  class="justify-start"
                >
                  <Calendar class="w-4 h-4 mr-2" />
                  Last 7 Days Only
                </Button>
              </div>
              
              <!-- Custom Date Range (Hidden by Default) -->
              <div v-if="showCustomRange" class="mt-4 p-4 border rounded-lg bg-gray-50">
                <div class="flex items-center justify-between mb-3">
                  <label class="text-sm font-medium">Custom Date Range</label>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="showCustomRange = false"
                  >
                    ✕
                  </Button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div>
                    <label class="text-xs text-gray-600">From Date</label>
                    <input
                      type="date"
                      v-model="customStartDate"
                      class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                      :max="customEndDate || today"
                    />
                  </div>
                  <div>
                    <label class="text-xs text-gray-600">To Date</label>
                    <input
                      type="date"
                      v-model="customEndDate"
                      class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                      :min="customStartDate"
                      :max="today"
                    />
                  </div>
                </div>
                <Button
                  v-if="customStartDate && customEndDate"
                  variant="outline"
                  size="sm"
                  @click="applyCustomRange"
                  class="mt-3"
                >
                  Apply Custom Range
                </Button>
              </div>
              
              <!-- Show Custom Range Option -->
              <Button
                v-if="!showCustomRange"
                variant="ghost"
                size="sm"
                @click="showCustomRange = true"
                class="text-blue-600 hover:text-blue-800"
              >
                <Calendar class="w-4 h-4 mr-2" />
                Need a custom date range?
              </Button>
            </div>
          </div>

          <!-- Sync Options -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex items-center space-x-2">
              <input 
                type="checkbox" 
                id="onlyWorklogs"
                v-model="syncOptions.onlyIssuesWithWorklogs"
                class="rounded border-gray-300"
              />
              <label for="onlyWorklogs" class="text-sm">Only issues with worklogs</label>
            </div>
            
            <div class="flex items-center space-x-2">
              <input 
                type="checkbox" 
                id="reclassify"
                v-model="syncOptions.reclassifyResources"
                class="rounded border-gray-300"
              />
              <label for="reclassify" class="text-sm">Reclassify resource types</label>
            </div>
            
            <div class="flex items-center space-x-2">
              <input 
                type="checkbox" 
                id="validate"
                v-model="syncOptions.validateData"
                class="rounded border-gray-300"
              />
              <label for="validate" class="text-sm">Validate data integrity</label>
            </div>
            
            <div class="flex items-center space-x-2">
              <input 
                type="checkbox" 
                id="cleanup"
                v-model="syncOptions.cleanupOrphaned"
                class="rounded border-gray-300"
              />
              <label for="cleanup" class="text-sm">Cleanup orphaned records</label>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex space-x-4 pt-4">
            <Button 
              @click="startSync" 
              :disabled="selectedProjects.length === 0 || syncInProgress"
              class="flex items-center gap-2"
              data-sync-button
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': syncInProgress }" />
              {{ syncInProgress ? 'Sync in Progress...' : 'Start Enhanced Sync' }}
            </Button>
            
            <Button 
              variant="outline" 
              @click="cancelSync" 
              v-if="syncInProgress"
            >
              Cancel Sync
            </Button>
            
            <Button 
              variant="outline" 
              @click="loadMetrics"
            >
              <BarChart3 class="h-4 w-4 mr-2" />
              Refresh Stats
            </Button>
          </div>
        </CardContent>
      </Card>

      <!-- Incremental Worklog Sync Panel -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <Clock class="h-5 w-5" />
            Incremental Worklog Sync
          </CardTitle>
          <CardDescription>
            Quick worklog updates for daily maintenance. Syncs only worklogs added or modified since last sync.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <!-- Worklog Sync Status Overview -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-3 border rounded-lg">
              <div class="text-sm text-gray-600">Last Worklog Sync</div>
              <div class="text-lg font-semibold">{{ worklogStats.lastSyncFormatted }}</div>
            </div>
            <div class="p-3 border rounded-lg">
              <div class="text-sm text-gray-600">Projects Synced Today</div>
              <div class="text-lg font-semibold">{{ worklogStats.projectsSyncedToday }}</div>
            </div>
            <div class="p-3 border rounded-lg">
              <div class="text-sm text-gray-600">Worklogs Processed</div>
              <div class="text-lg font-semibold">{{ worklogStats.worklogsProcessedToday }}</div>
            </div>
          </div>

          <!-- Worklog Sync Options -->
          <div class="space-y-3">
            <div>
              <label class="text-sm font-medium">Sync Timeframe</label>
              <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-2">
                <Button
                  :variant="worklogSyncTimeframe === 'last24h' ? 'default' : 'outline'"
                  size="sm"
                  @click="worklogSyncTimeframe = 'last24h'"
                  class="justify-start"
                >
                  <Clock class="w-4 h-4 mr-2" />
                  Last 24 Hours
                </Button>
                <Button
                  :variant="worklogSyncTimeframe === 'last7days' ? 'default' : 'outline'"
                  size="sm"
                  @click="worklogSyncTimeframe = 'last7days'"
                  class="justify-start"
                >
                  <Calendar class="w-4 h-4 mr-2" />
                  Last 7 Days
                </Button>
                <Button
                  :variant="worklogSyncTimeframe === 'force_all' ? 'default' : 'outline'"
                  size="sm"
                  @click="worklogSyncTimeframe = 'force_all'"
                  class="justify-start"
                >
                  <RefreshCw class="w-4 h-4 mr-2" />
                  All Worklogs
                </Button>
              </div>
            </div>

            <!-- Project Selection for Worklog Sync -->
            <div>
              <label class="text-sm font-medium">Projects for Worklog Sync</label>
              <div class="mt-2">
                <Button
                  variant="ghost"
                  size="sm"
                  @click="toggleAllWorklogProjects"
                  class="mb-2 text-blue-600 hover:text-blue-800"
                >
                  {{ selectedWorklogProjects.length === availableProjects.length ? 'Deselect All' : 'Select All' }}
                </Button>
                <div class="max-h-32 overflow-y-auto space-y-1 border rounded p-2">
                  <div v-for="project in availableProjects" :key="project.project_key" class="flex items-center space-x-2">
                    <input 
                      type="checkbox" 
                      :id="`worklog-${project.project_key}`"
                      v-model="selectedWorklogProjects"
                      :value="project.project_key"
                      class="rounded border-gray-300"
                    />
                    <label :for="`worklog-${project.project_key}`" class="text-sm">
                      {{ project.project_key }} - {{ project.name }}
                      <span v-if="getWorklogProjectStatus(project.project_key)" class="ml-2 text-xs text-gray-500">
                        ({{ getWorklogProjectStatus(project.project_key) }})
                      </span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Worklog Sync Actions -->
          <div class="flex space-x-4 pt-4 border-t">
            <Button 
              @click="startWorklogSync" 
              :disabled="selectedWorklogProjects.length === 0 || worklogSyncInProgress || syncInProgress"
              class="flex items-center gap-2"
              variant="secondary"
            >
              <Clock class="h-4 w-4" :class="{ 'animate-spin': worklogSyncInProgress }" />
              {{ worklogSyncInProgress ? 'Syncing Worklogs...' : 'Sync Worklogs Now' }}
            </Button>
            
            <Button 
              variant="outline" 
              @click="checkWorklogSyncStatus"
              :disabled="worklogSyncInProgress"
            >
              <BarChart3 class="h-4 w-4 mr-2" />
              Check Status
            </Button>

            <Button 
              variant="outline" 
              @click="showWorklogSyncHistory"
              size="sm"
            >
              View History
            </Button>
          </div>

          <!-- Worklog Sync Progress -->
          <div v-if="worklogSyncProgress && worklogSyncInProgress" class="mt-4 p-4 border rounded-lg bg-blue-50">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium">Worklog Sync Progress</span>
              <span class="text-sm text-gray-600">{{ worklogSyncProgress.projectsCompleted }}/{{ worklogSyncProgress.totalProjects }} projects</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
              <div 
                class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                :style="{ width: `${worklogSyncProgress.progressPercentage || 0}%` }"
              ></div>
            </div>
            <div class="text-sm text-gray-600">
              {{ worklogSyncProgress.currentMessage || 'Processing worklogs...' }}
            </div>
            <div class="text-xs text-gray-500 mt-1">
              Processed: {{ worklogSyncProgress.worklogsProcessed || 0 }} | 
              Added: {{ worklogSyncProgress.worklogsAdded || 0 }} | 
              Updated: {{ worklogSyncProgress.worklogsUpdated || 0 }}
            </div>
            
            <!-- Validation Progress -->
            <div v-if="worklogSyncProgress.validationInProgress" class="mt-2 pt-2 border-t border-blue-200">
              <div class="flex items-center space-x-2">
                <div class="animate-spin h-3 w-3 border border-blue-600 border-t-transparent rounded-full"></div>
                <span class="text-xs text-blue-700">Validating sync results...</span>
              </div>
            </div>
          </div>

          <!-- Worklog Sync Validation Results -->
          <div v-if="worklogValidationResults && !worklogSyncInProgress" class="mt-4 p-4 border rounded-lg">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-sm font-medium">Last Sync Validation Results</h4>
              <span class="text-xs text-gray-500">{{ formatValidationTime(worklogValidationResults.timestamp) }}</span>
            </div>
            
            <!-- Overall Score -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
              <div class="text-center p-2 border rounded">
                <div class="text-lg font-semibold" :class="getScoreColor(worklogValidationResults.averageCompletenessScore)">
                  {{ worklogValidationResults.averageCompletenessScore }}%
                </div>
                <div class="text-xs text-gray-600">Completeness Score</div>
              </div>
              <div class="text-center p-2 border rounded">
                <div class="text-lg font-semibold" :class="getDiscrepancyColor(worklogValidationResults.overallDiscrepancy)">
                  {{ worklogValidationResults.overallDiscrepancy }}%
                </div>
                <div class="text-xs text-gray-600">Discrepancy</div>
              </div>
              <div class="text-center p-2 border rounded">
                <div class="text-lg font-semibold" :class="worklogValidationResults.projectsPassed > 0 ? 'text-green-600' : 'text-red-600'">
                  {{ worklogValidationResults.projectsPassed }}/{{ worklogValidationResults.totalProjects }}
                </div>
                <div class="text-xs text-gray-600">Projects Passed</div>
              </div>
            </div>

            <!-- Critical Issues -->
            <div v-if="worklogValidationResults.criticalIssues && worklogValidationResults.criticalIssues.length > 0" 
                 class="mb-3 p-2 bg-red-50 border border-red-200 rounded">
              <div class="text-sm font-medium text-red-800 mb-1">Critical Issues:</div>
              <ul class="text-xs text-red-700 space-y-1">
                <li v-for="issue in worklogValidationResults.criticalIssues.slice(0, 3)" :key="issue" class="flex items-start">
                  <span class="text-red-500 mr-1">•</span>
                  {{ issue }}
                </li>
                <li v-if="worklogValidationResults.criticalIssues.length > 3" class="text-red-600">
                  ... and {{ worklogValidationResults.criticalIssues.length - 3 }} more issues
                </li>
              </ul>
            </div>

            <!-- Recommendations -->
            <div v-if="worklogValidationResults.recommendations && worklogValidationResults.recommendations.length > 0" 
                 class="p-2 bg-yellow-50 border border-yellow-200 rounded">
              <div class="text-sm font-medium text-yellow-800 mb-1">Recommendations:</div>
              <ul class="text-xs text-yellow-700 space-y-1">
                <li v-for="rec in worklogValidationResults.recommendations.slice(0, 2)" :key="rec" class="flex items-start">
                  <span class="text-yellow-500 mr-1">•</span>
                  {{ rec }}
                </li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Real-time Sync Progress -->
      <SyncProgressTracker
        :active-syncs="activeSyncs"
        :is-connected="isConnected"
        :connection-error="connectionError"
        :show-connection-status="true"
        @cancel-sync="handleCancelSync"
        @refresh-progress="fetchSyncProgress"
      />

      <!-- Project Sync Status -->
      <Card v-if="projectStatuses.length > 0">
        <CardHeader>
          <div class="flex items-center justify-between">
            <div>
              <CardTitle>Project Sync Status</CardTitle>
              <CardDescription>
                {{ projectStatusExpanded ? 'Current synchronization status for each project' : 'Overview of project synchronization status' }}
              </CardDescription>
            </div>
            <Button
              variant="ghost" 
              size="sm"
              @click="projectStatusExpanded = !projectStatusExpanded"
              class="flex items-center gap-2"
            >
              <span class="text-sm">{{ projectStatusExpanded ? 'Collapse' : 'Expand' }}</span>
              <ChevronDown 
                class="h-4 w-4 transition-transform duration-200" 
                :class="{ 'rotate-180': projectStatusExpanded }"
              />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <!-- Collapsed View: Aggregate Statistics -->
          <div v-if="!projectStatusExpanded" class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="p-3 border rounded-lg text-center">
              <div class="text-2xl font-bold text-blue-600">{{ projectStatusAggregates.total }}</div>
              <div class="text-sm text-gray-600">Total Projects</div>
            </div>
            <div class="p-3 border rounded-lg text-center">
              <div class="text-2xl font-bold text-green-600">{{ projectStatusAggregates.successful }}</div>
              <div class="text-sm text-gray-600">Successful</div>
            </div>
            <div class="p-3 border rounded-lg text-center">
              <div class="text-2xl font-bold text-red-600">{{ projectStatusAggregates.failed }}</div>
              <div class="text-sm text-gray-600">Failed</div>
            </div>
            <div class="p-3 border rounded-lg text-center">
              <div class="text-2xl font-bold text-gray-500">{{ projectStatusAggregates.neverSynced }}</div>
              <div class="text-sm text-gray-600">Never Synced</div>
            </div>
          </div>

          <!-- Additional collapsed view stats -->
          <div v-if="!projectStatusExpanded" class="mt-4 flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <div class="flex items-center gap-4">
              <div class="text-sm">
                <span class="font-medium">Success Rate:</span>
                <span :class="projectStatusAggregates.successRate >= 80 ? 'text-green-600' : projectStatusAggregates.successRate >= 60 ? 'text-yellow-600' : 'text-red-600'">
                  {{ projectStatusAggregates.successRate }}%
                </span>
              </div>
              <div class="text-sm">
                <span class="font-medium">Recent Syncs (24h):</span>
                <span class="text-blue-600">{{ projectStatusAggregates.recentSyncs }}</span>
              </div>
            </div>
            <Badge 
              :variant="projectStatusAggregates.successRate >= 80 ? 'default' : projectStatusAggregates.successRate >= 60 ? 'secondary' : 'destructive'"
            >
              {{ projectStatusAggregates.successRate >= 80 ? 'Healthy' : projectStatusAggregates.successRate >= 60 ? 'Warning' : 'Critical' }}
            </Badge>
          </div>

          <!-- Expanded View: Detailed Project List -->
          <div v-if="projectStatusExpanded" class="space-y-2">
            <div v-for="status in projectStatuses" :key="status.project_key" 
                 class="flex items-center justify-between p-3 border rounded-lg">
              <div>
                <div class="font-medium">{{ status.project_key }}</div>
                <div class="text-sm text-gray-500">
                  Last sync: {{ status.last_sync_at ? formatDate(status.last_sync_at) : 'Never' }}
                </div>
              </div>
              <Badge :variant="getStatusVariant(status.last_sync_status)">
                {{ status.last_sync_status || 'Never synced' }}
              </Badge>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Recent Sync History -->
      <Card v-if="recentSyncs.length > 0">
        <CardHeader>
          <CardTitle>Recent Sync History</CardTitle>
          <CardDescription>Latest synchronization operations</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div v-for="sync in recentSyncs" :key="sync.id" 
                 class="flex items-center justify-between p-3 border rounded-lg">
              <div>
                <div class="font-medium">Sync #{{ sync.id }}</div>
                <div class="text-sm text-gray-500">
                  {{ formatDate(sync.started_at) }}
                </div>
              </div>
              <div class="text-right">
                <Badge :variant="getStatusVariant(sync.status)">
                  {{ sync.status }}
                </Badge>
                <div class="text-sm text-gray-500 mt-1">
                  {{ sync.duration_seconds }}s
                </div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import SyncProgressTracker from '@/components/SyncProgressTracker.vue'
import { useSyncProgress } from '@/composables/useSyncProgress'
import { useSyncActions } from '@/composables/useSyncActions'
import {
  Wifi,
  Folders,
  Clock,
  Calendar,
  RefreshCw,
  Activity,
  BarChart3,
  ChevronDown,
} from 'lucide-vue-next'

interface PageProps {
  availableProjects: Array<{
    project_key: string
    name: string
    id: number
  }>
  connectionStatus: {
    connected: boolean
    lastChecked: string | null
  }
  stats: {
    totalProjects: number
    lastSyncFormatted: string
    totalHours: string
    activeSyncs: number
  }
  projectStatuses: Array<{
    project_key: string
    last_sync_at: string | null
    last_sync_status: string | null
  }>
  recentSyncs: Array<{
    id: number
    status: string
    started_at: string
    duration_seconds: number
  }>
  auth: {
    user: {
      id: number
      name: string
      email: string
    }
  }
}

const page = usePage<PageProps>()
const { availableProjects, connectionStatus, stats, projectStatuses, recentSyncs, auth } = page.props

// Real-time sync progress tracking
console.log('Auth object:', auth)
const {
  activeSyncs,
  isConnected,
  connectionError,
  hasActiveSyncs,
  fetchSyncProgress,
} = useSyncProgress(auth?.user?.id)

// Enhanced sync actions with debouncing and error handling
const {
  isStartingSync,
  isCancellingSync,
  canStartSync,
  startSync: startSyncAction,
  cancelSync: cancelSyncAction,
  testConnection: testConnectionAction,
} = useSyncActions()

// Reactive state
const selectedProjects = ref<string[]>([])
const syncType = ref('force_full') // Default to force_full (recommended)
const showCustomRange = ref(false)
const customStartDate = ref('')
const customEndDate = ref('')
const syncOptions = ref({
  onlyIssuesWithWorklogs: false,
  reclassifyResources: false,
  validateData: true,
  cleanupOrphaned: false,
})

// Worklog sync state
const selectedWorklogProjects = ref<string[]>([])
const worklogSyncTimeframe = ref('last24h')
const worklogSyncInProgress = ref(false)

// Project status collapse state
const projectStatusExpanded = ref(false)
const worklogSyncProgress = ref<{
  projectsCompleted: number
  totalProjects: number
  worklogsProcessed: number
  worklogsAdded: number
  worklogsUpdated: number
  currentMessage: string
} | null>(null)
const worklogStats = ref({
  lastSyncFormatted: 'Never',
  projectsSyncedToday: 0,
  worklogsProcessedToday: 0,
})
const worklogProjectStatuses = ref<Record<string, string>>({})
const worklogValidationResults = ref<{
  timestamp: string
  averageCompletenessScore: number
  overallDiscrepancy: number
  totalProjects: number
  projectsPassed: number
  criticalIssues: string[]
  recommendations: string[]
} | null>(null)

// Computed sync in progress state
const syncInProgress = computed(() => hasActiveSyncs.value || isStartingSync.value)

// Computed properties
const today = computed(() => new Date().toISOString().split('T')[0])

// Project status aggregate statistics
const projectStatusAggregates = computed(() => {
  const total = projectStatuses.length
  const successful = projectStatuses.filter(p => p.last_sync_status === 'completed').length
  const failed = projectStatuses.filter(p => p.last_sync_status === 'failed').length
  const neverSynced = projectStatuses.filter(p => !p.last_sync_status).length
  const recentSyncs = projectStatuses.filter(p => {
    if (!p.last_sync_at) return false
    const syncDate = new Date(p.last_sync_at)
    const yesterday = new Date()
    yesterday.setDate(yesterday.getDate() - 1)
    return syncDate > yesterday
  }).length

  return {
    total,
    successful,
    failed,
    neverSynced,
    recentSyncs,
    successRate: total > 0 ? Math.round((successful / total) * 100) : 0
  }
})

// Methods
const applyCustomRange = () => {
  if (customStartDate.value && customEndDate.value) {
    syncType.value = 'custom'
    showCustomRange.value = false
    alert(`Custom range applied: ${customStartDate.value} to ${customEndDate.value}`)
  }
}

const startSync = async () => {
  if (selectedProjects.value.length === 0) {
    alert('Please select at least one project to sync')
    return
  }
  
  // Build sync configuration based on selected type
  let config = {
    project_keys: selectedProjects.value,
    sync_type: syncType.value,
    only_issues_with_worklogs: syncOptions.value.onlyIssuesWithWorklogs,
    reclassify_resources: syncOptions.value.reclassifyResources,
    validate_data: syncOptions.value.validateData,
    cleanup_orphaned: syncOptions.value.cleanupOrphaned,
  }

  // Add date range for custom sync type
  if (syncType.value === 'custom' && customStartDate.value && customEndDate.value) {
    config.date_range = {
      start: customStartDate.value,
      end: customEndDate.value
    }
  }

  // For force_full, explicitly reset any previous sync status
  if (syncType.value === 'force_full') {
    config.force_full_sync = true
  }

  const result = await startSyncAction(config)
  
  if (result.success) {
    alert('Sync started successfully! Check the progress below.')
    
    // Start polling for progress
    setTimeout(() => {
      fetchSyncProgress()
    }, 1000)
  } else {
    alert(result.message)
  }
}

const cancelSync = async () => {
  const result = await cancelSyncAction()
  
  if (result.success) {
    alert('Sync cancelled successfully!')
  } else {
    alert(result.message)
  }
}

const handleCancelSync = async (syncId: number) => {
  const result = await cancelSyncAction(syncId)
  
  if (result.success) {
    alert('Sync cancelled successfully!')
    fetchSyncProgress() // Refresh progress immediately
  } else {
    alert(result.message)
  }
}

const testConnection = async () => {
  const result = await testConnectionAction()
  
  if (result.success) {
    alert('Connection test successful!')
  } else {
    alert(result.message)
  }
}

const loadMetrics = () => {
  router.reload()
}

// Worklog sync methods
const toggleAllWorklogProjects = () => {
  if (selectedWorklogProjects.value.length === availableProjects.length) {
    selectedWorklogProjects.value = []
  } else {
    selectedWorklogProjects.value = availableProjects.map(p => p.project_key)
  }
}

const toggleAllProjects = () => {
  if (selectedProjects.value.length === availableProjects.length) {
    selectedProjects.value = []
  } else {
    selectedProjects.value = availableProjects.map(p => p.project_key)
  }
}

const getWorklogProjectStatus = (projectKey: string) => {
  return worklogProjectStatuses.value[projectKey] || null
}

const startWorklogSync = async () => {
  if (selectedWorklogProjects.value.length === 0) {
    alert('Please select at least one project for worklog sync')
    return
  }

  worklogSyncInProgress.value = true
  worklogSyncProgress.value = {
    projectsCompleted: 0,
    totalProjects: selectedWorklogProjects.value.length,
    worklogsProcessed: 0,
    worklogsAdded: 0,
    worklogsUpdated: 0,
    currentMessage: 'Starting worklog sync...'
  }

  try {
    const config = {
      project_keys: selectedWorklogProjects.value,
      timeframe: worklogSyncTimeframe.value,
      sync_type: 'worklog_incremental'
    }

    const response = await fetch('/api/jira/sync/worklogs', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify(config)
    })

    const result = await response.json()

    if (result.success) {
      alert('Worklog sync started successfully!')
      
      // Poll for progress updates
      pollWorklogSyncProgress(result.sync_history_id)
    } else {
      throw new Error(result.message || 'Failed to start worklog sync')
    }

  } catch (error) {
    console.error('Worklog sync error:', error)
    alert(error.message || 'Failed to start worklog sync')
    worklogSyncInProgress.value = false
    worklogSyncProgress.value = null
  }
}

const pollWorklogSyncProgress = async (syncHistoryId: number) => {
  const maxAttempts = 60 // 5 minutes with 5-second intervals
  let attempts = 0

  const poll = async () => {
    if (attempts >= maxAttempts || !worklogSyncInProgress.value) {
      worklogSyncInProgress.value = false
      return
    }

    try {
      const response = await fetch(`/api/jira/sync/progress/${syncHistoryId}`)
      const progress = await response.json()

      if (progress.success && worklogSyncProgress.value) {
        const metadata = progress.data?.metadata || {}
        worklogSyncProgress.value.currentMessage = progress.data?.current_message || 'Processing...'
        worklogSyncProgress.value.worklogsProcessed = metadata.worklogs_processed || 0
        worklogSyncProgress.value.worklogsAdded = metadata.worklogs_added || 0
        worklogSyncProgress.value.worklogsUpdated = metadata.worklogs_updated || 0
        worklogSyncProgress.value.progressPercentage = progress.data?.progress_percentage || 0
        worklogSyncProgress.value.validationInProgress = metadata.validation_in_progress || false

        if (progress.data?.status === 'completed' || progress.data?.status === 'completed_with_errors') {
          worklogSyncInProgress.value = false
          worklogSyncProgress.value.currentMessage = 'Worklog sync completed!'
          loadWorklogStats() // Refresh stats
          loadWorklogValidationResults() // Load validation results
          return
        }

        if (progress.data?.status === 'failed') {
          worklogSyncInProgress.value = false
          worklogSyncProgress.value.currentMessage = 'Worklog sync failed'
          return
        }
      }

      attempts++
      setTimeout(poll, 5000) // Poll every 5 seconds

    } catch (error) {
      console.error('Progress polling error:', error)
      attempts++
      setTimeout(poll, 5000)
    }
  }

  poll()
}

const checkWorklogSyncStatus = async () => {
  try {
    const response = await fetch('/api/jira/sync/worklogs/status')
    const result = await response.json()

    if (result.success) {
      worklogStats.value = result.stats
      worklogProjectStatuses.value = result.project_statuses || {}
      alert('Worklog sync status refreshed!')
    } else {
      throw new Error(result.message || 'Failed to fetch worklog sync status')
    }

  } catch (error) {
    console.error('Status check error:', error)
    alert(error.message || 'Failed to check worklog sync status')
  }
}

const showWorklogSyncHistory = () => {
  // Navigate to worklog sync history page or show modal
  router.visit('/admin/jira/worklog-sync-history')
}

const loadWorklogStats = async () => {
  try {
    const response = await fetch('/api/jira/sync/worklogs/stats')
    const result = await response.json()

    if (result.success) {
      worklogStats.value = result.stats
    }

  } catch (error) {
    console.error('Failed to load worklog stats:', error)
  }
}

const loadWorklogValidationResults = async () => {
  try {
    const response = await fetch('/api/jira/sync/worklogs/validation')
    const result = await response.json()

    if (result.success && result.validation_summary) {
      worklogValidationResults.value = {
        timestamp: result.validation_summary.timestamp || new Date().toISOString(),
        averageCompletenessScore: Math.round(result.validation_summary.average_completeness_score || 0),
        overallDiscrepancy: Math.round((result.validation_summary.overall_discrepancy_percentage || 0) * 100) / 100,
        totalProjects: result.validation_summary.total_projects || 0,
        projectsPassed: result.validation_summary.projects_passed || 0,
        criticalIssues: result.validation_summary.critical_issues || [],
        recommendations: result.validation_summary.recommendations || [],
      }
    }

  } catch (error) {
    console.error('Failed to load worklog validation results:', error)
  }
}

const formatValidationTime = (timestamp: string) => {
  try {
    return new Date(timestamp).toLocaleString()
  } catch {
    return 'Unknown'
  }
}

const getScoreColor = (score: number) => {
  if (score >= 90) return 'text-green-600'
  if (score >= 75) return 'text-yellow-600'
  return 'text-red-600'
}

const getDiscrepancyColor = (discrepancy: number) => {
  if (discrepancy <= 2) return 'text-green-600'
  if (discrepancy <= 5) return 'text-yellow-600'
  return 'text-red-600'
}

const formatDate = (dateString: string) => {
  return new Date(dateString).toLocaleString()
}

const getStatusVariant = (status: string | null) => {
  switch (status) {
    case 'completed':
      return 'default'
    case 'failed':
      return 'destructive'
    case 'in_progress':
      return 'secondary'
    default:
      return 'outline'
  }
}

// Initialize with all projects selected
onMounted(() => {
  selectedProjects.value = availableProjects.map(p => p.project_key)
  selectedWorklogProjects.value = availableProjects.map(p => p.project_key)
  loadWorklogStats()
  loadWorklogValidationResults()
})
</script>