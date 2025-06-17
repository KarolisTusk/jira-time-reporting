<template>
  <div class="space-y-6">
    <!-- Project Selection -->
    <div class="space-y-2">
      <Label for="project-select" class="text-sm font-medium">
        Projects to Sync
      </Label>
      <div class="space-y-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <Checkbox
              id="select-all-projects"
              :checked="allProjectsSelected"
              :indeterminate="someProjectsSelected && !allProjectsSelected"
              @update:checked="toggleAllProjects"
            />
            <Label for="select-all-projects" class="text-sm">
              Select All Projects ({{ availableProjects.length }})
            </Label>
          </div>
          <Badge variant="secondary" class="text-xs">
            {{ selectedProjects.length }} selected
          </Badge>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 max-h-40 overflow-y-auto border rounded-lg p-3">
          <div
            v-for="project in availableProjects"
            :key="project.project_key"
            class="flex items-center space-x-2"
          >
            <Checkbox
              :id="`project-${project.project_key}`"
              :checked="selectedProjects.includes(project.project_key)"
              @update:checked="(checked) => toggleProject(project.project_key, checked)"
            />
            <Label :for="`project-${project.project_key}`" class="text-sm cursor-pointer">
              <span class="font-mono text-xs text-blue-600">{{ project.project_key }}</span>
              <span class="text-gray-600 ml-1">{{ project.name }}</span>
            </Label>
          </div>
        </div>
      </div>
    </div>

    <!-- Date Range Selection -->
    <div class="space-y-2">
      <Label class="text-sm font-medium">Sync Date Range</Label>
      <div class="space-y-3">
        <RadioGroup v-model="dateRangeType" class="grid grid-cols-2 md:grid-cols-4 gap-2">
          <div class="flex items-center space-x-2">
            <RadioGroupItem value="incremental" id="incremental" />
            <Label for="incremental" class="text-sm">Incremental</Label>
          </div>
          <div class="flex items-center space-x-2">
            <RadioGroupItem value="last7days" id="last7days" />
            <Label for="last7days" class="text-sm">Last 7 Days</Label>
          </div>
          <div class="flex items-center space-x-2">
            <RadioGroupItem value="last30days" id="last30days" />
            <Label for="last30days" class="text-sm">Last 30 Days</Label>
          </div>
          <div class="flex items-center space-x-2">
            <RadioGroupItem value="custom" id="custom" />
            <Label for="custom" class="text-sm">Custom Range</Label>
          </div>
        </RadioGroup>

        <!-- Custom Date Range Picker -->
        <div v-if="dateRangeType === 'custom'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="start-date" class="text-sm">Start Date</Label>
            <Input
              id="start-date"
              type="date"
              v-model="customDateRange.start"
              :max="customDateRange.end || today"
            />
          </div>
          <div class="space-y-2">
            <Label for="end-date" class="text-sm">End Date</Label>
            <Input
              id="end-date"
              type="date"
              v-model="customDateRange.end"
              :min="customDateRange.start"
              :max="today"
            />
          </div>
        </div>

        <!-- Date Range Info -->
        <div class="text-xs text-gray-500 bg-gray-50 p-2 rounded">
          <div class="flex items-center">
            <IconInfo class="w-4 h-4 mr-1" />
            <span>{{ dateRangeDescription }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Sync Options -->
    <div class="space-y-2">
      <Label class="text-sm font-medium">Sync Options</Label>
      <div class="space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="flex items-center space-x-2">
            <Checkbox
              id="only-issues-with-worklogs"
              v-model="syncOptions.onlyIssuesWithWorklogs"
            />
            <Label for="only-issues-with-worklogs" class="text-sm">
              Only sync issues with worklogs
            </Label>
          </div>
          <div class="flex items-center space-x-2">
            <Checkbox
              id="reclassify-resources"
              v-model="syncOptions.reclassifyResources"
            />
            <Label for="reclassify-resources" class="text-sm">
              Reclassify resource types
            </Label>
          </div>
          <div class="flex items-center space-x-2">
            <Checkbox
              id="validate-data"
              v-model="syncOptions.validateData"
            />
            <Label for="validate-data" class="text-sm">
              Validate data integrity
            </Label>
          </div>
          <div class="flex items-center space-x-2">
            <Checkbox
              id="cleanup-orphaned"
              v-model="syncOptions.cleanupOrphaned"
            />
            <Label for="cleanup-orphaned" class="text-sm">
              Cleanup orphaned data
            </Label>
          </div>
        </div>
      </div>
    </div>

    <!-- Batch Size Configuration -->
    <div class="space-y-2">
      <Label for="batch-size" class="text-sm font-medium">
        Batch Size Configuration
      </Label>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="space-y-2">
          <Label for="issue-batch-size" class="text-xs text-gray-600">Issues per batch</Label>
          <Select v-model="syncOptions.issueBatchSize">
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="25">25 (Conservative)</SelectItem>
              <SelectItem value="50">50 (Default)</SelectItem>
              <SelectItem value="100">100 (Aggressive)</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="space-y-2">
          <Label for="rate-limit" class="text-xs text-gray-600">Rate limit (req/min)</Label>
          <Select v-model="syncOptions.rateLimit">
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="200">200 (Conservative)</SelectItem>
              <SelectItem value="300">300 (Default)</SelectItem>
              <SelectItem value="500">500 (Aggressive)</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="space-y-2">
          <Label for="retry-attempts" class="text-xs text-gray-600">Max retry attempts</Label>
          <Select v-model="syncOptions.maxRetryAttempts">
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="1">1</SelectItem>
              <SelectItem value="3">3 (Default)</SelectItem>
              <SelectItem value="5">5</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>

    <!-- Estimated Impact -->
    <div class="space-y-2">
      <Label class="text-sm font-medium">Estimated Impact</Label>
      <Card>
        <CardContent class="p-4">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
            <div>
              <div class="text-lg font-semibold text-blue-600">{{ estimatedProjects }}</div>
              <div class="text-xs text-gray-500">Projects</div>
            </div>
            <div>
              <div class="text-lg font-semibold text-green-600">{{ estimatedIssues }}</div>
              <div class="text-xs text-gray-500">Issues</div>
            </div>
            <div>
              <div class="text-lg font-semibold text-orange-600">{{ estimatedWorklogs }}</div>
              <div class="text-xs text-gray-500">Worklogs</div>
            </div>
            <div>
              <div class="text-lg font-semibold text-purple-600">{{ estimatedDuration }}</div>
              <div class="text-xs text-gray-500">Est. Duration</div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Action Buttons -->
    <div class="flex items-center justify-between pt-4 border-t">
      <div class="flex items-center space-x-2 text-sm text-gray-600">
        <IconShield class="w-4 h-4" />
        <span>Pre-flight validation will run before sync starts</span>
      </div>
      <div class="flex items-center space-x-3">
        <Button
          variant="outline"
          @click="previewSync"
          :disabled="!canSync || isPreviewingSync"
        >
          <IconEye class="w-4 h-4 mr-2" />
          {{ isPreviewingSync ? 'Previewing...' : 'Preview' }}
        </Button>
        <Button
          v-if="!isSyncing"
          @click="handleStartSync"
          :disabled="!canSync"
          class="min-w-[120px]"
        >
          <IconPlay class="w-4 h-4 mr-2" />
          Start Sync
        </Button>
        <Button
          v-else
          variant="destructive"
          @click="handleCancelSync"
          class="min-w-[120px]"
        >
          <IconStop class="w-4 h-4 mr-2" />
          Cancel Sync
        </Button>
      </div>
    </div>

    <!-- Preview Modal -->
    <Dialog v-model:open="showPreview">
      <DialogContent class="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Sync Preview</DialogTitle>
          <DialogDescription>
            Review the sync configuration and estimated impact before proceeding.
          </DialogDescription>
        </DialogHeader>
        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <h4 class="font-medium mb-2">Selected Projects</h4>
              <div class="space-y-1 max-h-32 overflow-y-auto">
                <Badge
                  v-for="projectKey in selectedProjects"
                  :key="projectKey"
                  variant="secondary"
                  class="mr-1 mb-1"
                >
                  {{ projectKey }}
                </Badge>
              </div>
            </div>
            <div>
              <h4 class="font-medium mb-2">Sync Configuration</h4>
              <div class="space-y-1 text-sm text-gray-600">
                <div>Date Range: {{ dateRangeDescription }}</div>
                <div>Issues with worklogs only: {{ syncOptions.onlyIssuesWithWorklogs ? 'Yes' : 'No' }}</div>
                <div>Batch Size: {{ syncOptions.issueBatchSize }} issues</div>
                <div>Rate Limit: {{ syncOptions.rateLimit }} req/min</div>
              </div>
            </div>
          </div>
          <Separator />
          <div v-if="previewData">
            <h4 class="font-medium mb-2">Estimated Impact</h4>
            <div class="grid grid-cols-4 gap-4 text-center">
              <div class="p-3 bg-blue-50 rounded">
                <div class="text-lg font-semibold text-blue-600">{{ previewData.estimatedIssues }}</div>
                <div class="text-xs text-gray-500">Issues to sync</div>
              </div>
              <div class="p-3 bg-green-50 rounded">
                <div class="text-lg font-semibold text-green-600">{{ previewData.estimatedWorklogs }}</div>
                <div class="text-xs text-gray-500">Worklogs to sync</div>
              </div>
              <div class="p-3 bg-orange-50 rounded">
                <div class="text-lg font-semibold text-orange-600">{{ previewData.estimatedApiCalls }}</div>
                <div class="text-xs text-gray-500">API calls</div>
              </div>
              <div class="p-3 bg-purple-50 rounded">
                <div class="text-lg font-semibold text-purple-600">{{ previewData.estimatedDuration }}</div>
                <div class="text-xs text-gray-500">Duration</div>
              </div>
            </div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="showPreview = false">
            Cancel
          </Button>
          <Button @click="confirmSync">
            <IconPlay class="w-4 h-4 mr-2" />
            Start Sync
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useToast } from '@/composables/useToast'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Separator } from '@/components/ui/separator'
import {
  IconInfo,
  IconShield,
  IconEye,
  IconPlay,
  IconStop,
} from '@tabler/icons-vue'

interface Project {
  project_key: string
  name: string
  id: number
}

interface SyncOptions {
  onlyIssuesWithWorklogs: boolean
  reclassifyResources: boolean
  validateData: boolean
  cleanupOrphaned: boolean
  issueBatchSize: string
  rateLimit: string
  maxRetryAttempts: string
}

interface Props {
  availableProjects: Project[]
  isSyncing: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  sync: [config: any]
  cancel: []
  'update:selectedProjects': [projects: string[]]
  'update:dateRange': [range: any]
  'update:syncOptions': [options: SyncOptions]
}>()

const { toast } = useToast()

// Reactive state
const selectedProjects = ref<string[]>([])
const dateRangeType = ref('incremental')
const customDateRange = ref({
  start: '',
  end: ''
})
const syncOptions = ref<SyncOptions>({
  onlyIssuesWithWorklogs: false,
  reclassifyResources: false,
  validateData: true,
  cleanupOrphaned: false,
  issueBatchSize: '50',
  rateLimit: '300',
  maxRetryAttempts: '3'
})

const showPreview = ref(false)
const isPreviewingSync = ref(false)
const previewData = ref<any>(null)

// Computed properties
const today = computed(() => new Date().toISOString().split('T')[0])

const allProjectsSelected = computed(() => 
  selectedProjects.value.length === props.availableProjects.length
)

const someProjectsSelected = computed(() => 
  selectedProjects.value.length > 0 && selectedProjects.value.length < props.availableProjects.length
)

const dateRangeDescription = computed(() => {
  switch (dateRangeType.value) {
    case 'incremental':
      return 'Sync only data updated since last successful sync'
    case 'last7days':
      return 'Sync data from the last 7 days'
    case 'last30days':
      return 'Sync data from the last 30 days'
    case 'custom':
      if (customDateRange.value.start && customDateRange.value.end) {
        return `Sync data from ${customDateRange.value.start} to ${customDateRange.value.end}`
      }
      return 'Select custom date range'
    default:
      return ''
  }
})

const canSync = computed(() => 
  selectedProjects.value.length > 0 && 
  (dateRangeType.value !== 'custom' || (customDateRange.value.start && customDateRange.value.end))
)

// Estimated impact calculations
const estimatedProjects = computed(() => selectedProjects.value.length)
const estimatedIssues = computed(() => {
  // Simple estimation based on project count and historical data
  const avgIssuesPerProject = 50
  return estimatedProjects.value * avgIssuesPerProject
})
const estimatedWorklogs = computed(() => {
  const avgWorklogsPerIssue = 3
  return estimatedIssues.value * avgWorklogsPerIssue
})
const estimatedDuration = computed(() => {
  const estimatedMinutes = Math.ceil(estimatedWorklogs.value / 100)
  if (estimatedMinutes < 60) {
    return `${estimatedMinutes}m`
  }
  const hours = Math.floor(estimatedMinutes / 60)
  const minutes = estimatedMinutes % 60
  return `${hours}h ${minutes}m`
})

// Methods
const toggleProject = (projectKey: string, checked: boolean) => {
  if (checked) {
    if (!selectedProjects.value.includes(projectKey)) {
      selectedProjects.value.push(projectKey)
    }
  } else {
    selectedProjects.value = selectedProjects.value.filter(key => key !== projectKey)
  }
}

const toggleAllProjects = (checked: boolean) => {
  if (checked) {
    selectedProjects.value = props.availableProjects.map(p => p.project_key)
  } else {
    selectedProjects.value = []
  }
}

const buildSyncConfig = () => {
  let dateRange = null
  
  switch (dateRangeType.value) {
    case 'last7days':
      const last7Days = new Date()
      last7Days.setDate(last7Days.getDate() - 7)
      dateRange = {
        start: last7Days.toISOString().split('T')[0],
        end: today.value
      }
      break
    case 'last30days':
      const last30Days = new Date()
      last30Days.setDate(last30Days.getDate() - 30)
      dateRange = {
        start: last30Days.toISOString().split('T')[0],
        end: today.value
      }
      break
    case 'custom':
      dateRange = customDateRange.value
      break
    // incremental uses null (determined by service)
  }

  return {
    project_keys: selectedProjects.value,
    date_range: dateRange,
    sync_type: dateRangeType.value,
    only_issues_with_worklogs: syncOptions.value.onlyIssuesWithWorklogs,
    reclassify_resources: syncOptions.value.reclassifyResources,
    validate_data: syncOptions.value.validateData,
    cleanup_orphaned: syncOptions.value.cleanupOrphaned,
    batch_config: {
      issue_batch_size: parseInt(syncOptions.value.issueBatchSize),
      rate_limit: parseInt(syncOptions.value.rateLimit),
      max_retry_attempts: parseInt(syncOptions.value.maxRetryAttempts)
    }
  }
}

const previewSync = async () => {
  if (!canSync.value) return
  
  isPreviewingSync.value = true
  try {
    const config = buildSyncConfig()
    
    // Simulate API call to get preview data
    // In reality, this would be an API endpoint
    await new Promise(resolve => setTimeout(resolve, 1000))
    
    previewData.value = {
      estimatedIssues: estimatedIssues.value,
      estimatedWorklogs: estimatedWorklogs.value,
      estimatedApiCalls: Math.ceil(estimatedIssues.value / parseInt(syncOptions.value.issueBatchSize)),
      estimatedDuration: estimatedDuration.value
    }
    
    showPreview.value = true
  } catch (error) {
    toast.error('Failed to generate sync preview')
  } finally {
    isPreviewingSync.value = false
  }
}

const handleStartSync = () => {
  if (!canSync.value) return
  previewSync()
}

const confirmSync = () => {
  const config = buildSyncConfig()
  emit('sync', config)
  showPreview.value = false
}

const handleCancelSync = () => {
  emit('cancel')
}

// Watchers to emit updates
watch(selectedProjects, (newValue) => {
  emit('update:selectedProjects', newValue)
}, { deep: true })

watch([dateRangeType, customDateRange], () => {
  emit('update:dateRange', {
    type: dateRangeType.value,
    custom: customDateRange.value
  })
}, { deep: true })

watch(syncOptions, (newValue) => {
  emit('update:syncOptions', newValue)
}, { deep: true })
</script>