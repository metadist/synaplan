<template>
  <div class="space-y-6" data-testid="page-config-usage">
    <!-- Header -->
    <div
      class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
      data-testid="section-header"
    >
      <div>
        <h2 class="text-xl font-semibold txt-primary mb-2">
          {{ $t('config.usage.title') }}
        </h2>
        <p class="text-sm txt-secondary">
          {{ $t('config.usage.description') }}
        </p>
      </div>

      <button
        :disabled="loading || exporting"
        class="w-full sm:w-auto btn-secondary px-4 py-2 rounded-lg font-medium flex items-center justify-center gap-2 disabled:opacity-50"
        data-testid="btn-export"
        @click="exportUsage"
      >
        <svg v-if="exporting" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          ></circle>
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          ></path>
        </svg>
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
          />
        </svg>
        {{ $t('config.usage.export') }}
      </button>
    </div>

    <!-- Loading -->
    <div
      v-if="loading"
      class="flex items-center justify-center py-12"
      data-testid="section-loading"
    >
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-brand"></div>
    </div>

    <!-- Error -->
    <div v-if="error" class="surface-card p-4 border-l-4 border-red-500" data-testid="alert-error">
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <!-- Stats Content -->
    <div v-if="!loading && stats" class="space-y-6" data-testid="section-stats">
      <!-- Cost Budget -->
      <div v-if="stats.cost_budget" class="surface-card p-6" data-testid="section-cost-budget">
        <h3 class="text-lg font-semibold txt-primary mb-4">
          {{ $t('config.usage.costBudget.title') }}
        </h3>

        <!-- With budget limit -->
        <div v-if="stats.cost_budget.budget > 0" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <span class="txt-primary font-medium">
              {{ $t('config.usage.costBudget.consumption') }}:
              {{ stats.cost_budget.percent.toFixed(1) }}%
            </span>
          </div>

          <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
            <div
              class="h-4 rounded-full transition-all flex items-center justify-center text-xs text-white font-medium"
              :class="getBudgetBarClass(stats.cost_budget.percent)"
              :style="{ width: Math.min(Math.max(stats.cost_budget.percent, 2), 100) + '%' }"
            >
              <span v-if="stats.cost_budget.percent > 15">
                {{ stats.cost_budget.percent.toFixed(0) }}%
              </span>
            </div>
          </div>

          <div class="text-xs txt-secondary text-right">
            {{ formatDate(stats.cost_budget.period_start) }} -
            {{ formatDate(stats.cost_budget.period_end) }}
          </div>
        </div>

        <!-- No budget limit (open source / unlimited) -->
        <div v-else class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <span class="txt-primary font-medium">
              {{ $t('config.usage.costBudget.thisMonth') }}
            </span>
            <span class="txt-secondary"> {{ stats.cost_budget.used.toFixed(4) }} EUR </span>
          </div>

          <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4">
            <div
              class="h-4 rounded-full transition-all bg-green-500 flex items-center justify-center text-xs text-white font-medium"
              style="width: 100%"
            >
              {{ $t('config.usage.costBudget.unlimited') }}
            </div>
          </div>

          <div class="text-xs txt-secondary">
            {{ formatDate(stats.cost_budget.period_start) }} -
            {{ formatDate(stats.cost_budget.period_end) }}
          </div>
        </div>
      </div>

      <!-- Cost Summary Cards -->
      <div
        v-if="stats.cost_summary"
        class="grid grid-cols-1 sm:grid-cols-3 gap-4"
        data-testid="section-cost-summary"
      >
        <div class="surface-card p-4 text-center">
          <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.costSummary.today') }}</p>
          <p class="text-xl font-bold txt-primary">{{ stats.cost_summary.today.toFixed(4) }} EUR</p>
        </div>
        <div class="surface-card p-4 text-center">
          <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.costSummary.thisWeek') }}</p>
          <p class="text-xl font-bold txt-primary">
            {{ stats.cost_summary.this_week.toFixed(4) }} EUR
          </p>
        </div>
        <div class="surface-card p-4 text-center">
          <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.costSummary.thisMonth') }}</p>
          <p class="text-xl font-bold txt-primary">
            {{ stats.cost_summary.this_month.toFixed(4) }} EUR
          </p>
        </div>
      </div>

      <!-- Subscription Info -->
      <div class="surface-card p-6" data-testid="section-subscription">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold txt-primary">
            {{ $t('config.usage.subscription') }}
          </h3>
          <span
            class="px-3 py-1 rounded-full text-xs font-medium"
            :class="getSubscriptionBadgeClass(stats.subscription.level)"
          >
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
            <p
              class="text-sm font-medium"
              :class="stats.phone_verified ? 'text-green-600' : 'text-red-600'"
            >
              {{ stats.phone_verified ? $t('common.yes') : $t('common.no') }}
            </p>
          </div>
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.subscriptionActive') }}</p>
            <p
              class="text-sm font-medium"
              :class="stats.subscription.active ? 'text-green-600' : 'text-gray-600'"
            >
              {{ stats.subscription.active ? $t('common.active') : $t('common.inactive') }}
            </p>
          </div>
          <div>
            <p class="text-xs txt-secondary mb-1">{{ $t('config.usage.totalRequests') }}</p>
            <p class="text-sm font-medium txt-primary">
              {{ stats.total_requests.toLocaleString() }}
            </p>
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
            <div
              v-for="(data, source) in stats.breakdown.by_source"
              :key="source"
              class="flex items-center justify-between p-3 surface-chip rounded-lg"
              data-testid="item-source"
            >
              <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg v2-source-icon" :class="getSourceIconBg(source)">
                  <Icon
                    :icon="getSourceIcon(source)"
                    class="w-5 h-5"
                    :class="getSourceIconColor(source)"
                  />
                </div>
                <div>
                  <p class="text-sm font-medium txt-primary">{{ getSourceLabel(source) }}</p>
                  <p class="text-xs txt-secondary">
                    {{ Object.keys(data.actions).length }} {{ $t('config.usage.actionTypes') }}
                  </p>
                </div>
              </div>
              <span class="text-lg font-semibold txt-primary">{{ data.total }}</span>
            </div>

            <div
              v-if="Object.keys(stats.breakdown.by_source).length === 0"
              class="text-center py-8 txt-secondary text-sm"
            >
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
            <div
              v-for="(data, period) in stats.breakdown.by_time"
              :key="period"
              class="flex items-center justify-between p-3 surface-chip rounded-lg"
              data-testid="item-period"
            >
              <div>
                <p class="text-sm font-medium txt-primary">{{ getTimePeriodLabel(period) }}</p>
                <p class="text-xs txt-secondary">
                  {{ Object.keys(data.actions).length }} {{ $t('config.usage.actionTypes') }}
                </p>
              </div>
              <span class="text-lg font-semibold txt-primary">{{ data.total }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="surface-card p-6" data-testid="section-recent">
        <h3 class="text-lg font-semibold txt-primary mb-4">
          {{ $t('config.usage.recentActivity') }}
        </h3>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-3 mb-4">
          <div class="flex-1">
            <input
              v-model="activitySearch"
              type="text"
              :placeholder="$t('config.usage.activity.searchPlaceholder')"
              class="w-full px-3 py-2 rounded-lg border border-light-border bg-transparent txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-brand/50"
              data-testid="input-activity-search"
              @input="onSearchInput"
            />
          </div>

          <select
            v-model="activityAction"
            class="px-3 py-2 rounded-lg border border-light-border bg-transparent txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-brand/50"
            data-testid="select-activity-action"
            @change="loadActivity(1)"
          >
            <option value="">{{ $t('config.usage.activity.allActions') }}</option>
            <option value="MESSAGES">{{ $t('config.usage.actions.messages') }}</option>
            <option value="IMAGES">{{ $t('config.usage.actions.images') }}</option>
            <option value="VIDEOS">{{ $t('config.usage.actions.videos') }}</option>
            <option value="AUDIOS">{{ $t('config.usage.actions.audios') }}</option>
            <option value="FILE_ANALYSIS">{{ $t('config.usage.actions.file_analysis') }}</option>
            <option value="SORTING">{{ $t('config.usage.actions.sorting') }}</option>
            <option value="SEARCH_QUERY">{{ $t('config.usage.actions.search_query') }}</option>
          </select>

          <input
            v-model="activityFrom"
            type="date"
            class="px-3 py-2 rounded-lg border border-light-border bg-transparent txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-brand/50"
            :title="$t('config.usage.activity.dateFrom')"
            data-testid="input-activity-from"
            @change="loadActivity(1)"
          />

          <input
            v-model="activityTo"
            type="date"
            class="px-3 py-2 rounded-lg border border-light-border bg-transparent txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-brand/50"
            :title="$t('config.usage.activity.dateTo')"
            data-testid="input-activity-to"
            @change="loadActivity(1)"
          />

          <button
            v-if="hasActiveFilters"
            class="px-3 py-2 rounded-lg text-sm txt-secondary hover:txt-primary transition-colors"
            :title="$t('config.usage.activity.clearFilters')"
            data-testid="btn-clear-filters"
            @click="clearFilters"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>
        </div>

        <!-- Loading -->
        <div v-if="activityLoading" class="flex items-center justify-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-brand"></div>
        </div>

        <!-- Table -->
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs txt-secondary uppercase border-b border-light-border">
              <tr>
                <th class="px-3 py-3 text-left">{{ $t('config.usage.time') }}</th>
                <th class="px-3 py-3 text-left">{{ $t('config.usage.action') }}</th>
                <th class="px-3 py-3 text-left">{{ $t('config.usage.model') }}</th>
                <th class="px-3 py-3 text-right">{{ $t('config.usage.promptTokens') }}</th>
                <th class="px-3 py-3 text-right">{{ $t('config.usage.completionTokens') }}</th>
                <th class="px-3 py-3 text-right">{{ $t('config.usage.cachedTokens') }}</th>
                <th class="px-3 py-3 text-right">{{ $t('config.usage.cost') }}</th>
                <th class="px-3 py-3 text-right">{{ $t('config.usage.latency') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-light-border">
              <tr
                v-for="entry in activityItems"
                :key="entry.timestamp + entry.action + entry.model"
                class="hover:bg-black/5 dark:hover:bg-white/5"
                data-testid="item-activity"
              >
                <td class="px-3 py-3 txt-secondary whitespace-nowrap">
                  {{ formatDateTime(entry.timestamp) }}
                </td>
                <td class="px-3 py-3">
                  <span class="px-2 py-1 rounded-full text-xs font-medium surface-chip">
                    {{ getActionLabel(entry.action) }}
                  </span>
                </td>
                <td
                  class="px-3 py-3 txt-primary text-xs truncate max-w-[120px]"
                  :title="entry.model"
                >
                  {{ entry.model || '-' }}
                </td>
                <td class="px-3 py-3 text-right txt-secondary">
                  <span class="flex items-center justify-end gap-1">
                    {{ entry.prompt_tokens.toLocaleString() }}
                    <span
                      v-if="entry.estimated"
                      class="inline-block px-1 py-0.5 text-[10px] rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400"
                      :title="$t('config.usage.estimatedTooltip')"
                      >~</span
                    >
                  </span>
                </td>
                <td class="px-3 py-3 text-right txt-secondary">
                  {{ entry.completion_tokens.toLocaleString() }}
                </td>
                <td class="px-3 py-3 text-right">
                  <span v-if="entry.cached_tokens > 0" class="text-green-600 dark:text-green-400">
                    {{ entry.cached_tokens.toLocaleString() }}
                  </span>
                  <span v-else class="txt-secondary">-</span>
                </td>
                <td class="px-3 py-3 text-right txt-secondary whitespace-nowrap">
                  {{ entry.cost > 0 ? entry.cost.toFixed(4) + ' EUR' : '-' }}
                </td>
                <td class="px-3 py-3 text-right txt-secondary">
                  {{ entry.latency > 0 ? (entry.latency / 1000).toFixed(1) + 's' : '-' }}
                </td>
              </tr>

              <tr v-if="activityItems.length === 0" data-testid="row-empty">
                <td colspan="8" class="px-4 py-8 text-center txt-secondary text-sm">
                  {{ $t('config.usage.noRecentActivity') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div
          v-if="activityTotalPages > 1"
          class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4 pt-4 border-t border-light-border"
        >
          <span class="text-xs txt-secondary">
            {{
              $t('config.usage.activity.showing', {
                from: (activityPage - 1) * activityPerPage + 1,
                to: Math.min(activityPage * activityPerPage, activityTotal),
                total: activityTotal,
              })
            }}
          </span>

          <div class="flex items-center gap-1">
            <button
              :disabled="activityPage <= 1"
              class="px-3 py-1.5 rounded text-xs font-medium transition-colors disabled:opacity-40"
              :class="
                activityPage <= 1
                  ? 'txt-secondary'
                  : 'txt-primary hover:bg-black/5 dark:hover:bg-white/5'
              "
              @click="loadActivity(1)"
            >
              &laquo;
            </button>
            <button
              :disabled="activityPage <= 1"
              class="px-3 py-1.5 rounded text-xs font-medium transition-colors disabled:opacity-40"
              :class="
                activityPage <= 1
                  ? 'txt-secondary'
                  : 'txt-primary hover:bg-black/5 dark:hover:bg-white/5'
              "
              @click="loadActivity(activityPage - 1)"
            >
              &lsaquo;
            </button>

            <span class="px-3 py-1.5 text-xs txt-primary font-medium">
              {{ activityPage }} / {{ activityTotalPages }}
            </span>

            <button
              :disabled="activityPage >= activityTotalPages"
              class="px-3 py-1.5 rounded text-xs font-medium transition-colors disabled:opacity-40"
              :class="
                activityPage >= activityTotalPages
                  ? 'txt-secondary'
                  : 'txt-primary hover:bg-black/5 dark:hover:bg-white/5'
              "
              @click="loadActivity(activityPage + 1)"
            >
              &rsaquo;
            </button>
            <button
              :disabled="activityPage >= activityTotalPages"
              class="px-3 py-1.5 rounded text-xs font-medium transition-colors disabled:opacity-40"
              :class="
                activityPage >= activityTotalPages
                  ? 'txt-secondary'
                  : 'txt-primary hover:bg-black/5 dark:hover:bg-white/5'
              "
              @click="loadActivity(activityTotalPages)"
            >
              &raquo;
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import {
  getUsageStats,
  downloadUsageExport,
  getActivityLog,
  type UsageStats,
  type ActivityEntry,
} from '@/api/usageApi'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'
import { authService } from '@/services/authService'

const { success, error: showError } = useNotification()
const { t } = useI18n()

const loading = ref(false)
const exporting = ref(false)
const error = ref<string | null>(null)
const stats = ref<UsageStats | null>(null)

const activityItems = ref<ActivityEntry[]>([])
const activityLoading = ref(false)
const activityPage = ref(1)
const activityPerPage = 20
const activityTotal = ref(0)
const activityTotalPages = ref(1)
const activitySearch = ref('')
const activityAction = ref('')
const activityFrom = ref('')
const activityTo = ref('')
let searchDebounce: ReturnType<typeof setTimeout> | null = null

const hasActiveFilters = computed(
  () =>
    activitySearch.value !== '' ||
    activityAction.value !== '' ||
    activityFrom.value !== '' ||
    activityTo.value !== ''
)

const loadActivity = async (page = 1) => {
  try {
    activityLoading.value = true
    activityPage.value = page

    const fromTs = activityFrom.value
      ? Math.floor(new Date(activityFrom.value).getTime() / 1000)
      : undefined
    const toTs = activityTo.value
      ? Math.floor(new Date(activityTo.value + 'T23:59:59').getTime() / 1000)
      : undefined

    const data = await getActivityLog({
      page,
      per_page: activityPerPage,
      search: activitySearch.value || undefined,
      action: activityAction.value || undefined,
      from: fromTs,
      to: toTs,
    })

    activityItems.value = data.items
    activityTotal.value = data.total
    activityTotalPages.value = data.total_pages
  } catch (err: any) {
    console.error('Failed to load activity:', err)
  } finally {
    activityLoading.value = false
  }
}

const onSearchInput = () => {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => loadActivity(1), 350)
}

const clearFilters = () => {
  activitySearch.value = ''
  activityAction.value = ''
  activityFrom.value = ''
  activityTo.value = ''
  loadActivity(1)
}

const loadStats = async () => {
  try {
    loading.value = true
    error.value = null

    if (!authService.isAuthenticated()) {
      error.value = t('config.usage.notAuthenticated')
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
      return 'mdi:whatsapp'
    case 'EMAIL':
      return 'heroicons:envelope'
    case 'WEB':
      return 'heroicons:globe-alt'
    default:
      return 'heroicons:device-phone-mobile'
  }
}

const getSourceIconBg = (source: string) => {
  switch (source.toUpperCase()) {
    case 'WHATSAPP':
      return 'bg-green-500/10'
    case 'EMAIL':
      return 'bg-blue-500/10'
    case 'WEB':
      return 'bg-brand/10'
    default:
      return 'bg-gray-500/10'
  }
}

const getSourceIconColor = (source: string) => {
  switch (source.toUpperCase()) {
    case 'WHATSAPP':
      return 'text-green-500'
    case 'EMAIL':
      return 'text-blue-500'
    case 'WEB':
      return 'txt-brand'
    default:
      return 'txt-secondary'
  }
}

const getTimePeriodLabel = (period: string) => {
  return t(`config.usage.periods.${period.toLowerCase()}`)
}

const getBudgetBarClass = (percent: number) => {
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 75) return 'bg-orange-500'
  if (percent >= 50) return 'bg-yellow-500'
  return 'bg-green-500'
}

const formatDate = (timestamp: number) => {
  return new Date(timestamp * 1000).toLocaleDateString()
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
  loadActivity(1)
})

onUnmounted(() => {
  if (searchDebounce) clearTimeout(searchDebounce)
})
</script>
