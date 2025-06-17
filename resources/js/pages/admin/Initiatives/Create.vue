<template>
  <AppLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Create Initiative
          </h2>
          <p class="text-sm text-gray-600 mt-1">
            Create a new client initiative with project filters
          </p>
        </div>
        <router-link
          :href="route('admin.initiatives.index')"
          class="text-gray-600 hover:text-gray-800 transition-colors duration-200"
        >
          ← Back to Initiatives
        </router-link>
      </div>
    </template>

    <div class="py-6">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <form @submit.prevent="submit" class="space-y-6">
          <!-- Basic Information -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
              
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Initiative Name *
                  </label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    :class="{ 'border-red-500': errors.name }"
                    placeholder="e.g., SO Initiative"
                  />
                  <p v-if="errors.name" class="text-red-500 text-sm mt-1">{{ errors.name }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Hourly Rate
                  </label>
                  <div class="relative">
                    <span class="absolute left-3 top-2 text-gray-500">$</span>
                    <input
                      v-model="form.hourly_rate"
                      type="number"
                      step="0.01"
                      min="0"
                      max="9999.99"
                      class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      :class="{ 'border-red-500': errors.hourly_rate }"
                      placeholder="0.00"
                    />
                  </div>
                  <p v-if="errors.hourly_rate" class="text-red-500 text-sm mt-1">{{ errors.hourly_rate }}</p>
                </div>
              </div>

              <div class="mt-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Description
                </label>
                <textarea
                  v-model="form.description"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.description }"
                  placeholder="Brief description of this initiative..."
                />
                <p v-if="errors.description" class="text-red-500 text-sm mt-1">{{ errors.description }}</p>
              </div>

              <div class="mt-6">
                <label class="flex items-center">
                  <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 focus:ring-offset-0"
                  />
                  <span class="ml-2 text-sm text-gray-700">Active (initiative is available for use)</span>
                </label>
              </div>
            </div>
          </div>

          <!-- Project Filters -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <div class="flex justify-between items-center mb-4">
                <div>
                  <h3 class="text-lg font-medium text-gray-900">Project Filters</h3>
                  <p class="text-sm text-gray-600">Define which projects and criteria contribute to this initiative</p>
                </div>
                <button
                  type="button"
                  @click="addProjectFilter"
                  class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition-colors duration-200"
                >
                  Add Filter
                </button>
              </div>

              <div v-if="form.project_filters.length === 0" class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg">
                <Icon name="filter" class="w-8 h-8 text-gray-400 mx-auto mb-2" />
                <p class="text-gray-500">No project filters defined</p>
                <p class="text-sm text-gray-400 mb-4">Add at least one filter to define what work contributes to this initiative</p>
                <button
                  type="button"
                  @click="addProjectFilter"
                  class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded transition-colors duration-200"
                >
                  Add First Filter
                </button>
              </div>

              <div v-else class="space-y-4">
                <div
                  v-for="(filter, index) in form.project_filters"
                  :key="index"
                  class="border border-gray-200 rounded-lg p-4 relative"
                >
                  <button
                    type="button"
                    @click="removeProjectFilter(index)"
                    class="absolute top-2 right-2 text-gray-400 hover:text-red-500 transition-colors duration-200"
                  >
                    <Icon name="x" class="w-4 h-4" />
                  </button>

                  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">
                        Project *
                      </label>
                      <select
                        v-model="filter.jira_project_id"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors[`project_filters.${index}.jira_project_id`] }"
                      >
                        <option value="">Select a project</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                          {{ project.name }} ({{ project.project_key }})
                        </option>
                      </select>
                      <p v-if="errors[`project_filters.${index}.jira_project_id`]" class="text-red-500 text-xs mt-1">
                        {{ errors[`project_filters.${index}.jira_project_id`] }}
                      </p>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">
                        Required Labels
                      </label>
                      <input
                        v-model="filter.labels_input"
                        type="text"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g., SO, client-work"
                        @input="updateLabels(filter, index)"
                      />
                      <p class="text-xs text-gray-500 mt-1">Comma-separated labels (optional)</p>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">
                        Epic Key
                      </label>
                      <input
                        v-model="filter.epic_key"
                        type="text"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g., PROJ-123"
                      />
                      <p class="text-xs text-gray-500 mt-1">Specific epic (optional)</p>
                    </div>
                  </div>

                  <div v-if="filter.required_labels?.length" class="mt-3">
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
                </div>
              </div>

              <p v-if="errors.project_filters" class="text-red-500 text-sm mt-2">{{ errors.project_filters }}</p>
            </div>
          </div>

          <!-- Form Actions -->
          <div class="flex justify-end gap-4">
            <router-link
              :href="route('admin.initiatives.index')"
              class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition-colors duration-200"
            >
              Cancel
            </router-link>
            <button
              type="submit"
              :disabled="processing"
              class="px-6 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
            >
              {{ processing ? 'Creating...' : 'Create Initiative' }}
            </button>
          </div>
        </form>
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
  projects: Array,
  errors: Object
})

const processing = ref(false)

const form = reactive({
  name: '',
  description: '',
  hourly_rate: '',
  is_active: true,
  project_filters: []
})

const addProjectFilter = () => {
  form.project_filters.push({
    jira_project_id: '',
    required_labels: [],
    labels_input: '',
    epic_key: ''
  })
}

const removeProjectFilter = (index) => {
  form.project_filters.splice(index, 1)
}

const updateLabels = (filter, index) => {
  if (filter.labels_input) {
    filter.required_labels = filter.labels_input
      .split(',')
      .map(label => label.trim())
      .filter(label => label.length > 0)
  } else {
    filter.required_labels = []
  }
}

const submit = () => {
  processing.value = true
  
  // Prepare form data
  const formData = {
    ...form,
    project_filters: form.project_filters.map(filter => ({
      jira_project_id: parseInt(filter.jira_project_id),
      required_labels: filter.required_labels,
      epic_key: filter.epic_key || null
    }))
  }

  router.post(route('admin.initiatives.store'), formData, {
    onFinish: () => {
      processing.value = false
    }
  })
}

// Add initial filter
addProjectFilter()
</script>