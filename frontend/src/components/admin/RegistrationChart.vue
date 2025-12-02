<template>
  <div class="surface-card rounded-lg p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
        <Icon icon="mdi:chart-line" class="w-5 h-5" />
        {{ $t('admin.analytics.registrations') }}
      </h3>
      
      <!-- Filters -->
      <div class="flex gap-2 flex-wrap">
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

        <select
          v-model="period"
          @change="$emit('update:period', period)"
          class="px-3 py-1.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          data-testid="select-period"
        >
          <option value="7d">{{ $t('admin.analytics.period.7d') }}</option>
          <option value="30d">{{ $t('admin.analytics.period.30d') }}</option>
          <option value="90d">{{ $t('admin.analytics.period.90d') }}</option>
          <option value="1y">{{ $t('admin.analytics.period.1y') }}</option>
          <option value="all">{{ $t('admin.analytics.period.all') }}</option>
        </select>
        
        <select
          v-model="groupBy"
          @change="$emit('update:groupBy', groupBy)"
          class="px-3 py-1.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          data-testid="select-group-by"
        >
          <option value="day">{{ $t('admin.analytics.groupBy.day') }}</option>
          <option value="week">{{ $t('admin.analytics.groupBy.week') }}</option>
          <option value="month">{{ $t('admin.analytics.groupBy.month') }}</option>
        </select>
      </div>
    </div>

    <!-- Chart -->
    <div class="relative h-[300px]">
      <Line v-if="chartType === 'line'" :data="lineChartData" :options="lineChartOptions" />
      <Bar v-else :data="barChartData" :options="barChartOptions" />
    </div>

    <!-- Legend -->
    <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
      <div
        v-for="(count, provider) in data.byProvider"
        :key="provider"
        class="flex items-center gap-2"
      >
        <div
          class="w-3 h-3 rounded-full"
          :style="{ backgroundColor: getProviderColor(provider) }"
        ></div>
        <span class="text-sm txt-secondary">{{ provider }}: <strong class="txt-primary">{{ count }}</strong></span>
      </div>
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
  type ChartOptions,
  type ChartData,
  type ChartDataset
} from 'chart.js'
import type { RegistrationAnalytics } from '@/services/api/adminApi'

// Register Chart.js components
ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Title, Tooltip, Legend, Filler)

interface Props {
  data: RegistrationAnalytics
  initialPeriod?: '7d' | '30d' | '90d' | '1y' | 'all'
  initialGroupBy?: 'day' | 'week' | 'month'
}

const props = withDefaults(defineProps<Props>(), {
  initialPeriod: '30d',
  initialGroupBy: 'day'
})

defineEmits<{
  'update:period': [value: string]
  'update:groupBy': [value: string]
}>()

const period = ref(props.initialPeriod)
const groupBy = ref(props.initialGroupBy)
const chartType = ref<'line' | 'bar'>('line')

// Provider colors
const providerColors: Record<string, string> = {
  'local': '#3b82f6',
  'google': '#ea4335',
  'github': '#24292e',
  'keycloak': '#008aaa',
  'email': '#10b981',
  'whatsapp': '#25d366',
}

const getProviderColor = (provider: string) => {
  return providerColors[provider.toLowerCase()] || '#6b7280'
}

const providers = computed(() => {
  const set = new Set<string>()
  props.data.timeline.forEach(item => {
    Object.keys(item.byProvider).forEach(p => set.add(p))
  })
  return Array.from(set)
})

const buildLineDatasets = (): ChartDataset<'line'>[] => {
  return providers.value.map(provider => {
    const color = getProviderColor(provider)
    return {
      label: provider,
      data: props.data.timeline.map(item => item.byProvider[provider] || 0),
      backgroundColor: `${color}33`,
      borderColor: color,
      borderWidth: 2,
      fill: true,
      tension: 0.4,
      type: 'line'
    }
  })
}

const buildBarDatasets = (): ChartDataset<'bar'>[] => {
  return providers.value.map(provider => {
    const color = getProviderColor(provider)
    return {
      label: provider,
      data: props.data.timeline.map(item => item.byProvider[provider] || 0),
      backgroundColor: color,
      borderColor: color,
      borderWidth: 0,
      type: 'bar'
    }
  })
}

const lineChartData = computed<ChartData<'line'>>(() => ({
  labels: props.data.timeline.map(item => formatLabel(item.date)),
  datasets: buildLineDatasets()
}))

const barChartData = computed<ChartData<'bar'>>(() => ({
  labels: props.data.timeline.map(item => formatLabel(item.date)),
  datasets: buildBarDatasets()
}))

const lineChartOptions = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index' as const,
    intersect: false,
  },
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      mode: 'index',
      intersect: false,
    }
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
      beginAtZero: true,
      ticks: {
        color: 'rgb(156, 163, 175)',
        precision: 0,
      },
      grid: {
        color: 'rgba(156, 163, 175, 0.1)',
      }
    }
  }
}))

const barChartOptions = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index' as const,
    intersect: false,
  },
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      mode: 'index',
      intersect: false,
    }
  },
  scales: {
    x: {
      stacked: true,
      grid: {
        display: false,
      },
      ticks: {
        color: 'rgb(156, 163, 175)',
      }
    },
    y: {
      stacked: true,
      beginAtZero: true,
      ticks: {
        color: 'rgb(156, 163, 175)',
        precision: 0,
      },
      grid: {
        color: 'rgba(156, 163, 175, 0.1)',
      }
    }
  }
}))

const formatLabel = (dateStr: string) => {
  // Format based on groupBy
  if (groupBy.value === 'month') {
    const [year, month] = dateStr.split('-')
    return `${month}/${year}`
  } else if (groupBy.value === 'week') {
    return dateStr.replace('W', 'Week ')
  } else {
    const [, month, day] = dateStr.split('-')
    return `${day}.${month}`
  }
}
</script>

