<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="Project Trend Report" />
    
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">Project Trend Report</h1>
      </div>

      <!-- Filters -->
      <Card>
        <CardContent class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
              <Label for="project_ids">Projects</Label>
              <select
                id="project_ids"
                v-model="selectedProjects"
                multiple
                class="mt-1 flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <option
                  v-for="project in availableProjects"
                  :key="project.id"
                  :value="project.id.toString()"
                >
                  {{ project.name }} ({{ project.project_key }})
                </option>
              </select>
              <p class="text-xs text-muted-foreground mt-1">{{ selectedProjectsText }}</p>
            </div>
            <div>
              <Label for="period">Period</Label>
              <select
                id="period"
                v-model="filters.period"
                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <option
                  v-for="period in availablePeriods"
                  :key="period.value"
                  :value="period.value"
                >
                  {{ period.label }}
                </option>
              </select>
            </div>
            <div>
              <Label for="start_date">Start Date</Label>
              <Input
                id="start_date"
                v-model="filters.start_date"
                type="date"
                class="mt-1"
              />
            </div>
            <div>
              <Label for="end_date">End Date</Label>
              <Input
                id="end_date"
                v-model="filters.end_date"
                type="date"
                class="mt-1"
              />
            </div>
            <div class="flex items-end">
              <Button
                @click="loadData"
                class="w-full"
                :disabled="loading"
              >
                Update Report
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Loading State -->
      <div v-if="loading" class="flex justify-center py-12">
        <div class="text-gray-500">Loading report data...</div>
      </div>

      <!-- Report Data -->
      <div v-else-if="reportData" class="space-y-6">
        <!-- Summary -->
        <Card v-if="reportData.summary">
          <CardContent class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <div class="text-sm text-gray-500">Total Periods</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_periods }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Total Projects</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_projects }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Total Time</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_time_formatted }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Total Worklogs</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_worklogs }}</div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Chart -->
        <Card v-if="reportData.chart">
          <CardHeader>
            <CardTitle>Project Time Trends</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-96">
              <LineChart :data="reportData.chart" />
            </div>
          </CardContent>
        </Card>

        <!-- Table -->
        <Card v-if="reportData.table && reportData.table.length > 0">
          <CardHeader>
            <CardTitle>Trend Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="border-b">
                    <th class="text-left p-2">Period</th>
                    <th class="text-left p-2">Project</th>
                    <th class="text-left p-2">Total Time</th>
                    <th class="text-left p-2">Worklogs</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, index) in reportData.table" :key="index" class="border-b">
                    <td class="p-2 font-medium">{{ row.period }}</td>
                    <td class="p-2">{{ row.project_name }} ({{ row.project_key }})</td>
                    <td class="p-2">{{ row.total_time_formatted }}</td>
                    <td class="p-2">{{ row.worklog_count }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- No Data State -->
      <Card v-else>
        <CardContent class="p-12 text-center">
          <p class="text-gray-500">No data available. Please select projects and adjust your filters.</p>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import LineChart from '@/components/Charts/LineChart.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
// import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
// import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { type BreadcrumbItem } from '@/types'

interface Props {
  reportData?: any
  filters?: {
    project_ids?: string[]
    period?: string
    start_date?: string
    end_date?: string
  }
  availableProjects?: any[]
  availablePeriods?: Array<{ value: string; label: string }>
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Reports',
    href: '#',
  },
  {
    title: 'Project Trends',
    href: '/reports/project-trend',
  },
]

const selectedProjects = ref<string[]>(props.filters?.project_ids?.map(id => id.toString()) || [])
const filters = ref({
  period: props.filters?.period || 'weekly',
  start_date: props.filters?.start_date || new Date(Date.now() - 90 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  end_date: props.filters?.end_date || new Date().toISOString().split('T')[0],
})

const selectedProjectsText = computed(() => {
  if (selectedProjects.value.length === 0) return 'Select projects'
  if (selectedProjects.value.length === 1) return '1 project selected'
  return `${selectedProjects.value.length} projects selected`
})

const reportData = ref(props.reportData)
const loading = ref(false)
const availableProjects = ref(props.availableProjects || [])
const availablePeriods = ref(props.availablePeriods || [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'yearly', label: 'Yearly' },
])

const loadData = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams({
      ...filters.value,
      project_ids: selectedProjects.value.join(',')
    })
    const response = await fetch(`/api/reports/project-trend-data?${params}`)
    const data = await response.json()
    if (data.success) {
      reportData.value = data.data
    }
  } catch (error) {
    console.error('Failed to load report data:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!reportData.value) {
    loadData()
  }
})
</script>