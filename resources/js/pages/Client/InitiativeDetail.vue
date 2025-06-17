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
            <span
              :class="[
                'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                access_type === 'admin'
                  ? 'bg-red-100 text-red-800'
                  : 'bg-blue-100 text-blue-800'
              ]"
            >
              {{ access_type }} access
            </span>
          </div>
        </div>
        <div class="flex gap-3">
          <button
            @click="exportData"
            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center gap-2"
          >
            <Icon name="download" class="w-4 h-4" />
            Export Data
          </button>
          <router-link
            :href="route('initiatives.index')"
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
            <h3 class="text-lg font-medium text-gray-900 mb-2">About This Initiative</h3>
            <p class="text-gray-700">{{ initiative.description }}</p>
          </div>
        </div>

        <!-- Date Filter -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex flex-col sm:flex-row gap-4 items-end">
              <div class="flex-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Time Period Filter</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                    <input
                      v-model="dateFilters.start_date"
                      type="date"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                    <input
                      v-model="dateFilters.end_date"
                      type="date"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                  <div class="flex gap-2">
                    <button
                      @click="applyDateFilter"
                      class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md transition-colors duration-200"
                    >
                      Apply
                    </button>
                    <button
                      @click="clearDateFilter"
                      class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition-colors duration-200"
                    >
                      Clear
                    </button>
                  </div>
                </div>
              </div>
              <div class="flex gap-2">
                <button
                  @click="setQuickFilter('last30')"
                  class="px-3 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200"
                >
                  Last 30 Days
                </button>
                <button
                  @click="setQuickFilter('last90')"
                  class="px-3 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200"
                >
                  Last 90 Days
                </button>
                <button
                  @click="setQuickFilter('current_month')"
                  class="px-3 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200"
                >
                  This Month
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Metrics Overview -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-6">Summary Metrics</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
              <div class="text-center">
                <div class="text-3xl font-bold text-blue-600">{{ metrics.total_hours || 0 }}</div>
                <div class="text-sm text-gray-500">Total Hours</div>
              </div>
              <div class="text-center">
                <div class="text-3xl font-bold text-green-600">
                  ${{ (metrics.total_cost || 0).toFixed(2) }}
                </div>
                <div class="text-sm text-gray-500">Total Cost</div>
              </div>
              <div class="text-center">
                <div class="text-3xl font-bold text-purple-600">{{ metrics.total_issues || 0 }}</div>
                <div class="text-sm text-gray-500">Issues Worked On</div>
              </div>
              <div class="text-center">
                <div class="text-3xl font-bold text-orange-600">
                  {{ Object.keys(metrics.monthly_breakdown || {}).length }}
                </div>
                <div class="text-sm text-gray-500">Active Months</div>
              </div>
            </div>

            <!-- Monthly Breakdown -->
            <div v-if="Object.keys(metrics.monthly_breakdown || {}).length > 0" class="mb-6">
              <h4 class="text-md font-medium text-gray-900 mb-4">Monthly Breakdown</h4>
              <div class="overflow-x-auto">
                <table class="min-w-full">
                  <thead>
                    <tr class="border-b border-gray-200">
                      <th class="text-left py-3 text-sm font-medium text-gray-700">Month</th>
                      <th class="text-right py-3 text-sm font-medium text-gray-700">Hours</th>
                      <th class="text-right py-3 text-sm font-medium text-gray-700">Cost</th>
                      <th class="text-right py-3 text-sm font-medium text-gray-700">Work Entries</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(data, month) in metrics.monthly_breakdown"
                      :key="month"
                      class="border-b border-gray-100 hover:bg-gray-50"
                    >
                      <td class="py-3 text-sm text-gray-900 font-medium">{{ formatMonth(data.year, data.month) }}</td>
                      <td class="py-3 text-sm text-gray-900 text-right">{{ data.hours }}</td>
                      <td class="py-3 text-sm text-green-600 text-right font-medium">${{ data.cost.toFixed(2) }}</td>
                      <td class="py-3 text-sm text-gray-500 text-right">{{ data.worklog_count }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Recent Activity -->
            <div v-if="metrics.recent_activity?.length > 0">
              <h4 class="text-md font-medium text-gray-900 mb-4">Recent Activity (Last 30 Days)</h4>
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                  v-for="activity in metrics.recent_activity"
                  :key="activity.date"
                  class="border border-gray-200 rounded-lg p-4"
                >
                  <div class="text-sm font-medium text-gray-900 mb-2">{{ formatDate(activity.date) }}</div>
                  <div class="space-y-1">
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Hours:</span>
                      <span class="font-medium">{{ activity.hours }}h</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Cost:</span>
                      <span class="font-medium text-green-600">${{ activity.cost.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Entries:</span>
                      <span class="text-gray-500">{{ activity.worklog_count }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Project Configuration -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Project Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div
                v-for="filter in initiative.project_filters"
                :key="filter.jira_project.project_key"
                class="border border-gray-200 rounded-lg p-4"
              >
                <div class="font-medium text-gray-900 mb-2">
                  {{ filter.jira_project.name }}
                </div>
                <div class="text-sm text-gray-600 mb-3">
                  Project Key: <span class="font-mono">{{ filter.jira_project.project_key }}</span>
                </div>
                <div v-if="filter.required_labels?.length" class="mb-3">
                  <div class="text-xs text-gray-500 mb-2">Required Labels:</div>
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
                <div v-if="filter.epic_key" class="mb-3">
                  <div class="text-xs text-gray-500 mb-2">Epic:</div>
                  <span class="inline-flex items-center px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded font-mono">
                    {{ filter.epic_key }}
                  </span>
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
              No issues found for the selected time period.
            </div>

            <div v-else class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issue</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <tr v-for="issue in contributingIssues" :key="issue.issue_key" class="hover:bg-gray-50">
                    <td class="px-4 py-4">
                      <div>
                        <div class="text-sm font-medium text-blue-600 font-mono">{{ issue.issue_key }}</div>
                        <div class="text-sm text-gray-900 truncate max-w-xs">{{ issue.summary }}</div>
                        <div v-if="issue.labels?.length" class="flex flex-wrap gap-1 mt-2">
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
                        <div v-if="issue.epic_key" class="mt-1">
                          <span class="inline-flex items-center px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded font-mono">
                            Epic: {{ issue.epic_key }}
                          </span>
                        </div>
                      </div>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ issue.project_name }}</td>
                    <td class="px-4 py-4">
                      <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                        {{ issue.status }}
                      </span>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-500">
                      {{ formatDate(issue.first_worklog) }} - {{ formatDate(issue.last_worklog) }}
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900 text-right font-medium">{{ issue.hours }}</td>
                    <td class="px-4 py-4 text-sm text-green-600 text-right font-medium">${{ issue.cost.toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="contributingIssues.length === 20" class="mt-4 text-center">
              <p class="text-sm text-gray-500">Showing top 20 issues. Use the export feature for complete data.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Icon from '@/components/Icon.vue'

const props = defineProps({
  initiative: Object,
  metrics: Object,
  contributingIssues: Array,
  access_type: String,
  filters: Object
})

const dateFilters = reactive({
  start_date: props.filters.start_date || '',
  end_date: props.filters.end_date || ''
})

const metrics = ref({ ...props.metrics })

const applyDateFilter = () => {
  router.get(route('initiatives.show', props.initiative.id), {
    start_date: dateFilters.start_date || undefined,
    end_date: dateFilters.end_date || undefined
  }, {
    preserveState: true
  })
}

const clearDateFilter = () => {
  dateFilters.start_date = ''
  dateFilters.end_date = ''
  router.get(route('initiatives.show', props.initiative.id), {}, {
    preserveState: true
  })
}

const setQuickFilter = (period) => {
  const now = new Date()
  let startDate = null
  let endDate = null

  switch (period) {
    case 'last30':
      startDate = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)
      break
    case 'last90':
      startDate = new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000)
      break
    case 'current_month':
      startDate = new Date(now.getFullYear(), now.getMonth(), 1)
      break
  }

  dateFilters.start_date = startDate ? startDate.toISOString().split('T')[0] : ''
  dateFilters.end_date = endDate ? endDate.toISOString().split('T')[0] : ''
  
  applyDateFilter()
}

const exportData = () => {
  const params = new URLSearchParams()
  if (dateFilters.start_date) params.append('start_date', dateFilters.start_date)
  if (dateFilters.end_date) params.append('end_date', dateFilters.end_date)
  
  window.open(route('initiatives.export', props.initiative.id) + '?' + params.toString(), '_blank')
}

const formatMonth = (year, month) => {
  const date = new Date(year, month - 1)
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
}

const formatDate = (dateString) => {
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>