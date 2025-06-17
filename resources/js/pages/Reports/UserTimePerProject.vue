<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="User Time per Project Report" />
    
    <div class="space-y-6">
      <div class="flex justify-between items-center">
        <h1 class="text-2xl font-semibold text-gray-900">User Time per Project Report</h1>
      </div>

      <!-- Filters -->
      <Card>
        <CardContent class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <Label for="project_id">Project</Label>
              <select
                id="project_id"
                v-model="filters.project_id"
                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <option value="">All Projects</option>
                <option
                  v-for="project in availableProjects"
                  :key="project.id"
                  :value="project.id.toString()"
                >
                  {{ project.name }} ({{ project.project_key }})
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
                <div class="text-sm text-gray-500">Total Users</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_users }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Total Time</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_time_formatted }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Total Worklogs</div>
                <div class="text-2xl font-semibold">{{ reportData.summary.total_worklogs }}</div>
              </div>
              <div>
                <div class="text-sm text-gray-500">Average per User</div>
                <div class="text-2xl font-semibold">
                  {{ reportData.summary.total_users > 0 
                    ? Math.round(reportData.summary.total_time_seconds / reportData.summary.total_users / 3600) + ' hrs'
                    : '0 hrs' 
                  }}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Chart -->
        <Card v-if="reportData.chart">
          <CardHeader>
            <CardTitle>Time Distribution by User</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="h-96">
              <BarChart :data="reportData.chart" />
            </div>
          </CardContent>
        </Card>

        <!-- Table -->
        <Card v-if="reportData.table && reportData.table.length > 0">
          <CardHeader>
            <CardTitle>User Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="border-b">
                    <th class="text-left p-2">User Name</th>
                    <th class="text-left p-2">Email</th>
                    <th class="text-left p-2">Project</th>
                    <th class="text-left p-2">Total Time</th>
                    <th class="text-left p-2">Worklogs</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in reportData.table" :key="`${row.user_name}-${row.project_key}`" class="border-b">
                    <td class="p-2 font-medium">{{ row.user_name }}</td>
                    <td class="p-2">{{ row.email_address }}</td>
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
          <p class="text-gray-500">No data available. Please select a project and adjust your filters.</p>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import BarChart from '@/components/Charts/BarChart.vue'
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
    project_id?: string
    start_date?: string
    end_date?: string
  }
  availableProjects?: any[]
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Reports',
    href: '#',
  },
  {
    title: 'User Time per Project',
    href: '/reports/user-time-per-project',
  },
]

const filters = ref({
  project_id: props.filters?.project_id || '',
  start_date: props.filters?.start_date || new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  end_date: props.filters?.end_date || new Date().toISOString().split('T')[0],
})

const reportData = ref(props.reportData)
const loading = ref(false)
const availableProjects = ref(props.availableProjects || [])

const loadData = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams(filters.value)
    const response = await fetch(`/api/reports/user-time-per-project-data?${params}`)
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