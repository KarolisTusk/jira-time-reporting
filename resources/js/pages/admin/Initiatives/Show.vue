<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ initiative.name }}
          </h2>
          <div class="flex items-center gap-4 mt-1">
            <span
              :class="[
                'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                initiative.is_active
                  ? 'bg-green-100 text-green-800'
                  : 'bg-red-100 text-red-800'
              ]"
            >
              {{ initiative.is_active ? 'Active' : 'Inactive' }}
            </span>
            <span v-if="initiative.hourly_rate" class="text-sm text-gray-600">
              ${{ parseFloat(initiative.hourly_rate).toFixed(2) }}/hour
            </span>
          </div>
        </div>
        <div class="flex gap-3">
          <router-link
            :href="route('admin.initiatives.edit', initiative.id)"
            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200"
          >
            Edit Initiative
          </router-link>
          <router-link
            :href="route('admin.initiatives.index')"
            class="text-gray-600 hover:text-gray-800 transition-colors duration-200"
          >
            ← Back to Initiatives
          </router-link>
        </div>
      </div>
    </template>

    <div class="py-6">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        
        <!-- Description -->
        <div v-if="initiative.description" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-2">Description</h3>
            <p class="text-gray-700">{{ initiative.description }}</p>
          </div>
        </div>

        <!-- Project Filters -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Project Filters</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div
                v-for="filter in initiative.project_filters"
                :key="filter.id"
                class="border border-gray-200 rounded-lg p-4"
              >
                <div class="font-medium text-gray-900 mb-2">
                  {{ filter.jira_project?.name || 'Unknown Project' }}
                </div>
                <div class="text-sm text-gray-600 mb-2">
                  Key: {{ filter.jira_project?.project_key || 'Unknown' }}
                </div>
                <div v-if="filter.required_labels?.length" class="mb-2">
                  <div class="text-xs text-gray-500 mb-1">Required Labels:</div>
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="label in filter.required_labels"
                      :key="label"
                      class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded"
                    >
                      {{ label }}
                    </span>
                  </div>
                </div>
                <div v-if="filter.epic_key" class="mb-2">
                  <div class="text-xs text-gray-500 mb-1">Epic:</div>
                  <span class="inline-flex items-center px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded">
                    {{ filter.epic_key }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Metrics Overview -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-medium text-gray-900">Metrics Overview</h3>
              <div class="flex gap-2">
                <select
                  v-model="selectedTimeRange"
                  @change="updateMetrics"
                  class="px-3 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="all">All Time</option>
                  <option value="last30">Last 30 Days</option>
                  <option value="last90">Last 90 Days</option>
                  <option value="current_month">Current Month</option>
                  <option value="last_month">Last Month</option>
                </select>
                <button
                  @click="refreshMetrics"
                  class="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded transition-colors duration-200"
                >
                  Refresh
                </button>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
              <div class="text-center">
                <div class="text-2xl font-bold text-blue-600">{{ metrics.total_hours || 0 }}</div>
                <div class="text-sm text-gray-500">Total Hours</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-green-600">
                  ${{ (metrics.total_cost || 0).toFixed(2) }}
                </div>
                <div class="text-sm text-gray-500">Total Cost</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-purple-600">{{ metrics.total_issues || 0 }}</div>
                <div class="text-sm text-gray-500">Issues</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-orange-600">{{ initiative.users?.length || 0 }}</div>
                <div class="text-sm text-gray-500">Users</div>
              </div>
            </div>

            <!-- Monthly Breakdown Chart -->
            <div v-if="Object.keys(metrics.monthly_breakdown || {}).length > 0" class="mb-6">
              <h4 class="text-md font-medium text-gray-900 mb-3">Monthly Breakdown</h4>
              <div class="overflow-x-auto">
                <table class="min-w-full">
                  <thead>
                    <tr class="border-b border-gray-200">
                      <th class="text-left py-2 text-sm font-medium text-gray-700">Month</th>
                      <th class="text-right py-2 text-sm font-medium text-gray-700">Hours</th>
                      <th class="text-right py-2 text-sm font-medium text-gray-700">Cost</th>
                      <th class="text-right py-2 text-sm font-medium text-gray-700">Worklogs</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(data, month) in metrics.monthly_breakdown"
                      :key="month"
                      class="border-b border-gray-100"
                    >
                      <td class="py-2 text-sm text-gray-900">{{ formatMonth(data.year, data.month) }}</td>
                      <td class="py-2 text-sm text-gray-900 text-right">{{ data.hours }}</td>
                      <td class="py-2 text-sm text-green-600 text-right font-medium">${{ data.cost.toFixed(2) }}</td>
                      <td class="py-2 text-sm text-gray-500 text-right">{{ data.worklog_count }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Recent Activity -->
            <div v-if="metrics.recent_activity?.length > 0">
              <h4 class="text-md font-medium text-gray-900 mb-3">Recent Activity (Last 30 Days)</h4>
              <div class="space-y-2">
                <div
                  v-for="activity in metrics.recent_activity.slice(0, 5)"
                  :key="activity.date"
                  class="flex justify-between items-center py-2 border-b border-gray-100"
                >
                  <div class="text-sm text-gray-900">{{ formatDate(activity.date) }}</div>
                  <div class="flex gap-4 text-sm">
                    <span class="text-gray-600">{{ activity.hours }}h</span>
                    <span class="text-green-600 font-medium">${{ activity.cost.toFixed(2) }}</span>
                    <span class="text-gray-500">{{ activity.worklog_count }} entries</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Contributing Issues -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Contributing Issues</h3>
            
            <div v-if="contributingIssues.length === 0" class="text-center py-8 text-gray-500">
              No contributing issues found for the selected time period.
            </div>

            <div v-else class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issue</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Entries</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <tr v-for="issue in contributingIssues" :key="issue.issue_key" class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                      <div>
                        <div class="text-sm font-medium text-blue-600">{{ issue.issue_key }}</div>
                        <div class="text-sm text-gray-900 truncate max-w-xs">{{ issue.summary }}</div>
                        <div v-if="issue.labels?.length" class="flex flex-wrap gap-1 mt-1">
                          <span
                            v-for="label in issue.labels.slice(0, 3)"
                            :key="label"
                            class="inline-flex items-center px-1 py-0.5 text-xs bg-gray-100 text-gray-700 rounded"
                          >
                            {{ label }}
                          </span>
                          <span v-if="issue.labels.length > 3" class="text-xs text-gray-400">
                            +{{ issue.labels.length - 3 }} more
                          </span>
                        </div>
                      </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ issue.project_name }}</td>
                    <td class="px-4 py-3">
                      <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                        {{ issue.status }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900 text-right font-medium">{{ issue.hours }}</td>
                    <td class="px-4 py-3 text-sm text-green-600 text-right font-medium">${{ issue.cost.toFixed(2) }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500 text-right">{{ issue.worklog_count }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="contributingIssues.length === 10" class="mt-4 text-center">
              <p class="text-sm text-gray-500">Showing top 10 issues. Use exports for complete data.</p>
            </div>
          </div>
        </div>

        <!-- User Access -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">User Access</h3>
            
            <div v-if="initiative.users?.length === 0" class="text-center py-8 text-gray-500">
              No users have been assigned access to this initiative yet.
            </div>

            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div
                v-for="user in initiative.users"
                :key="user.id"
                class="border border-gray-200 rounded-lg p-4"
              >
                <div class="font-medium text-gray-900">{{ user.name }}</div>
                <div class="text-sm text-gray-600">{{ user.email }}</div>
                <div class="mt-2">
                  <span
                    :class="[
                      'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                      user.pivot.access_type === 'admin'
                        ? 'bg-red-100 text-red-800'
                        : 'bg-blue-100 text-blue-800'
                    ]"
                  >
                    {{ user.pivot.access_type }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

const props = defineProps({
  initiative: Object,
  metrics: Object,
  contributingIssues: Array
})

const selectedTimeRange = ref('all')
const metrics = reactive({ ...props.metrics })
const contributingIssues = ref([...props.contributingIssues])

const updateMetrics = () => {
  let startDate = null
  let endDate = null
  const now = new Date()

  switch (selectedTimeRange.value) {
    case 'last30':
      startDate = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
      break
    case 'last90':
      startDate = new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
      break
    case 'current_month':
      startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0]
      break
    case 'last_month':
      startDate = new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().split('T')[0]
      endDate = new Date(now.getFullYear(), now.getMonth(), 0).toISOString().split('T')[0]
      break
  }

  fetchMetrics(startDate, endDate)
}

const refreshMetrics = () => {
  updateMetrics()
}

const fetchMetrics = (startDate = null, endDate = null) => {
  const params = {}
  if (startDate) params.start_date = startDate
  if (endDate) params.end_date = endDate

  fetch(route('admin.initiatives.metrics', props.initiative.id) + '?' + new URLSearchParams(params))
    .then(response => response.json())
    .then(data => {
      Object.assign(metrics, data)
    })
    .catch(error => {
      console.error('Error fetching metrics:', error)
    })
}

const formatMonth = (year, month) => {
  const date = new Date(year, month - 1)
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
}

const formatDate = (dateString) => {
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>