<template>
  <AppLayout>
    <template #header>
      <div>
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          My Initiatives
        </h2>
        <p class="text-sm text-gray-600 mt-1">
          Track development time and costs for your projects
        </p>
      </div>
    </template>

    <div class="py-6">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <!-- No Initiatives State -->
        <div v-if="initiatives.length === 0" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-12 text-center">
            <Icon name="folder-open" class="w-16 h-16 text-gray-400 mx-auto mb-4" />
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Initiatives Available</h3>
            <p class="text-gray-500 mb-6">
              You don't have access to any initiatives yet. Contact your administrator to get access to initiative reporting.
            </p>
          </div>
        </div>

        <!-- Initiatives Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div
            v-for="initiative in initiatives"
            :key="initiative.id"
            class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition-shadow duration-200 cursor-pointer"
            @click="viewInitiative(initiative)"
          >
            <div class="p-6">
              <!-- Header -->
              <div class="flex justify-between items-start mb-4">
                <div>
                  <h3 class="text-lg font-medium text-gray-900 mb-1">
                    {{ initiative.name }}
                  </h3>
                  <div class="flex items-center gap-2">
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
                    <span
                      :class="[
                        'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                        initiative.access_type === 'admin'
                          ? 'bg-red-100 text-red-800'
                          : 'bg-blue-100 text-blue-800'
                      ]"
                    >
                      {{ initiative.access_type }}
                    </span>
                  </div>
                </div>
                <button
                  @click.stop="viewInitiative(initiative)"
                  class="text-blue-600 hover:text-blue-800 transition-colors duration-200"
                >
                  <Icon name="arrow-right" class="w-5 h-5" />
                </button>
              </div>

              <!-- Description -->
              <div v-if="initiative.description" class="mb-4">
                <p class="text-sm text-gray-600 line-clamp-2">{{ initiative.description }}</p>
              </div>

              <!-- Metrics -->
              <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="text-center">
                  <div class="text-2xl font-bold text-blue-600">
                    {{ initiative.metrics.total_hours || 0 }}
                  </div>
                  <div class="text-xs text-gray-500">Total Hours</div>
                </div>
                <div class="text-center">
                  <div class="text-2xl font-bold text-green-600">
                    ${{ (initiative.metrics.total_cost || 0).toFixed(0) }}
                  </div>
                  <div class="text-xs text-gray-500">Total Cost</div>
                </div>
              </div>

              <!-- Project Filters Summary -->
              <div class="mb-4">
                <div class="text-xs text-gray-500 mb-2">Projects:</div>
                <div class="space-y-1">
                  <div
                    v-for="filter in initiative.project_filters.slice(0, 2)"
                    :key="filter.jira_project.project_key"
                    class="text-xs bg-gray-100 rounded px-2 py-1"
                  >
                    {{ filter.jira_project.name }}
                    <span v-if="filter.required_labels?.length" class="text-blue-600">
                      • {{ filter.required_labels.slice(0, 2).join(', ') }}
                      <span v-if="filter.required_labels.length > 2">+{{ filter.required_labels.length - 2 }}</span>
                    </span>
                    <span v-if="filter.epic_key" class="text-purple-600">
                      • {{ filter.epic_key }}
                    </span>
                  </div>
                  <div v-if="initiative.project_filters.length > 2" class="text-xs text-gray-400">
                    +{{ initiative.project_filters.length - 2 }} more project{{ initiative.project_filters.length - 2 !== 1 ? 's' : '' }}
                  </div>
                </div>
              </div>

              <!-- Monthly Activity -->
              <div v-if="Object.keys(initiative.metrics.monthly_breakdown || {}).length > 0">
                <div class="text-xs text-gray-500 mb-2">Recent Activity:</div>
                <div class="flex justify-between text-xs">
                  <span class="text-gray-600">This Month</span>
                  <span class="font-medium">
                    {{ getCurrentMonthHours(initiative.metrics.monthly_breakdown) }}h
                  </span>
                </div>
              </div>

              <!-- Hourly Rate -->
              <div v-if="initiative.hourly_rate" class="mt-4 pt-4 border-t border-gray-200">
                <div class="flex justify-between items-center text-sm">
                  <span class="text-gray-500">Rate:</span>
                  <span class="font-medium text-gray-900">
                    ${{ parseFloat(initiative.hourly_rate).toFixed(2) }}/hour
                  </span>
                </div>
              </div>

              <!-- Quick Actions -->
              <div class="mt-4 pt-4 border-t border-gray-200 flex gap-2">
                <button
                  @click.stop="viewInitiative(initiative)"
                  class="flex-1 bg-blue-50 text-blue-700 text-sm py-2 px-3 rounded hover:bg-blue-100 transition-colors duration-200"
                >
                  View Details
                </button>
                <button
                  @click.stop="exportInitiative(initiative)"
                  class="bg-gray-50 text-gray-700 text-sm py-2 px-3 rounded hover:bg-gray-100 transition-colors duration-200"
                  title="Export Data"
                >
                  <Icon name="download" class="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Activity Summary -->
        <div v-if="initiatives.length > 0" class="mt-8 bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Activity Summary</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div class="text-center">
                <div class="text-3xl font-bold text-blue-600">{{ totalHours }}</div>
                <div class="text-sm text-gray-500">Total Hours Across All Initiatives</div>
              </div>
              <div class="text-center">
                <div class="text-3xl font-bold text-green-600">${{ totalCost.toFixed(0) }}</div>
                <div class="text-sm text-gray-500">Total Development Cost</div>
              </div>
              <div class="text-center">
                <div class="text-3xl font-bold text-purple-600">{{ activeInitiatives }}</div>
                <div class="text-sm text-gray-500">Active Initiatives</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Icon from '@/components/Icon.vue'

const props = defineProps({
  initiatives: Array
})

const totalHours = computed(() => {
  return props.initiatives.reduce((sum, initiative) => {
    return sum + (initiative.metrics.total_hours || 0)
  }, 0)
})

const totalCost = computed(() => {
  return props.initiatives.reduce((sum, initiative) => {
    return sum + (initiative.metrics.total_cost || 0)
  }, 0)
})

const activeInitiatives = computed(() => {
  return props.initiatives.filter(initiative => initiative.is_active).length
})

const getCurrentMonthHours = (monthlyBreakdown) => {
  const now = new Date()
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  return monthlyBreakdown[currentMonth]?.hours || 0
}

const viewInitiative = (initiative) => {
  router.visit(route('initiatives.show', initiative.id))
}

const exportInitiative = (initiative) => {
  // TODO: Implement export functionality
  window.open(route('initiatives.export', initiative.id), '_blank')
}
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>