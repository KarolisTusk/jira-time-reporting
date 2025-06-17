<template>
  <div class="w-full h-full">
    <Line
      :data="chartData"
      :options="chartOptions"
      :plugins="plugins"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Filler,
  type ChartData,
  type ChartOptions,
  type Plugin
} from 'chart.js'
import { Line } from 'vue-chartjs'

// Register Chart.js components
ChartJS.register(
  Title,
  Tooltip,
  Legend,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Filler
)

// Define props
interface Props {
  data: ChartData<'line'>
  options?: ChartOptions<'line'>
  plugins?: Plugin<'line'>[]
  height?: string
}

const props = withDefaults(defineProps<Props>(), {
  options: () => ({}),
  plugins: () => [],
  height: '400px'
})

// Computed chart data
const chartData = computed(() => props.data)

// Merge default options with provided options
const chartOptions = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index' as const,
    intersect: false
  },
  plugins: {
    legend: {
      position: 'top' as const,
      labels: {
        font: {
          size: 12
        },
        usePointStyle: true,
        padding: 15
      }
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
      titleFont: {
        size: 13
      },
      bodyFont: {
        size: 12
      },
      cornerRadius: 4,
      displayColors: true,
      mode: 'index' as const,
      intersect: false,
      callbacks: {
        label: (context) => {
          let label = context.dataset.label || ''
          if (label) {
            label += ': '
          }
          if (context.parsed.y !== null) {
            label += context.parsed.y.toFixed(2) + ' hours'
          }
          return label
        }
      }
    }
  },
  scales: {
    x: {
      grid: {
        display: false
      },
      ticks: {
        font: {
          size: 11
        }
      }
    },
    y: {
      beginAtZero: true,
      grid: {
        color: 'rgba(0, 0, 0, 0.05)'
      },
      ticks: {
        font: {
          size: 11
        },
        callback: function(value) {
          return value + ' hrs'
        }
      }
    }
  },
  elements: {
    line: {
      tension: 0.3,
      borderWidth: 2
    },
    point: {
      radius: 3,
      hoverRadius: 5,
      hitRadius: 10
    }
  },
  ...props.options
}))
</script>

<style scoped>
/* Ensure the chart container maintains proper dimensions */
div {
  position: relative;
}
</style> 