<template>
  <div class="surface-card rounded-lg p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
        <Icon icon="mdi:chart-bar" class="w-5 h-5" />
        {{ $t('admin.analytics.usageByAction') }}
      </h3>

      <!-- Chart Type Toggle -->
      <div class="flex gap-1 bg-chat rounded-lg p-1 border border-light-border/30 dark:border-dark-border/20">
        <button
          @click="chartType = 'line'"
          :class="[
            'px-3 py-1.5 rounded text-xs font-medium transition-all',
            chartType === 'line' 
              ? 'bg-[var(--brand)] text-white' 
              : 'txt-secondary hover:txt-primary'
          ]"
          data-testid="btn-chart-type-line"
        >
          <Icon icon="mdi:chart-line" class="w-4 h-4" />
        </button>
        <button
          @click="chartType = 'bar'"
          :class="[
            'px-3 py-1.5 rounded text-xs font-medium transition-all',
            chartType === 'bar' 
              ? 'bg-[var(--brand)] text-white' 
              : 'txt-secondary hover:txt-primary'
          ]"
          data-testid="btn-chart-type-bar"
        >
          <Icon icon="mdi:chart-bar" class="w-4 h-4" />
        </button>
      </div>
    </div>

    <!-- Chart -->
    <div class="relative h-[300px]">
      <component :is="chartType === 'line' ? Line : Bar" :data="chartData" :options="chartOptions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Icon } from '@iconify/vue'
import { Bar, Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  Title,
  Tooltip,
  Legend,
  Filler,
  type ChartOptions
} from 'chart.js'
import { useI18n } from 'vue-i18n'

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Title, Tooltip, Legend, Filler)

const { t } = useI18n()

interface Props {
  data: Record<string, { count: number; tokens: number; cost: number }>
}

const props = defineProps<Props>()

const chartType = ref<'line' | 'bar'>('line')

// Action colors
const actionColors: Record<string, string> = {
  'chat': '#3b82f6',
  'image_generation': '#ec4899',
  'video_generation': '#8b5cf6',
  'embedding': '#10b981',
  'search': '#f59e0b',
  'file_analysis': '#06b6d4',
}

const getActionColor = (action: string) => {
  return actionColors[action.toLowerCase()] || '#6b7280'
}

// Chart data
const chartData = computed(() => {
  const labels = Object.keys(props.data)
  const counts = labels.map(action => props.data[action].count)
  const tokens = labels.map(action => props.data[action].tokens)

  return {
    labels: labels.map(l => l.replace('_', ' ').toUpperCase()),
    datasets: [
      {
        label: t('admin.usage.requests'),
        data: counts,
        backgroundColor: chartType.value === 'line' ? 'rgba(59, 130, 246, 0.2)' : 'rgba(59, 130, 246, 1)',
        borderColor: 'rgb(59, 130, 246)',
        borderWidth: chartType.value === 'line' ? 3 : 0,
        fill: chartType.value === 'line',
        tension: 0.4,
        yAxisID: 'y',
      },
      {
        label: t('admin.usage.tokens'),
        data: tokens,
        backgroundColor: chartType.value === 'line' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(16, 185, 129, 1)',
        borderColor: 'rgb(16, 185, 129)',
        borderWidth: chartType.value === 'line' ? 3 : 0,
        fill: chartType.value === 'line',
        tension: 0.4,
        yAxisID: 'y1',
      }
    ]
  }
})

// Chart options
const chartOptions = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index',
    intersect: false,
  },
  scales: {
    x: {
      grid: {
        display: false,
      },
      ticks: {
        color: 'rgb(156, 163, 175)',
      }
    },
    y: {
      type: 'linear',
      display: true,
      position: 'left',
      beginAtZero: true,
      ticks: {
        color: 'rgb(156, 163, 175)',
        precision: 0,
      },
      grid: {
        color: 'rgba(156, 163, 175, 0.1)',
      },
      title: {
        display: true,
        text: t('admin.usage.requests'),
        color: 'rgb(156, 163, 175)',
      }
    },
    y1: {
      type: 'linear',
      display: true,
      position: 'right',
      beginAtZero: true,
      grid: {
        drawOnChartArea: false,
      },
      ticks: {
        color: 'rgb(156, 163, 175)',
        precision: 0,
      },
      title: {
        display: true,
        text: t('admin.usage.tokens'),
        color: 'rgb(156, 163, 175)',
      }
    }
  },
  plugins: {
    legend: {
      display: true,
      position: 'top',
      labels: {
        color: 'rgb(156, 163, 175)',
      }
    },
    tooltip: {
      mode: 'index',
      intersect: false,
    }
  }
}))
</script>

