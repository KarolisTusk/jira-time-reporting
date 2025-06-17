<template>
  <Dialog :open="open" @update:open="$emit('close')">
    <DialogContent class="max-w-4xl max-h-[90vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <FileText class="w-5 h-5" />
          {{ issue.issue_key }}
        </DialogTitle>
        <DialogDescription>
          {{ issue.summary }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-6">
        <!-- Issue Details -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card>
            <CardHeader>
              <CardTitle class="text-base">Issue Information</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div>
                <label class="text-sm font-medium text-gray-600">Status</label>
                <div class="mt-1">
                  <Badge :variant="getStatusVariant(issue.status)">
                    {{ issue.status }}
                  </Badge>
                </div>
              </div>

              <div>
                <label class="text-sm font-medium text-gray-600">Project</label>
                <div class="mt-1">
                  <span class="font-medium">{{ issue.project.key }}</span>
                  <p class="text-sm text-gray-500">{{ issue.project.name }}</p>
                </div>
              </div>

              <div>
                <label class="text-sm font-medium text-gray-600">Assignee</label>
                <div class="mt-1">
                  <div v-if="issue.assignee">
                    <span class="font-medium">{{ issue.assignee.display_name }}</span>
                    <p class="text-sm text-gray-500">{{ issue.assignee.email_address }}</p>
                  </div>
                  <span v-else class="text-gray-400">Unassigned</span>
                </div>
              </div>

              <div v-if="issue.original_estimate_hours">
                <label class="text-sm font-medium text-gray-600">Original Estimate</label>
                <div class="mt-1">
                  <span class="font-medium">{{ issue.original_estimate_hours }}h</span>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle class="text-base">Time Tracking</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div>
                <label class="text-sm font-medium text-gray-600">Total Logged</label>
                <div class="mt-1">
                  <span class="text-2xl font-bold text-blue-600">{{ issue.total_logged_hours }}h</span>
                </div>
              </div>

              <div>
                <label class="text-sm font-medium text-gray-600">Worklogs Count</label>
                <div class="mt-1">
                  <span class="font-medium">{{ issue.worklogs.length }}</span>
                </div>
              </div>

              <div v-if="issue.original_estimate_hours">
                <label class="text-sm font-medium text-gray-600">Progress</label>
                <div class="mt-1">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                      <div 
                        class="h-2 rounded-full transition-all duration-300"
                        :class="getProgressBarClass(issue.total_logged_hours, issue.original_estimate_hours)"
                        :style="{ width: Math.min(100, (issue.total_logged_hours / issue.original_estimate_hours) * 100) + '%' }"
                      ></div>
                    </div>
                    <span class="text-sm font-medium">
                      {{ Math.round((issue.total_logged_hours / issue.original_estimate_hours) * 100) }}%
                    </span>
                  </div>
                </div>
              </div>

              <div>
                <label class="text-sm font-medium text-gray-600">Last Updated</label>
                <div class="mt-1">
                  <span class="text-sm">{{ formatDateTime(issue.updated_at) }}</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Worklogs -->
        <Card>
          <CardHeader>
            <div class="flex items-center justify-between">
              <CardTitle class="flex items-center gap-2">
                <Clock class="w-4 h-4" />
                Worklogs ({{ issue.worklogs.length }})
              </CardTitle>
              <div class="text-sm text-gray-600">
                Total: {{ issue.total_logged_hours }}h
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div v-if="issue.worklogs.length === 0" class="text-center py-8">
              <Clock class="w-12 h-12 mx-auto text-gray-400 mb-4" />
              <h3 class="text-lg font-medium text-gray-900 mb-2">No worklogs</h3>
              <p class="text-gray-600">This issue doesn't have any time logged yet.</p>
            </div>

            <div v-else class="space-y-3">
              <!-- Worklogs Summary by Author -->
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div v-for="authorSummary in worklogSummary" :key="authorSummary.author" class="bg-gray-50 p-3 rounded-lg">
                  <div class="font-medium">{{ authorSummary.author }}</div>
                  <div class="text-sm text-gray-600">{{ authorSummary.count }} entries</div>
                  <div class="text-lg font-bold text-blue-600">{{ authorSummary.hours }}h</div>
                </div>
              </div>

              <!-- Individual Worklogs -->
              <div class="space-y-2">
                <div v-for="worklog in sortedWorklogs" :key="worklog.id" 
                     class="flex items-center justify-between p-3 border rounded-lg hover:bg-gray-50">
                  <div class="flex items-center gap-3">
                    <div class="w-2 h-2 rounded-full" :class="getResourceTypeColor(worklog.resource_type)"></div>
                    <div>
                      <div class="font-medium">{{ worklog.author.display_name }}</div>
                      <div class="text-sm text-gray-600">{{ worklog.author.email_address }}</div>
                    </div>
                  </div>
                  
                  <div class="text-right">
                    <div class="font-bold text-blue-600">{{ worklog.time_spent_hours }}h</div>
                    <div class="text-sm text-gray-600">{{ formatDateTime(worklog.started_at) }}</div>
                    <div v-if="worklog.resource_type" class="text-xs">
                      <Badge variant="outline" class="text-xs">
                        {{ worklog.resource_type }}
                      </Badge>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Resource Type Distribution -->
        <Card v-if="resourceTypeDistribution.length > 0">
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Users class="w-4 h-4" />
              Resource Type Distribution
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div class="space-y-3">
              <div v-for="resource in resourceTypeDistribution" :key="resource.type" 
                   class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <div class="w-3 h-3 rounded-full" :class="getResourceTypeColor(resource.type)"></div>
                  <span class="font-medium capitalize">{{ resource.type || 'Unclassified' }}</span>
                </div>
                <div class="flex items-center gap-4">
                  <div class="text-right">
                    <div class="font-bold">{{ resource.hours }}h</div>
                    <div class="text-sm text-gray-600">{{ resource.count }} entries</div>
                  </div>
                  <div class="w-24 bg-gray-200 rounded-full h-2">
                    <div 
                      class="h-2 rounded-full"
                      :class="getResourceTypeColor(resource.type)"
                      :style="{ width: (resource.hours / issue.total_logged_hours) * 100 + '%' }"
                    ></div>
                  </div>
                  <div class="text-sm font-medium w-12 text-right">
                    {{ Math.round((resource.hours / issue.total_logged_hours) * 100) }}%
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <DialogFooter>
        <Button @click="$emit('close')" variant="outline">
          Close
        </Button>
        <Button @click="openInJira" variant="default">
          <ExternalLink class="w-4 h-4 mr-2" />
          Open in JIRA
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  FileText,
  Clock,
  Users,
  ExternalLink,
} from 'lucide-vue-next'

interface Worklog {
  id: number
  jira_id: string
  time_spent_hours: number
  started_at: string
  resource_type?: string
  author: {
    display_name: string
    email_address: string
  }
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
    display_name: string
    email_address: string
  }
  original_estimate_hours?: number
  worklogs: Worklog[]
  total_logged_hours: number
  created_at: string
  updated_at: string
}

interface Props {
  issue: Issue
  open: boolean
}

const props = defineProps<Props>()

defineEmits<{
  close: []
}>()

// Computed properties
const sortedWorklogs = computed(() => {
  return [...props.issue.worklogs].sort((a, b) => 
    new Date(b.started_at).getTime() - new Date(a.started_at).getTime()
  )
})

const worklogSummary = computed(() => {
  const summary = new Map<string, { count: number; hours: number }>()
  
  props.issue.worklogs.forEach(worklog => {
    const author = worklog.author.display_name
    const existing = summary.get(author) || { count: 0, hours: 0 }
    summary.set(author, {
      count: existing.count + 1,
      hours: existing.hours + worklog.time_spent_hours
    })
  })
  
  return Array.from(summary.entries())
    .map(([author, data]) => ({ author, ...data }))
    .sort((a, b) => b.hours - a.hours)
})

const resourceTypeDistribution = computed(() => {
  const distribution = new Map<string, { count: number; hours: number }>()
  
  props.issue.worklogs.forEach(worklog => {
    const type = worklog.resource_type || 'unclassified'
    const existing = distribution.get(type) || { count: 0, hours: 0 }
    distribution.set(type, {
      count: existing.count + 1,
      hours: existing.hours + worklog.time_spent_hours
    })
  })
  
  return Array.from(distribution.entries())
    .map(([type, data]) => ({ type, ...data }))
    .sort((a, b) => b.hours - a.hours)
})

// Methods
const getStatusVariant = (status: string) => {
  const statusLower = status.toLowerCase()
  if (statusLower.includes('done') || statusLower.includes('closed') || statusLower.includes('resolved')) {
    return 'default'
  }
  if (statusLower.includes('progress') || statusLower.includes('review')) {
    return 'secondary'
  }
  return 'outline'
}

const getProgressBarClass = (logged: number, estimate: number) => {
  const percentage = (logged / estimate) * 100
  if (percentage >= 100) return 'bg-red-500'
  if (percentage >= 80) return 'bg-yellow-500'
  return 'bg-green-500'
}

const getResourceTypeColor = (type?: string) => {
  switch (type) {
    case 'frontend': return 'bg-blue-500'
    case 'backend': return 'bg-green-500'
    case 'qa': return 'bg-purple-500'
    case 'devops': return 'bg-orange-500'
    case 'management': return 'bg-red-500'
    case 'architect': return 'bg-indigo-500'
    default: return 'bg-gray-500'
  }
}

const formatDateTime = (dateString: string) => {
  return new Date(dateString).toLocaleString()
}

const openInJira = () => {
  // This would need the JIRA base URL from settings
  // For now, we'll just show the issue key
  alert(`Open ${props.issue.issue_key} in JIRA`)
}
</script> 