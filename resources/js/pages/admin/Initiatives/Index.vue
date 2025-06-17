<template>
  <Head title="Manage Initiatives" />
  
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Initiative Management
          </h2>
          <p class="text-sm text-gray-600 mt-1">
            Manage client initiatives and project groupings
          </p>
        </div>
        <div class="flex gap-3">
          <Link
            :href="route('admin.initiatives.create')"
            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center gap-2"
          >
            <Plus class="w-4 h-4" />
            Create Initiative
          </Link>
        </div>
      </div>
    </template>

    <div class="py-6">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Filters -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6 border-b border-gray-200">
            <div class="flex flex-col sm:flex-row gap-4">
              <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Search
                </label>
                <input
                  v-model="filters.search"
                  type="text"
                  placeholder="Search initiatives..."
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  @input="debouncedSearch"
                />
              </div>
              <div class="sm:w-48">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Status
                </label>
                <select
                  v-model="filters.status"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                  @change="search"
                >
                  <option value="">All</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="flex items-end">
                <button
                  @click="clearFilters"
                  class="px-4 py-2 text-gray-600 hover:text-gray-800 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200"
                >
                  Clear
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Initiatives List -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Initiative
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Projects & Filters
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Hourly Rate
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Users
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="initiative in initiatives.data" :key="initiative.id" class="hover:bg-gray-50">
                  <td class="px-6 py-4">
                    <div>
                      <div class="text-sm font-medium text-gray-900">
                        {{ initiative.name }}
                      </div>
                      <div v-if="initiative.description" class="text-sm text-gray-500 mt-1">
                        {{ initiative.description }}
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="space-y-1">
                      <div
                        v-for="filter in initiative.project_filters"
                        :key="filter.id"
                        class="text-xs bg-gray-100 rounded px-2 py-1 inline-block mr-1"
                      >
                        {{ filter.jira_project?.name || 'Unknown Project' }}
                        <span v-if="filter.required_labels?.length" class="text-blue-600">
                          • {{ filter.required_labels.join(', ') }}
                        </span>
                        <span v-if="filter.epic_key" class="text-purple-600">
                          • Epic: {{ filter.epic_key }}
                        </span>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    <span v-if="initiative.hourly_rate" class="font-medium">
                      ${{ parseFloat(initiative.hourly_rate).toFixed(2) }}/hr
                    </span>
                    <span v-else class="text-gray-400">Not set</span>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-500">
                    {{ initiative.users_count }} user{{ initiative.users_count !== 1 ? 's' : '' }}
                  </td>
                  <td class="px-6 py-4">
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
                  </td>
                  <td class="px-6 py-4 text-right text-sm font-medium">
                    <div class="flex justify-end items-center gap-2">
                      <Link
                        :href="route('admin.initiatives.show', initiative.id)"
                        class="text-blue-600 hover:text-blue-900 transition-colors duration-200"
                      >
                        View
                      </Link>
                      <Link
                        :href="route('admin.initiatives.edit', initiative.id)"
                        class="text-gray-600 hover:text-gray-900 transition-colors duration-200"
                      >
                        Edit
                      </Link>
                      <button
                        @click="toggleStatus(initiative)"
                        :class="[
                          'transition-colors duration-200',
                          initiative.is_active
                            ? 'text-red-600 hover:text-red-900'
                            : 'text-green-600 hover:text-green-900'
                        ]"
                      >
                        {{ initiative.is_active ? 'Disable' : 'Enable' }}
                      </button>
                      <button
                        @click="confirmDelete(initiative)"
                        class="text-red-600 hover:text-red-900 transition-colors duration-200"
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Empty State -->
          <div v-if="initiatives.data.length === 0" class="text-center py-12">
            <Plus class="w-12 h-12 text-gray-400 mx-auto mb-4" />
            <h3 class="text-lg font-medium text-gray-900 mb-2">No initiatives found</h3>
            <p class="text-gray-500 mb-6">
              {{ hasFilters ? 'Try adjusting your search criteria.' : 'Get started by creating your first initiative.' }}
            </p>
            <Link
              v-if="!hasFilters"
              :href="route('admin.initiatives.create')"
              class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200"
            >
              Create Initiative
            </Link>
          </div>

          <!-- Pagination -->
          <div v-if="initiatives.data.length > 0" class="px-6 py-4 border-t border-gray-200">
            <div class="flex items-center justify-between">
              <div class="text-sm text-gray-700">
                Showing {{ initiatives.from }} to {{ initiatives.to }} of {{ initiatives.total }} results
              </div>
              <div class="flex space-x-1">
                <Link
                  v-for="link in initiatives.links"
                  :key="link.label"
                  :href="link.url"
                  :class="[
                    'px-3 py-2 text-sm border transition-colors duration-200',
                    link.active
                      ? 'bg-blue-500 text-white border-blue-500'
                      : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                  ]"
                  v-html="link.label"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, Link, Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Plus, Eye, Edit, Trash2 } from 'lucide-vue-next'

const props = defineProps({
  initiatives: Object,
  filters: Object
})

const filters = ref({
  search: props.filters.search || '',
  status: props.filters.status || ''
})

const hasFilters = computed(() => {
  return filters.value.search || filters.value.status
})

let searchTimeout = null

const debouncedSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    search()
  }, 300)
}

const search = () => {
  router.get(route('admin.initiatives.index'), {
    search: filters.value.search || undefined,
    status: filters.value.status || undefined
  }, {
    preserveState: true,
    replace: true
  })
}

const clearFilters = () => {
  filters.value.search = ''
  filters.value.status = ''
  search()
}

const toggleStatus = (initiative) => {
  router.patch(route('admin.initiatives.toggle-status', initiative.id), {}, {
    preserveScroll: true
  })
}

const confirmDelete = (initiative) => {
  if (confirm(`Are you sure you want to delete "${initiative.name}"? This action cannot be undone.`)) {
    router.delete(route('admin.initiatives.destroy', initiative.id))
  }
}
</script>