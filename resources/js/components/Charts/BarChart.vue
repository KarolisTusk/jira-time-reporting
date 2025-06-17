<template>
  <div class="w-full h-full">
    <Bar
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
  BarElement,
  CategoryScale,
  LinearScale,
  type ChartData,
  type ChartOptions,
  type Plugin
} from 'chart.js'
import { Bar } from 'vue-chartjs'

// Register Chart.js components
ChartJS.register(
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale
)

// Define props
interface Props {
  data: ChartData<'bar'>
  options?: ChartOptions<'bar'>
  plugins?: Plugin<'bar'>[]
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
const chartOptions = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top' as const,
      labels: {
        font: {
          size: 12
        }
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
      callbacks: {
        label: (context) => {
          let label = context.dataset.label || ''
          if (label) {
            label += ': '
          }
          if (context.parsed.y !== null) {
            label += context.parsed.y.toFixed(2)
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
        }
      }
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