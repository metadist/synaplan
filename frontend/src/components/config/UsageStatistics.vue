<template>
  <div class="space-y-6" data-testid="page-config-usage">
    <!-- Header -->
    <div class="flex items-center justify-between" data-testid="section-header">
      <div>
        <h2 class="text-xl font-semibold txt-primary mb-2">
          {{ $t('config.usage.title') }}
        </h2>
        <p class="text-sm txt-secondary">
          {{ $t('config.usage.description') }}
        </p>
      </div>
      
      <button
        @click="exportUsage"
        :disabled="loading || exporting"
        class="btn-secondary px-4 py-2 rounded-lg font-medium flex items-center gap-2 disabled:opacity-50"
        data-testid="btn-export"
      >
        <svg v-if="exporting" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        {{ $t('config.usage.export') }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12" data-testid="section-loading">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-brand"></div>
    </div>

    <!-- Error -->
    <div v-if="error" class="surface-card p-4 border-l-4 border-red-500" data-testid="alert-error">
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <!-- Stats Content -->
    <div v-if="!loading && stats" class="space-y-6" data-testid="section-stats">
      <!-- Subscription Info -->
      <div class="surface-card p-6" data-testid="section-subscription">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold txt-primary">
            {{ $t('config.usage.subscription') }}
          </h3>
          <span class="px-3 py-1 rounded-full text-xs font-medium"
                :class="getSubscriptionBadgeClass(stats.subscription.level)">
            {{ stats.subscription.plan_name }}
          </span>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.userLevel') }}</p>
            <p class="text-sm font-medium txt-primary">{{ stats.user_level }}</p>
          </div>
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.phoneVerified') }}</p>
            <p class="text-sm font-medium" :class="stats.phone_verified ? 'text-green-600' : 'text-red-600'">
              {{ stats.phone_verified ? $t('common.yes') : $t('common.no') }}
            </p>
          </div>
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.subscriptionActive') }}</p>
            <p class="text-sm font-medium" :class="stats.subscription.active ? 'text-green-600' : 'text-gray-600'">
              {{ stats.subscription.active ? $t('common.active') : $t('common.inactive') }}
            </p>
          </div>
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.totalRequests') }}</p>
            <p class="text-sm font-medium txt-primary">{{ stats.total_requests.toLocaleString() }}</p>
          </div>
        </div>
      </div>

      <!-- Usage per Action Type -->
      <div class="surface-card p-6" data-testid="section-usage-types">
        <h3 class="text-lg font-semibold txt-primary mb-4">
          {{ $t('config.usage.usageByType') }}
        </h3>
        
        <div class="space-y-4">
          <div v-for="(usage, action) in stats.usage" :key="action" class="space-y-2" data-testid="item-usage">
            <div class="flex items-center justify-between text-sm">
              <span class="txt-primary font-medium">{{ getActionLabel(action) }}</span>
              <span class="txt-secondary">
                {{ usage.used }} / {{ formatLimit(usage.limit) }}
                <span v-if="usage.type === 'lifetime'" class="text-xs">({{ $t('config.usage.lifetime') }})</span>
              </span>
            </div>
            
            <!-- Progress Bar -->
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
              <div class="h-2 rounded-full transition-all"
                   :class="getProgressBarClass(usage.used, usage.limit)"
                   :style="{ width: getProgressPercent(usage.used, usage.limit) + '%' }">
              </div>
            </div>
            
            <div class="flex items-center justify-between text-xs txt-secondary">
              <span>{{ $t('config.usage.remaining') }}: {{ usage.remaining.toLocaleString() }}</span>
              <span v-if="usage.resets_at">
                {{ $t('config.usage.resetsAt') }}: {{ formatResetTime(usage.resets_at) }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Breakdown by Source -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="surface-card p-6" data-testid="section-breakdown-source">
          <h3 class="text-lg font-semibold txt-primary mb-4">
            {{ $t('config.usage.bySource') }}
          </h3>
          
          <div class="space-y-3">
            <div v-for="(data, source) in stats.breakdown.by_source" :key="source"
                 class="flex items-center justify-between p-3 surface-chip rounded-lg"
                 data-testid="item-source">
              <div class="flex items-center gap-3">
                <span class="text-2xl">{{ getSourceIcon(source) }}</span>
                <div>
                  <p class="text-sm font-medium txt-primary">{{ getSourceLabel(source) }}</p>
                  <p class="text-xs txt-secondary">{{ Object.keys(data.actions).length }} {{ $t('config.usage.actionTypes') }}</p>
                </div>
              </div>
              <span class="text-lg font-semibold txt-primary">{{ data.total }}</span>
            </div>
            
            <div v-if="Object.keys(stats.breakdown.by_source).length === 0"
                 class="text-center py-8 txt-secondary text-sm">
              {{ $t('config.usage.noData') }}
            </div>
          </div>
        </div>

        <!-- Breakdown by Time -->
        <div class="surface-card p-6" data-testid="section-breakdown-time">
          <h3 class="text-lg font-semibold txt-primary mb-4">
            {{ $t('config.usage.byTime') }}
          </h3>
          
          <div class="space-y-3">
            <div v-for="(data, period) in stats.breakdown.by_time" :key="period"
                 class="flex items-center justify-between p-3 surface-chip rounded-lg"
                 data-testid="item-period">
              <div>
                <p class="text-sm font-medium txt-primary">{{ getTimePeriodLabel(period) }}</p>
                <p class="text-xs txt-secondary">{{ Object.keys(data.actions).length }} {{ $t('config.usage.actionTypes') }}</p>
              </div>
              <span class="text-lg font-semibold txt-primary">{{ data.total }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Usage -->
      <div class="surface-card p-6" data-testid="section-recent">
        <h3 class="text-lg font-semibold txt-primary mb-4">
          {{ $t('config.usage.recentActivity') }}
        </h3>
        
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs txt-secondary uppercase border-b border-light-border">
              <tr>
                <th class="px-4 py-3 text-left">{{ $t('config.usage.time') }}</th>
                <th class="px-4 py-3 text-left">{{ $t('config.usage.action') }}</th>
                <th class="px-4 py-3 text-left">{{ $t('config.usage.source') }}</th>
                <th class="px-4 py-3 text-right">{{ $t('config.usage.tokens') }}</th>
                <th class="px-4 py-3 text-right">{{ $t('config.usage.latency') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-light-border">
              <tr v-for="(entry, idx) in stats.recent_usage" :key="idx" class="hover:bg-black/5 dark:hover:bg-white/5" data-testid="item-activity">
                <td class="px-4 py-3 txt-secondary">{{ formatDateTime(entry.timestamp) }}</td>
                <td class="px-4 py-3">
                  <span class="px-2 py-1 rounded-full text-xs font-medium surface-chip">
                    {{ getActionLabel(entry.action) }}
                  </span>
                </td>
                <td class="px-4 py-3 txt-primary">{{ getSourceLabel(entry.source) }}</td>
                <td class="px-4 py-3 text-right txt-secondary">{{ entry.tokens.toLocaleString() }}</td>
                <td class="px-4 py-3 text-right txt-secondary">{{ entry.latency.toFixed(2) }}s</td>
              </tr>
              
              <tr v-if="stats.recent_usage.length === 0" data-testid="row-empty">
                <td colspan="5" class="px-4 py-8 text-center txt-secondary text-sm">
                  {{ $t('config.usage.noRecentActivity') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getUsageStats, downloadUsageExport, type UsageStats } from '@/api/usageApi'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'
import { authService } from '@/services/authService'

const { success, error: showError } = useNotification()
const { t } = useI18n()

const loading = ref(false)
const exporting = ref(false)
const error = ref<string | null>(null)
const stats = ref<UsageStats | null>(null)

const loadStats = async () => {
  try {
    loading.value = true
    error.value = null
    
    // Check if user is authenticated (user info in memory, not localStorage)
    if (!authService.isAuthenticated()) {
      error.value = 'Not authenticated. Please log in to view statistics.'
      loading.value = false
      return
    }
    
    stats.value = await getUsageStats()
  } catch (err: any) {
    console.error('Failed to load usage stats:', err)
    error.value = err.message || t('config.usage.errorLoading')
  } finally {
    loading.value = false
  }
}

const exportUsage = async () => {
  try {
    exporting.value = true
    await downloadUsageExport()
    success(t('config.usage.exportSuccess'))
  } catch (err: any) {
    console.error('Failed to export usage:', err)
    showError(err.message || t('config.usage.errorExporting'))
  } finally {
    exporting.value = false
  }
}

const getSubscriptionBadgeClass = (level: string) => {
  switch (level) {
    case 'BUSINESS':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-400'
    case 'TEAM':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400'
    case 'PRO':
      return 'bg-green-500/10 text-green-600 dark:text-green-400'
    case 'NEW':
      return 'bg-gray-500/10 text-gray-600 dark:text-gray-400'
    case 'ANONYMOUS':
      return 'bg-orange-500/10 text-orange-600 dark:text-orange-400'
    default:
      return 'bg-gray-500/10 text-gray-600 dark:text-gray-400'
  }
}

const getActionLabel = (action: string) => {
  return t(`config.usage.actions.${action.toLowerCase()}`, action)
}

const getSourceLabel = (source: string) => {
  return t(`config.usage.sources.${source.toLowerCase()}`, source)
}

const getSourceIcon = (source: string) => {
  switch (source.toUpperCase()) {
    case 'WHATSAPP':
      return '💬'
    case 'EMAIL':
      return '📧'
    case 'WEB':
      return '🌐'
    default:
      return '📱'
  }
}

const getTimePeriodLabel = (period: string) => {
  return t(`config.usage.periods.${period.toLowerCase()}`)
}

const formatLimit = (limit: number) => {
  if (limit === 9223372036854775807 || limit >= 1000000) {
    return '∞'
  }
  return limit.toLocaleString()
}

const getProgressPercent = (used: number, limit: number) => {
  if (limit === 0 || limit >= 1000000) return 0
  return Math.min(100, (used / limit) * 100)
}

const getProgressBarClass = (used: number, limit: number) => {
  const percent = getProgressPercent(used, limit)
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 75) return 'bg-orange-500'
  if (percent >= 50) return 'bg-yellow-500'
  return 'bg-green-500'
}

const formatResetTime = (timestamp: number) => {
  const date = new Date(timestamp * 1000)
  const now = new Date()
  const diff = date.getTime() - now.getTime()
  
  if (diff < 0) return t('common.now')
  
  const hours = Math.floor(diff / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
  
  if (hours > 24) {
    return date.toLocaleDateString()
  }
  
  if (hours > 0) {
    return `${hours}h ${minutes}m`
  }
  
  return `${minutes}m`
}

const formatDateTime = (timestamp: number) => {
  const date = new Date(timestamp * 1000)
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  
  // Less than 1 minute
  if (diff < 60000) {
    return t('common.justNow')
  }
  
  // Less than 1 hour
  if (diff < 3600000) {
    const minutes = Math.floor(diff / 60000)
    return t('common.minutesAgo', { count: minutes })
  }
  
  // Less than 24 hours
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000)
    return t('common.hoursAgo', { count: hours })
  }
  
  // More than 24 hours - show full date
  return date.toLocaleString()
}

onMounted(() => {
  loadStats()
})
</script>
