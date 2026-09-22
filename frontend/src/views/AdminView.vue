<template>
  <MainLayout data-testid="view-admin">
    <div class="container mx-auto px-6 py-8 max-w-7xl overflow-x-hidden">
      <!-- Header -->
      <PageHeader
        :title="$t('admin.title')"
        :subtitle="$t('admin.description')"
        icon="mdi:shield-crown"
      >
        <TabNav
          :model-value="activeTab"
          :tabs="tabNavItems"
          :aria-label="$t('admin.title')"
          mobile-trigger-testid="tab-admin-mobile-trigger"
          mobile-menu-testid="tab-admin-mobile-menu"
          @update:model-value="onTabNavChange"
        />
      </PageHeader>

      <!-- Tab Content -->
      <div class="space-y-6">
        <!-- Overview Tab -->
        <div v-if="activeTab === 'overview'" data-testid="section-overview">
          <AdminSystemInfoPanel class="mb-6" />
          <div v-if="overviewLoading" class="text-center py-12">
            <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
          </div>
          <div v-else-if="overview" class="space-y-6">
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.overview.totalUsers') }}</span>
                  <Icon icon="mdi:account-multiple" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ overview.totalUsers }}</div>
              </div>

              <div
                v-for="(count, level) in overview.usersByLevel"
                :key="level"
                class="surface-card rounded-lg p-6"
              >
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ level }}</span>
                  <Icon :icon="getLevelIcon(level)" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ count }}</div>
              </div>
            </div>

            <!-- Registration Analytics Chart -->
            <RegistrationChart
              v-if="registrationAnalytics"
              :data="registrationAnalytics"
              :initial-period="analyticsPeriod"
              :initial-group-by="analyticsGroupBy"
              @update:period="updateAnalyticsPeriod"
              @update:group-by="updateAnalyticsGroupBy"
            />

            <!-- Active Subscriptions Overview -->
            <div v-if="config.billing.enabled" class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:credit-card-outline" class="w-5 h-5" />
                {{ $t('admin.usage.activeSubscriptions') }}
              </h3>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <template v-for="(count, level) in overview.usersByLevel" :key="level">
                  <div
                    v-if="level !== 'NEW' && level !== 'ADMIN'"
                    class="surface-elevated rounded-lg p-4"
                  >
                    <div class="flex items-center justify-between mb-2">
                      <span class="font-semibold txt-primary">{{ level }}</span>
                      <Icon :icon="getLevelIcon(level)" class="w-5 h-5 txt-secondary" />
                    </div>
                    <div class="text-2xl font-bold txt-primary">{{ count }}</div>
                    <div class="text-xs txt-secondary mt-1">
                      {{ $t('admin.usage.activeSubscriptions') }}
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Recent Users -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:account-clock" class="w-5 h-5" />
                {{ $t('admin.overview.recentUsers') }}
              </h3>
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                        {{ $t('admin.users.email') }}
                      </th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                        {{ $t('admin.users.level') }}
                      </th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                        {{ $t('admin.users.created') }}
                      </th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                        {{ $t('admin.users.status') }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="user in recentUsers"
                      :key="user.id"
                      class="border-b border-light-border/30 dark:border-dark-border/20 hover:bg-black/5 dark:hover:bg-white/5"
                    >
                      <td class="py-3 px-4 txt-primary">{{ user.email }}</td>
                      <td class="py-3 px-4">
                        <span :class="getLevelBadgeClass(user.level)">{{ user.level }}</span>
                      </td>
                      <td class="py-3 px-4 txt-secondary text-sm">
                        {{ formatDate(user.created) }}
                      </td>
                      <td class="py-3 px-4">
                        <Icon
                          v-if="user.emailVerified"
                          icon="mdi:check-circle"
                          class="w-5 h-5 text-green-500"
                          :title="$t('admin.users.verified')"
                        />
                        <Icon
                          v-else
                          icon="mdi:alert-circle"
                          class="w-5 h-5 text-yellow-500"
                          :title="$t('admin.users.unverified')"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Prompts Tab. Stays mounted after the first visit so an unsaved draft survives a tab switch. -->
        <div
          v-if="promptsTabMounted"
          v-show="activeTab === 'prompts'"
          data-testid="section-prompts"
        >
          <AdminPromptsPanel />
        </div>

        <!-- Usage Tab -->
        <div v-if="activeTab === 'usage'" data-testid="section-usage">
          <!-- Period Selector -->
          <div class="surface-card rounded-lg p-4 mb-6">
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="period in ['day', 'week', 'month', 'all']"
                :key="period"
                :class="[
                  'px-4 py-2 rounded-lg font-medium',
                  usageStatsPeriod === period ? 'btn-primary' : 'btn-secondary',
                ]"
                :data-testid="`btn-period-${period}`"
                @click="loadUsageStats(period as any)"
              >
                {{ $t(`admin.usage.period.${period}`) }}
              </button>
            </div>
          </div>

          <div v-if="usageStatsLoading" class="text-center py-12">
            <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
          </div>
          <div v-else-if="usageStats" class="space-y-6">
            <!-- Usage Chart -->
            <UsageChart :data="usageStats.byAction" />
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalRequests') }}</span>
                  <Icon icon="mdi:message" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">
                  {{ (usageStats.total_requests || 0).toLocaleString() }}
                </div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalTokens') }}</span>
                  <Icon icon="mdi:alphabetical-variant" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">
                  {{ (usageStats.total_tokens || 0).toLocaleString() }}
                </div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalCost') }}</span>
                  <Icon icon="mdi:currency-usd" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">
                  ${{ (usageStats.total_cost || 0).toFixed(2) }}
                </div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.avgLatency') }}</span>
                  <Icon icon="mdi:speedometer" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">
                  {{ (usageStats.avg_latency || 0).toFixed(0) }}ms
                </div>
              </div>
            </div>

            <div class="flex justify-end">
              <button
                type="button"
                class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-usage-accordion-toggle-all"
                @click="
                  allUsageSectionsOpen ? collapseAllUsageSections() : expandAllUsageSections()
                "
              >
                {{
                  allUsageSectionsOpen
                    ? $t('admin.config.accordion.collapseAll')
                    : $t('admin.config.accordion.expandAll')
                }}
              </button>
            </div>

            <AccordionStack testid="usage-accordion">
              <AccordionSection
                panel-id="usage-section-by-action"
                :title="$t('admin.usage.byAction')"
                :open="isUsageSectionOpen('byAction')"
                header-testid="btn-usage-section-by-action"
                @toggle="toggleUsageSection('byAction')"
              >
                <template #leading>
                  <Icon icon="mdi:gesture-tap" class="w-5 h-5 txt-secondary flex-shrink-0" />
                </template>
                <div class="space-y-2">
                  <div
                    v-for="(stats, action) in usageStats.byAction"
                    :key="action"
                    class="flex items-center justify-between py-2 px-4 rounded-lg bg-chat"
                  >
                    <span class="txt-primary font-medium">{{ action }}</span>
                    <div class="flex gap-6 text-sm txt-secondary">
                      <span
                        >{{ stats.count.toLocaleString() }} {{ $t('admin.usage.requests') }}</span
                      >
                      <span
                        >{{ stats.tokens.toLocaleString() }} {{ $t('admin.usage.tokens') }}</span
                      >
                      <span>${{ stats.cost.toFixed(4) }}</span>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <AccordionSection
                panel-id="usage-section-by-provider"
                :title="$t('admin.usage.byProvider')"
                :open="isUsageSectionOpen('byProvider')"
                header-testid="btn-usage-section-by-provider"
                @toggle="toggleUsageSection('byProvider')"
              >
                <template #leading>
                  <Icon icon="mdi:server-network" class="w-5 h-5 txt-secondary flex-shrink-0" />
                </template>
                <div class="space-y-2">
                  <div
                    v-for="(stats, provider) in usageStats.byProvider"
                    :key="provider"
                    class="flex items-center justify-between py-2 px-4 rounded-lg bg-chat"
                  >
                    <span class="txt-primary font-medium">{{ provider }}</span>
                    <div class="flex gap-6 text-sm txt-secondary">
                      <span
                        >{{ stats.count.toLocaleString() }} {{ $t('admin.usage.requests') }}</span
                      >
                      <span
                        >{{ stats.tokens.toLocaleString() }} {{ $t('admin.usage.tokens') }}</span
                      >
                      <span>${{ stats.cost.toFixed(4) }}</span>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <AccordionSection
                v-if="usageHasModels"
                panel-id="usage-section-by-model"
                :title="$t('admin.usage.topModels')"
                :open="isUsageSectionOpen('byModel')"
                header-testid="btn-usage-section-by-model"
                @toggle="toggleUsageSection('byModel')"
              >
                <template #leading>
                  <Icon icon="mdi:robot" class="w-5 h-5 txt-secondary flex-shrink-0" />
                </template>
                <div class="space-y-2">
                  <div
                    v-for="(stats, model) in usageStats.byModel"
                    :key="model"
                    class="flex items-center justify-between py-2 px-4 rounded-lg bg-chat"
                  >
                    <span
                      class="txt-primary font-medium text-sm truncate max-w-[200px]"
                      :title="String(model)"
                      >{{ model }}</span
                    >
                    <div class="flex gap-6 text-sm txt-secondary">
                      <span
                        >{{ stats.count.toLocaleString() }} {{ $t('admin.usage.requests') }}</span
                      >
                      <span
                        >{{ stats.tokens.toLocaleString() }} {{ $t('admin.usage.tokens') }}</span
                      >
                      <span>${{ stats.cost.toFixed(4) }}</span>
                    </div>
                  </div>
                </div>
              </AccordionSection>

              <AccordionSection
                panel-id="usage-section-top-users"
                :title="$t('admin.usage.topUsers')"
                :open="isUsageSectionOpen('topUsers')"
                header-testid="btn-usage-section-top-users"
                @toggle="toggleUsageSection('topUsers')"
              >
                <template #leading>
                  <Icon icon="mdi:trophy" class="w-5 h-5 txt-secondary flex-shrink-0" />
                </template>
                <p class="text-sm txt-secondary mb-4">
                  {{ $t('admin.usage.topUsersHint') }}
                </p>
                <div class="overflow-x-auto">
                  <table class="w-full">
                    <thead>
                      <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                        <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">#</th>
                        <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                          {{ $t('admin.users.email') }}
                        </th>
                        <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                          {{ $t('admin.users.level') }}
                        </th>
                        <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">
                          {{ $t('admin.usage.requests') }}
                        </th>
                        <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">
                          {{ $t('admin.usage.tokens') }}
                        </th>
                        <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">
                          {{ $t('admin.usage.cost') }}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <template v-if="usageStatsTopUsers.length === 0">
                        <tr>
                          <td colspan="6" class="py-10 px-4 text-center text-sm txt-secondary">
                            {{ $t('admin.usage.topUsersEmpty') }}
                          </td>
                        </tr>
                      </template>
                      <template v-else>
                        <tr
                          v-for="(user, index) in usageStatsTopUsers"
                          :key="user.id"
                          class="border-b border-light-border/30 dark:border-dark-border/20"
                        >
                          <td class="py-3 px-4 txt-secondary text-sm">{{ index + 1 }}</td>
                          <td class="py-3 px-4 txt-primary">{{ user.email || '—' }}</td>
                          <td class="py-3 px-4">
                            <span :class="getLevelBadgeClass(user.level)">{{ user.level }}</span>
                          </td>
                          <td class="py-3 px-4 text-right txt-secondary">
                            {{ user.requests.toLocaleString() }}
                          </td>
                          <td class="py-3 px-4 text-right txt-secondary">
                            {{ user.tokens.toLocaleString() }}
                          </td>
                          <td class="py-3 px-4 text-right txt-secondary">
                            ${{ user.cost.toFixed(2) }}
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </div>
              </AccordionSection>
            </AccordionStack>
          </div>
        </div>

        <!-- Subscriptions Tab -->
        <div v-if="activeTab === 'subscriptions'" data-testid="section-subscriptions">
          <AdminSubscriptionsPanel />
        </div>

        <!-- Moderation Tab -->
        <div v-if="activeTab === 'moderation'" data-testid="section-moderation">
          <AdminModerationPanel />
        </div>

        <!-- App server Tab (native shell only) -->
        <div v-if="activeTab === 'appServer'">
          <NativeServerControl />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { defineAsyncComponent, ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import RegistrationChart from '@/components/admin/RegistrationChart.vue'
import UsageChart from '@/components/admin/UsageChart.vue'
import { useAccordion } from '@/composables/useAccordion'
import {
  adminApi,
  type UsageStats,
  type SystemOverview,
  type RegistrationAnalytics,
} from '@/services/api/adminApi'
const AdminPromptsPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminPromptsPanel.vue')
)
const AdminSubscriptionsPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminSubscriptionsPanel.vue')
)
const AdminModerationPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminModerationPanel.vue')
)
const AdminSystemInfoPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminSystemInfoPanel.vue')
)
const NativeServerControl = defineAsyncComponent(
  () => import('@/components/NativeServerControl.vue')
)
import { useConfigStore } from '@/stores/config'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import { isNativeApp } from '@/services/api/nativeRuntime'

const { t } = useI18n()
const { formatDateTime } = useDateFormat()
const config = useConfigStore()
const route = useRoute()
const router = useRouter()
type TabId = 'overview' | 'prompts' | 'usage' | 'subscriptions' | 'moderation' | 'appServer'
interface AdminTab {
  id: TabId
  label: string
  icon: string
}

const isValidTab = (tab: unknown): tab is TabId =>
  tab === 'overview' ||
  tab === 'prompts' ||
  tab === 'usage' ||
  tab === 'subscriptions' ||
  tab === 'moderation' ||
  tab === 'appServer'

const tabFromQuery = (): TabId => {
  const tab = route.query.tab
  if (isValidTab(tab)) {
    if (tab === 'appServer' && !isNativeApp()) {
      return 'overview'
    }
    return tab
  }
  return 'overview'
}

// Tabs
const activeTab = ref<TabId>(tabFromQuery())
// Once the prompts tab has been opened, keep the panel mounted so a tab
// switch does not throw away an unsaved draft.
const promptsTabMounted = ref(activeTab.value === 'prompts')
const tabs = computed<AdminTab[]>(() => {
  const baseTabs: AdminTab[] = [
    { id: 'overview', label: t('admin.tabs.overview'), icon: 'mdi:view-dashboard' },
    { id: 'prompts', label: t('admin.tabs.prompts'), icon: 'mdi:text-box-multiple' },
    { id: 'usage', label: t('admin.tabs.usage'), icon: 'mdi:chart-bar' },
    { id: 'subscriptions', label: t('admin.tabs.subscriptions'), icon: 'mdi:credit-card-outline' },
    { id: 'moderation', label: t('admin.tabs.moderation'), icon: 'mdi:flag-outline' },
  ]
  // Native shell only: the backend the app connects to is a client-side bootstrap
  // value with no meaning on the web build, so the tab is hidden there.
  if (isNativeApp()) {
    baseTabs.push({ id: 'appServer', label: t('admin.tabs.appServer'), icon: 'mdi:server-network' })
  }
  return baseTabs
})

const tabNavItems = computed<TabNavItem[]>(() =>
  tabs.value.map((tab) => ({
    id: tab.id,
    label: tab.label,
    icon: tab.icon,
    testid: `tab-${tab.id}`,
  }))
)

function onTabNavChange(id: string) {
  activeTab.value = id as TabId
}

watch(activeTab, (id) => {
  const tab = route.query.tab
  if (tab === id || (id === 'overview' && (tab === undefined || tab === ''))) {
    return
  }
  const query = { ...route.query }
  if (id === 'overview') {
    delete query.tab
  } else {
    query.tab = id
  }
  void router.replace({ query })
})

// Overview
const overview = ref<SystemOverview | null>(null)
const overviewLoading = ref(false)
const recentUsers = computed(() => overview.value?.recentUsers ?? [])

// Registration Analytics
const registrationAnalytics = ref<RegistrationAnalytics | null>(null)
const analyticsPeriod = ref<'7d' | '30d' | '90d' | '1y' | 'all'>('30d')
const analyticsGroupBy = ref<'day' | 'week' | 'month'>('day')

// Usage Stats
const usageStats = ref<UsageStats | null>(null)
const usageStatsLoading = ref(false)
const usageStatsPeriod = ref<'day' | 'week' | 'month' | 'all'>('week')
const usageStatsTopUsers = computed(() => usageStats.value?.topUsers ?? [])
const usageHasModels = computed(
  () => !!usageStats.value?.byModel && Object.keys(usageStats.value.byModel).length > 0
)
const usageSectionIds = computed(() => {
  const ids = ['byAction', 'byProvider']
  if (usageHasModels.value) ids.push('byModel')
  ids.push('topUsers')
  return ids
})
const {
  isOpen: isUsageSectionOpen,
  toggle: toggleUsageSection,
  expandAll: expandAllUsageSections,
  collapseAll: collapseAllUsageSections,
  allOpen: allUsageSectionsOpen,
} = useAccordion(usageSectionIds)

// Load data based on active tab
watch(activeTab, (newTab: string) => {
  if (newTab === 'prompts') {
    promptsTabMounted.value = true
  }
  if (newTab === 'overview') {
    if (!overview.value) loadOverview()
    if (!registrationAnalytics.value) loadRegistrationAnalytics()
  } else if (newTab === 'usage' && !usageStats.value) {
    loadUsageStats()
  }
})

// Load functions
async function loadOverview() {
  overviewLoading.value = true
  try {
    overview.value = await adminApi.getOverview()
  } catch (error) {
    console.error('Failed to load overview:', error)
  } finally {
    overviewLoading.value = false
  }
}

async function loadRegistrationAnalytics() {
  try {
    registrationAnalytics.value = await adminApi.getRegistrationAnalytics(
      analyticsPeriod.value,
      analyticsGroupBy.value
    )
  } catch (error) {
    console.error('Failed to load registration analytics:', error)
  }
}

async function updateAnalyticsPeriod(newPeriod: string) {
  if (
    newPeriod === '7d' ||
    newPeriod === '30d' ||
    newPeriod === '90d' ||
    newPeriod === '1y' ||
    newPeriod === 'all'
  ) {
    analyticsPeriod.value = newPeriod
  }
  await loadRegistrationAnalytics()
}

async function updateAnalyticsGroupBy(newGroupBy: string) {
  if (newGroupBy === 'day' || newGroupBy === 'week' || newGroupBy === 'month') {
    analyticsGroupBy.value = newGroupBy
  }
  await loadRegistrationAnalytics()
}

async function loadUsageStats(period: 'day' | 'week' | 'month' | 'all' = 'week') {
  usageStatsLoading.value = true
  usageStatsPeriod.value = period
  try {
    usageStats.value = await adminApi.getUsageStats(period)
  } catch (error) {
    console.error('Failed to load usage stats:', error)
  } finally {
    usageStatsLoading.value = false
  }
}

// Helpers
function getLevelIcon(level: string): string {
  const icons: Record<string, string> = {
    NEW: 'mdi:star-outline',
    PRO: 'mdi:star',
    TEAM: 'mdi:account-group',
    BUSINESS: 'mdi:office-building',
    ADMIN: 'mdi:shield-crown',
  }
  return icons[level] || 'mdi:account'
}

function getLevelBadgeClass(level: string): string {
  const classes: Record<string, string> = {
    NEW: 'badge-level badge-new',
    PRO: 'badge-level badge-pro',
    TEAM: 'badge-level badge-team',
    BUSINESS: 'badge-level badge-business',
    ADMIN: 'badge-level badge-admin',
  }
  return classes[level] || classes['NEW']
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '—'
  try {
    const date = new Date(dateStr)
    if (isNaN(date.getTime())) return '—'
    return formatDateTime(date)
  } catch {
    return '—'
  }
}

// Initialize
watch(
  () => route.query.tab,
  () => {
    if (route.query.tab === 'users') {
      void router.replace({ name: 'admin-people' })
      return
    }
    activeTab.value = tabFromQuery()
  }
)

onMounted(() => {
  loadOverview()
  loadRegistrationAnalytics()
})
</script>
