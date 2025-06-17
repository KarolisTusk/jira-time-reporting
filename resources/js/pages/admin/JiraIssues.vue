<template>
  <AppLayout title="JIRA Issues">
    <div class="space-y-6">
      <!-- Header with Stats -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">JIRA Issues</h1>
          <p class="text-gray-600">View and manage all imported JIRA issues</p>
        </div>
        <div class="flex gap-4">
          <Button @click="exportData" variant="outline" :disabled="loading">
            <Download class="w-4 h-4 mr-2" />
            Export CSV
          </Button>
          <Button @click="refreshData" :disabled="loading">
            <RefreshCw class="w-4 h-4 mr-2" :class="{ 'animate-spin': loading }" />
            Refresh
          </Button>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-blue-100 rounded-lg">
                <FileText class="w-5 h-5 text-blue-600" />
              </div>
              <div>
                <p class="text-sm text-gray-600">Total Issues</p>
                <p class="text-2xl font-bold">{{ stats.total_issues?.toLocaleString() || 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-green-100 rounded-lg">
                <Clock class="w-5 h-5 text-green-600" />
              </div>
              <div>
                <p class="text-sm text-gray-600">With Worklogs</p>
                <p class="text-2xl font-bold">{{ stats.issues_with_worklogs?.toLocaleString() || 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-purple-100 rounded-lg">
                <Users class="w-5 h-5 text-purple-600" />
              </div>
              <div>
                <p class="text-sm text-gray-600">Total Hours</p>
                <p class="text-2xl font-bold">{{ stats.total_logged_hours?.toLocaleString() || 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="p-2 bg-orange-100 rounded-lg">
                <Calendar class="w-5 h-5 text-orange-600" />
              </div>
              <div>
                <p class="text-sm text-gray-600">Last Sync</p>
                <p class="text-sm font-medium">{{ formatDate(stats.last_sync) || 'Never' }}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Filters -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <Filter class="w-4 h-4" />
            Filters
          </CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Search -->
            <div>
              <label class="block text-sm font-medium mb-1">Search</label>
              <Input
                v-model="filters.search"
                placeholder="Issue key or summary..."
                @input="debouncedSearch"
              />
            </div>

            <!-- Project Filter -->
            <div>
              <label class="block text-sm font-medium mb-1">Project</label>
              <select 
                v-model="filters.project_key" 
                @change="loadData"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All projects</option>
                <option v-for="project in projects" :key="project.id" :value="project.project_key">
                  {{ project.project_key }} - {{ project.name }}
                </option>
              </select>
            </div>

            <!-- Status Filter -->
            <div>
              <label class="block text-sm font-medium mb-1">Status</label>
              <select 
                v-model="filters.status" 
                @change="loadData"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All statuses</option>
                <option v-for="status in uniqueStatuses" :key="status" :value="status">
                  {{ status }}
                </option>
              </select>
            </div>

            <!-- Worklogs Filter -->
            <div>
              <label class="block text-sm font-medium mb-1">Worklogs</label>
              <select 
                v-model="filters.has_worklogs" 
                @change="loadData"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">All issues</option>
                <option value="true">With worklogs</option>
                <option value="false">Without worklogs</option>
              </select>
            </div>
          </div>

          <div class="flex gap-2">
            <Button @click="clearFilters" variant="outline" size="sm">
              Clear Filters
            </Button>
            <div v-if="filteredStats" class="text-sm text-gray-600 flex items-center">
              Showing {{ filteredStats.filtered_count }} issues
              ({{ filteredStats.filtered_with_worklogs }} with worklogs, 
              {{ filteredStats.filtered_logged_hours }}h logged)
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Issues Table -->
      <Card>
        <CardHeader>
          <div class="flex items-center justify-between">
            <CardTitle>Issues</CardTitle>
            <div class="flex items-center gap-2">
              <span class="text-sm text-gray-600">Per page:</span>
              <select 
                v-model="pagination.per_page" 
                @change="loadData"
                class="w-20 px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div class="relative">
            <!-- Loading Overlay -->
            <div v-if="loading" class="absolute inset-0 bg-white/50 flex items-center justify-center z-10">
              <RefreshCw class="w-6 h-6 animate-spin" />
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
              <table class="w-full border-collapse">
                <thead>
                  <tr class="border-b">
                    <th class="text-left p-3 font-medium">
                      <button @click="sort('issue_key')" class="flex items-center gap-1 hover:text-blue-600">
                        Issue Key
                        <ArrowUpDown class="w-3 h-3" />
                      </button>
                    </th>
                    <th class="text-left p-3 font-medium">Summary</th>
                    <th class="text-left p-3 font-medium">
                      <button @click="sort('status')" class="flex items-center gap-1 hover:text-blue-600">
                        Status
                        <ArrowUpDown class="w-3 h-3" />
                      </button>
                    </th>
                    <th class="text-left p-3 font-medium">
                      <button @click="sort('project_key')" class="flex items-center gap-1 hover:text-blue-600">
                        Project
                        <ArrowUpDown class="w-3 h-3" />
                      </button>
                    </th>
                    <th class="text-left p-3 font-medium">Assignee</th>
                    <th class="text-left p-3 font-medium">Worklogs</th>
                    <th class="text-left p-3 font-medium">Hours</th>
                    <th class="text-left p-3 font-medium">
                      <button @click="sort('updated_at')" class="flex items-center gap-1 hover:text-blue-600">
                        Last Sync
                        <ArrowUpDown class="w-3 h-3" />
                      </button>
                    </th>
                    <th class="text-left p-3 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="issue in issues" :key="issue.id" class="border-b hover:bg-gray-50">
                    <td class="p-3">
                      <span class="font-mono text-sm font-medium text-blue-600">
                        {{ issue.issue_key }}
                      </span>
                    </td>
                    <td class="p-3">
                      <div class="max-w-xs">
                        <p class="truncate font-medium">{{ issue.summary }}</p>
                      </div>
                    </td>
                    <td class="p-3">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                            :class="getStatusClass(issue.status)">
                        {{ issue.status }}
                      </span>
                    </td>
                    <td class="p-3">
                      <div>
                        <span class="font-medium">{{ issue.project.key }}</span>
                        <p class="text-xs text-gray-500">{{ issue.project.name }}</p>
                      </div>
                    </td>
                    <td class="p-3">
                      <div v-if="issue.assignee">
                        <span class="text-sm">{{ issue.assignee.display_name }}</span>
                        <p class="text-xs text-gray-500">{{ issue.assignee.email_address }}</p>
                      </div>
                      <span v-else class="text-gray-400">Unassigned</span>
                    </td>
                    <td class="p-3">
                      <div class="flex items-center gap-2">
                        <span class="font-medium">{{ issue.worklogs_count }}</span>
                        <div v-if="issue.worklog_authors.length > 0" class="text-xs text-gray-500">
                          by {{ issue.worklog_authors.slice(0, 2).join(', ') }}
                          <span v-if="issue.worklog_authors.length > 2">
                            +{{ issue.worklog_authors.length - 2 }} more
                          </span>
                        </div>
                      </div>
                    </td>
                    <td class="p-3">
                      <div>
                        <span class="font-medium">{{ issue.total_logged_hours }}h</span>
                        <div v-if="issue.original_estimate_hours" class="text-xs text-gray-500">
                          est: {{ issue.original_estimate_hours }}h
                        </div>
                      </div>
                    </td>
                    <td class="p-3">
                      <span class="text-sm text-gray-600">{{ issue.last_sync }}</span>
                    </td>
                    <td class="p-3">
                      <Button @click="viewIssue(issue.issue_key)" variant="outline" size="sm">
                        <Eye class="w-3 h-3 mr-1" />
                        View
                      </Button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Empty State -->
            <div v-if="!loading && issues.length === 0" class="text-center py-8">
              <FileText class="w-12 h-12 mx-auto text-gray-400 mb-4" />
              <h3 class="text-lg font-medium text-gray-900 mb-2">No issues found</h3>
              <p class="text-gray-600">Try adjusting your filters or sync some JIRA data first.</p>
            </div>
          </div>

          <!-- Pagination -->
          <div v-if="paginationData && paginationData.last_page > 1" class="mt-4 flex items-center justify-between">
            <div class="text-sm text-gray-600">
              Showing {{ paginationData.from }} to {{ paginationData.to }} of {{ paginationData.total }} results
            </div>
            <div class="flex gap-2">
              <Button
                @click="goToPage(paginationData.current_page - 1)"
                :disabled="paginationData.current_page <= 1"
                variant="outline"
                size="sm"
              >
                Previous
              </Button>
              <Button
                v-for="page in visiblePages"
                :key="page"
                @click="goToPage(page)"
                :variant="page === paginationData.current_page ? 'default' : 'outline'"
                size="sm"
              >
                {{ page }}
              </Button>
              <Button
                @click="goToPage(paginationData.current_page + 1)"
                :disabled="paginationData.current_page >= paginationData.last_page"
                variant="outline"
                size="sm"
              >
                Next
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Issue Detail Modal -->
    <div v-if="selectedIssue && showIssueModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg max-w-4xl max-h-[90vh] overflow-y-auto p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-bold">{{ selectedIssue.issue_key }}</h2>
          <Button @click="closeIssueModal" variant="outline" size="sm">
            Close
          </Button>
        </div>
        <div class="space-y-4">
          <p class="text-gray-600">{{ selectedIssue.summary }}</p>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-gray-600">Status</label>
              <p class="font-medium">{{ selectedIssue.status }}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-600">Project</label>
              <p class="font-medium">{{ selectedIssue.project.key }} - {{ selectedIssue.project.name }}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-600">Assignee</label>
              <p class="font-medium">{{ selectedIssue.assignee?.display_name || 'Unassigned' }}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-600">Total Logged Hours</label>
              <p class="font-medium text-blue-600">{{ selectedIssue.total_logged_hours }}h</p>
            </div>
          </div>
          <div v-if="selectedIssue.worklogs.length > 0">
            <h3 class="font-medium mb-2">Worklogs ({{ selectedIssue.worklogs.length }})</h3>
            <div class="space-y-2">
              <div v-for="worklog in selectedIssue.worklogs" :key="worklog.id" 
                   class="flex items-center justify-between p-3 border rounded-lg">
                <div>
                  <div class="font-medium">{{ worklog.author.display_name }}</div>
                  <div class="text-sm text-gray-600">{{ formatDateTime(worklog.started_at) }}</div>
                </div>
                <div class="text-right">
                  <div class="font-bold text-blue-600">{{ worklog.time_spent_hours }}h</div>
                  <div v-if="worklog.resource_type" class="text-xs text-gray-500">
                    {{ worklog.resource_type }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  FileText,
  Clock,
  Users,
  Calendar,
  Filter,
  Download,
  RefreshCw,
  ArrowUpDown,
  Eye,
} from 'lucide-vue-next'

interface Project {
  id: number
  project_key: string
  name: string
}

interface User {
  id: number
  display_name: string
  email_address: string
}

interface Issue {
  id: number
  jira_id: string
  issue_key: string
  summary: string
  status: string
  project: {
    key: string
    name: string
  }
  assignee?: {
    id: number
    display_name: string
    email_address: string
  }
  original_estimate_hours?: number
  worklogs_count: number
  total_logged_hours: number
  worklog_authors: string[]
  created_at: string
  updated_at: string
  last_sync: string
}

interface Props {
  projects: Project[]
  users: User[]
  stats: {
    total_issues: number
    issues_with_worklogs: number
    total_projects: number
    total_users: number
    total_logged_hours: number
    last_sync: string
  }
}

defineProps<Props>()

// Reactive state
const loading = ref(false)
const issues = ref<Issue[]>([])
const paginationData = ref<any>(null)
const filteredStats = ref<any>(null)
const selectedIssue = ref<any>(null)
const showIssueModal = ref(false)

// Filters
const filters = ref({
  search: '',
  project_key: '',
  status: '',
  assignee_id: '',
  has_worklogs: '',
  date_from: '',
  date_to: '',
})

// Sorting
const sorting = ref({
  field: 'updated_at',
  direction: 'desc',
})

// Pagination
const pagination = ref({
  page: 1,
  per_page: '25',
})

// Computed
const uniqueStatuses = computed(() => {
  const statuses = new Set<string>()
  issues.value.forEach(issue => statuses.add(issue.status))
  return Array.from(statuses).sort()
})

const visiblePages = computed(() => {
  if (!paginationData.value) return []
  
  const current = paginationData.value.current_page
  const last = paginationData.value.last_page
  const pages = []
  
  // Show up to 5 pages around current page
  const start = Math.max(1, current - 2)
  const end = Math.min(last, current + 2)
  
  for (let i = start; i <= end; i++) {
    pages.push(i)
  }
  
  return pages
})

// Methods
const loadData = async () => {
  loading.value = true
  
  try {
    const params = new URLSearchParams({
      page: pagination.value.page.toString(),
      per_page: pagination.value.per_page,
      sort_field: sorting.value.field,
      sort_direction: sorting.value.direction,
      ...Object.fromEntries(
        Object.entries(filters.value).filter(([, value]) => value !== '')
      ),
    })

    const response = await fetch(`/admin/jira/issues/data?${params}`)
    const data = await response.json()

    if (data.success) {
      issues.value = data.data.data
      paginationData.value = data.data
      filteredStats.value = data.stats
    }
  } catch (error) {
    console.error('Failed to load issues:', error)
  } finally {
    loading.value = false
  }
}

const debouncedSearch = (() => {
  let timeout: NodeJS.Timeout
  return () => {
    clearTimeout(timeout)
    timeout = setTimeout(() => {
      pagination.value.page = 1
      loadData()
    }, 300)
  }
})()

const sort = (field: string) => {
  if (sorting.value.field === field) {
    sorting.value.direction = sorting.value.direction === 'asc' ? 'desc' : 'asc'
  } else {
    sorting.value.field = field
    sorting.value.direction = 'asc'
  }
  pagination.value.page = 1
  loadData()
}

const goToPage = (page: number) => {
  pagination.value.page = page
  loadData()
}

const clearFilters = () => {
  filters.value = {
    search: '',
    project_key: '',
    status: '',
    assignee_id: '',
    has_worklogs: '',
    date_from: '',
    date_to: '',
  }
  pagination.value.page = 1
  loadData()
}

const refreshData = () => {
  loadData()
}

const exportData = () => {
  const params = new URLSearchParams(
    Object.fromEntries(
      Object.entries(filters.value).filter(([, value]) => value !== '')
    )
  )
  window.open(`/admin/jira/issues/export?${params}`, '_blank')
}

const viewIssue = async (issueKey: string) => {
  try {
    const response = await fetch(`/admin/jira/issues/${issueKey}`)
    const data = await response.json()
    
    if (data.success) {
      selectedIssue.value = data.data
      showIssueModal.value = true
    }
  } catch (error) {
    console.error('Failed to load issue details:', error)
  }
}

const closeIssueModal = () => {
  showIssueModal.value = false
  selectedIssue.value = null
}

const getStatusClass = (status: string) => {
  const statusLower = status.toLowerCase()
  if (statusLower.includes('done') || statusLower.includes('closed') || statusLower.includes('resolved')) {
    return 'bg-green-100 text-green-800'
  }
  if (statusLower.includes('progress') || statusLower.includes('review')) {
    return 'bg-blue-100 text-blue-800'
  }
  if (statusLower.includes('todo') || statusLower.includes('open') || statusLower.includes('new')) {
    return 'bg-gray-100 text-gray-800'
  }
  return 'bg-gray-100 text-gray-800'
}

const formatDate = (dateString: string) => {
  if (!dateString) return null
  return new Date(dateString).toLocaleDateString()
}

const formatDateTime = (dateString: string) => {
  return new Date(dateString).toLocaleString()
}

// Watch for pagination changes
watch(() => pagination.value.per_page, () => {
  pagination.value.page = 1
  loadData()
})

// Load data on mount
onMounted(() => {
  loadData()
})
</script> 