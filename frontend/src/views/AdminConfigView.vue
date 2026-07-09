<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ConfigField from '@/components/admin/ConfigField.vue'
import { useAuthStore } from '@/stores/auth'
import { useNotification } from '@/composables/useNotification'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import {
  getConfigSchema,
  getConfigValues,
  updateConfigValue,
  testConnection,
  type ConfigSchema,
  type ConfigValue,
} from '@/services/api/adminConfigApi'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const { success, error: showError } = useNotification()

// State
const loading = ref(true)
const schema = ref<ConfigSchema | null>(null)
const values = ref<Record<string, ConfigValue>>({})
const activeTab = ref('ai')
const showRestartBanner = ref(false)
const testingService = ref<string | null>(null)

// Tab icons
const tabIcons: Record<string, string> = {
  ai: 'mdi:robot',
  email: 'mdi:email-outline',
  auth: 'mdi:shield-key',
  channels: 'mdi:message-text',
  processing: 'mdi:file-document-outline',
  vectordb: 'mdi:database-search',
}

// Computed
const tabs = computed(() => {
  if (!schema.value) return []
  return Object.entries(schema.value.tabs).map(([id, tab]) => ({
    id,
    label: tab.label,
    icon: tabIcons[id] || 'mdi:cog',
  }))
})

// Tabs are grouped into max 3 condensed dropdowns (same pattern as the
// widget's AdvancedWidgetConfig) so the header never overflows horizontally.
// Group membership is purely a UI concern; `activeTab` still drives content.
const groupDefs = [
  {
    id: 'ai-data',
    icon: 'mdi:robot',
    labelKey: 'admin.config.tabGroups.aiData',
    tabIds: ['ai', 'vectordb', 'processing'],
  },
  {
    id: 'communication',
    icon: 'mdi:message-text',
    labelKey: 'admin.config.tabGroups.communication',
    tabIds: ['email', 'channels'],
  },
  {
    id: 'security',
    icon: 'mdi:shield-key',
    labelKey: 'admin.config.tabGroups.security',
    tabIds: ['auth'],
  },
]

const tabGroups = computed(() => {
  const all = tabs.value
  const assigned = new Set(groupDefs.flatMap((g) => g.tabIds))
  const groups = groupDefs.map((def) => ({
    ...def,
    tabs: def.tabIds.flatMap((id) => all.filter((tab) => tab.id === id)),
  }))
  // New backend tabs not covered by the mapping stay reachable via group 1.
  groups[0].tabs.push(...all.filter((tab) => !assigned.has(tab.id)))
  return groups.filter((g) => g.tabs.length > 0)
})

// Which tab-group dropdown is currently open (null = all closed).
const openGroup = ref<string | null>(null)
const tabBarRef = ref<HTMLElement | null>(null)

function toggleGroup(groupId: string) {
  openGroup.value = openGroup.value === groupId ? null : groupId
}

function selectTab(tabId: string) {
  activeTab.value = tabId
  openGroup.value = null
}

// Mobile tab dropdown: a single dropdown replaces the 3-group bar (same
// pattern as FilesTabs.vue), grouping the tabs under their section headers
// so every entry the desktop dropdowns expose stays reachable.
const mobileTabMenuOpen = ref(false)
const mobileTabDropdownRef = ref<HTMLElement | null>(null)

function toggleMobileTabMenu() {
  triggerHapticImpact('light')
  mobileTabMenuOpen.value = !mobileTabMenuOpen.value
}

function closeMobileTabMenu() {
  if (!mobileTabMenuOpen.value) return
  triggerHapticImpact('light')
  mobileTabMenuOpen.value = false
}

function selectMobileTab(tabId: string) {
  closeMobileTabMenu()
  activeTab.value = tabId
}

function handleTabBarOutsideClick(event: MouseEvent) {
  if (openGroup.value && tabBarRef.value && !tabBarRef.value.contains(event.target as Node)) {
    openGroup.value = null
  }
  if (
    mobileTabMenuOpen.value &&
    mobileTabDropdownRef.value &&
    !mobileTabDropdownRef.value.contains(event.target as Node)
  ) {
    mobileTabMenuOpen.value = false
  }
}

function handleTabBarEscape(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  openGroup.value = null
  mobileTabMenuOpen.value = false
}

const currentTab = computed(() => {
  if (!schema.value) return null
  return schema.value.tabs[activeTab.value]
})

const currentSections = computed(() => {
  if (!currentTab.value || !schema.value) return []
  return Object.entries(currentTab.value.sections).map(([id, section]) => {
    const fields = section.fields.map((fieldKey) => ({
      key: fieldKey,
      schema: schema.value!.fields[fieldKey],
      value: values.value[fieldKey] || { value: '', isSet: false, isMasked: false },
    }))
    const isLive = fields.some((f) => f.schema?.source === 'database')
    return { id, label: section.label, fields, isLive }
  })
})

// Service test mapping (multiple services per tab are tested sequentially)
const testableServices: Record<string, string[]> = {
  ai: ['ollama', 'piper'],
  processing: ['tika'],
  vectordb: ['qdrant'],
  email: ['mailer'],
}

const canTestCurrentTab = computed(() => {
  return testableServices[activeTab.value]?.length > 0
})

// Methods
async function loadConfig() {
  loading.value = true
  try {
    const [schemaData, valuesData] = await Promise.all([getConfigSchema(), getConfigValues()])
    schema.value = schemaData
    values.value = valuesData
  } catch (err) {
    console.error('Failed to load config:', err)
    showError(t('admin.config.loadError'))
  } finally {
    loading.value = false
  }
}

async function handleUpdate(key: string, value: string) {
  try {
    const result = await updateConfigValue(key, value)
    if (result.success) {
      const isLive = schema.value?.fields[key]?.source === 'database'
      success(t(isLive ? 'admin.config.savedLive' : 'admin.config.saved'))
      // Update local value
      values.value[key] = {
        value: schema.value?.fields[key]?.sensitive ? '' : value,
        isSet: true,
        isMasked: schema.value?.fields[key]?.sensitive || false,
      }
      // Show restart banner only for env-based fields
      if (result.requiresRestart) {
        showRestartBanner.value = true
      }
    }
  } catch (err) {
    console.error('Failed to update config:', err)
    showError(t('admin.config.saveError'))
  }
}

async function handleTestConnection() {
  const services = testableServices[activeTab.value]
  if (!services?.length) return

  testingService.value = services[0]

  try {
    const results = await Promise.allSettled(services.map((svc) => testConnection(svc)))

    const succeeded: string[] = []
    const failed: string[] = []

    results.forEach((result, i) => {
      const svc = services[i]
      if (result.status === 'fulfilled' && result.value.success) {
        succeeded.push(result.value.message)
      } else {
        const msg =
          result.status === 'fulfilled' ? result.value.message : t('admin.config.testFailed')
        failed.push(`${svc}: ${msg}`)
      }
    })

    if (failed.length === 0) {
      success(succeeded.join(' | '))
    } else if (succeeded.length === 0) {
      showError(failed.join(' | '))
    } else {
      success(succeeded.join(' | '))
      showError(failed.join(' | '))
    }
  } catch (err) {
    console.error('Connection test failed:', err)
    showError(t('admin.config.testFailed'))
  } finally {
    testingService.value = null
  }
}

function dismissRestartBanner() {
  showRestartBanner.value = false
}

function copyRestartCommand() {
  navigator.clipboard.writeText('docker compose restart backend')
  success(t('admin.config.commandCopied'))
}

// Check admin access
onMounted(async () => {
  // Close any open tab-group dropdown when clicking elsewhere.
  document.addEventListener('click', handleTabBarOutsideClick)
  document.addEventListener('keydown', handleTabBarEscape)

  if (!authStore.isAdmin) {
    router.push('/admin')
    return
  }
  await loadConfig()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleTabBarOutsideClick)
  document.removeEventListener('keydown', handleTabBarEscape)
})
</script>

<template>
  <MainLayout data-testid="view-admin-config">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="container mx-auto max-w-5xl">
        <!-- Restart Banner -->
        <Transition
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="opacity-0 -translate-y-4"
          enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 translate-y-0"
          leave-to-class="opacity-0 -translate-y-4"
        >
          <div
            v-if="showRestartBanner"
            class="mb-6 p-4 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800/30"
          >
            <div class="flex items-start gap-3">
              <Icon
                icon="mdi:alert"
                class="w-6 h-6 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"
              />
              <div class="flex-1">
                <h4 class="font-semibold text-yellow-800 dark:text-yellow-200">
                  {{ $t('admin.config.restartBanner.title') }}
                </h4>
                <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                  {{ $t('admin.config.restartBanner.message') }}
                </p>
                <div class="mt-3 flex items-center gap-2">
                  <code
                    class="flex-1 px-3 py-2 bg-yellow-100 dark:bg-yellow-900/40 rounded text-sm font-mono text-yellow-900 dark:text-yellow-100"
                  >
                    docker compose restart backend
                  </code>
                  <button
                    type="button"
                    class="p-2 rounded-lg hover:bg-yellow-100 dark:hover:bg-yellow-900/40"
                    :title="$t('admin.config.restartBanner.copyCommand')"
                    @click="copyRestartCommand"
                  >
                    <Icon
                      icon="mdi:content-copy"
                      class="w-5 h-5 text-yellow-700 dark:text-yellow-300"
                    />
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="p-1 rounded hover:bg-yellow-100 dark:hover:bg-yellow-900/40"
                @click="dismissRestartBanner"
              >
                <Icon icon="mdi:close" class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
              </button>
            </div>
          </div>
        </Transition>

        <!-- Header -->
        <div class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <Icon icon="mdi:cog" class="w-8 h-8 text-[var(--brand)]" />
            <h1 class="text-3xl font-bold txt-primary">{{ $t('admin.config.title') }}</h1>
          </div>
          <p class="txt-secondary">{{ $t('admin.config.description') }}</p>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center py-20">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
        </div>

        <!-- Content -->
        <div v-else-if="schema" class="space-y-6">
          <!-- Tab group dropdowns (desktop/tablet, max 3, mirrors AdvancedWidgetConfig).
               On phones this is replaced by the single dropdown below (same
               pattern as FilesTabs.vue). -->
          <div
            ref="tabBarRef"
            class="hidden md:block border-b border-light-border/30 dark:border-dark-border/20"
          >
            <div class="flex gap-1 sm:gap-2 py-2">
              <div v-for="group in tabGroups" :key="group.id" class="relative flex-1 sm:flex-none">
                <button
                  type="button"
                  :class="[
                    'w-full sm:w-auto flex items-center justify-between gap-2 px-3 sm:px-4 py-2 rounded-lg font-medium text-xs sm:text-sm transition-colors',
                    group.tabs.some((t) => t.id === activeTab)
                      ? 'bg-[var(--brand)]/10 txt-brand'
                      : 'txt-secondary hover:txt-primary hover-surface',
                  ]"
                  :data-testid="`btn-config-group-${group.id}`"
                  @click="toggleGroup(group.id)"
                >
                  <span class="flex items-center gap-1.5 min-w-0">
                    <Icon :icon="group.icon" class="w-4 h-4 flex-shrink-0" />
                    <span class="truncate">{{ $t(group.labelKey) }}</span>
                  </span>
                  <Icon
                    icon="heroicons:chevron-down"
                    :class="[
                      'w-4 h-4 flex-shrink-0 transition-transform',
                      openGroup === group.id && 'rotate-180',
                    ]"
                  />
                </button>

                <!-- Dropdown menu -->
                <div
                  v-if="openGroup === group.id"
                  class="absolute left-0 top-full mt-1 z-20 min-w-[12rem] surface-card rounded-lg shadow-xl border border-light-border/30 dark:border-dark-border/20 py-1"
                  :data-testid="`menu-config-group-${group.id}`"
                >
                  <button
                    v-for="tab in group.tabs"
                    :key="tab.id"
                    type="button"
                    :class="[
                      'w-full flex items-center gap-2 px-3 py-2 text-left text-sm transition-colors',
                      activeTab === tab.id
                        ? 'txt-brand bg-[var(--brand)]/5'
                        : 'txt-secondary hover:txt-primary hover-surface',
                    ]"
                    :data-testid="`btn-config-tab-${tab.id}`"
                    @click="selectTab(tab.id)"
                  >
                    <Icon :icon="tab.icon" class="w-4 h-4 flex-shrink-0" />
                    <span class="truncate">{{ tab.label }}</span>
                    <Icon
                      v-if="activeTab === tab.id"
                      icon="heroicons:check"
                      class="w-4 h-4 ml-auto flex-shrink-0"
                    />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Tab dropdown (mobile): a single dropdown replaces the 3-group bar
               (same pattern as FilesTabs.vue). The trigger shows the current
               tab; the panel keeps every group's sub-tabs under its own
               section header so nothing from the desktop dropdowns is lost. -->
          <div
            ref="mobileTabDropdownRef"
            class="md:hidden relative border-b border-light-border/30 dark:border-dark-border/20 pb-2"
          >
            <button
              type="button"
              class="dropdown-trigger surface-card w-full justify-between border border-light-border/20 dark:border-dark-border/10"
              :aria-expanded="mobileTabMenuOpen"
              aria-haspopup="menu"
              data-testid="tab-admin-config-mobile-trigger"
              @click="toggleMobileTabMenu"
            >
              <span class="flex items-center gap-2 txt-primary font-medium min-w-0">
                <Icon :icon="tabIcons[activeTab] || 'mdi:cog'" class="w-5 h-5 flex-shrink-0" />
                <span class="truncate">{{ currentTab?.label }}</span>
              </span>
              <Icon
                icon="heroicons:chevron-down"
                class="w-5 h-5 flex-shrink-0 transition-transform"
                :class="{ 'rotate-180': mobileTabMenuOpen }"
              />
            </button>

            <div
              v-if="mobileTabMenuOpen"
              class="dropdown-panel absolute left-0 right-0 top-full mt-1 z-30 max-h-[70vh] overflow-y-auto scroll-thin"
              role="menu"
              data-testid="tab-admin-config-mobile-menu"
            >
              <template v-for="(group, groupIdx) in tabGroups" :key="group.id">
                <p
                  class="px-3 pt-2.5 pb-1 text-[10px] font-semibold txt-secondary uppercase tracking-wider opacity-60"
                  :class="{
                    'border-t border-light-border/10 dark:border-dark-border/10 mt-1': groupIdx > 0,
                  }"
                >
                  {{ $t(group.labelKey) }}
                </p>
                <button
                  v-for="tab in group.tabs"
                  :key="tab.id"
                  type="button"
                  role="menuitem"
                  :class="['dropdown-item', activeTab === tab.id && 'dropdown-item--active']"
                  :data-testid="`btn-config-tab-${tab.id}-mobile`"
                  @click="selectMobileTab(tab.id)"
                >
                  <Icon :icon="tab.icon" class="w-5 h-5 flex-shrink-0" />
                  <span class="flex-1 text-left truncate">{{ tab.label }}</span>
                  <Icon
                    v-if="activeTab === tab.id"
                    icon="heroicons:check"
                    class="w-4 h-4 flex-shrink-0"
                  />
                </button>
              </template>
            </div>
          </div>

          <!-- Active tab title + actions -->
          <div class="flex items-center justify-between gap-2">
            <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon :icon="tabIcons[activeTab] || 'mdi:cog'" class="w-6 h-6 text-[var(--brand)]" />
              {{ currentTab?.label }}
            </h2>
            <button
              v-if="canTestCurrentTab"
              type="button"
              :disabled="!!testingService"
              class="btn-secondary px-4 py-2 rounded-lg flex items-center gap-2"
              @click="handleTestConnection"
            >
              <Icon
                :icon="testingService ? 'mdi:loading' : 'mdi:connection'"
                :class="['w-5 h-5', testingService && 'animate-spin']"
              />
              {{ $t('admin.config.testConnection') }}
            </button>
          </div>

          <!-- Sections -->
          <div class="space-y-8">
            <div
              v-for="section in currentSections"
              :key="section.id"
              class="surface-card rounded-xl p-6"
            >
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
                  <Icon icon="mdi:folder-cog" class="w-5 h-5 txt-secondary" />
                  {{ section.label }}
                </h3>
                <span
                  v-if="section.isLive"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"
                  :title="$t('admin.config.liveHint')"
                >
                  <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                  {{ $t('admin.config.liveBadge') }}
                </span>
              </div>
              <p v-if="section.isLive" class="text-xs txt-secondary mb-4 -mt-2">
                {{ $t('admin.config.liveHint') }}
              </p>
              <div class="space-y-4">
                <ConfigField
                  v-for="field in section.fields"
                  :key="field.key"
                  :field-key="field.key"
                  :schema="field.schema"
                  :value="field.value"
                  @update="handleUpdate"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Error State -->
        <div v-else class="text-center py-20">
          <Icon icon="mdi:alert-circle" class="w-12 h-12 txt-secondary mx-auto mb-4" />
          <p class="txt-secondary">{{ $t('admin.config.loadError') }}</p>
          <button type="button" class="btn-primary mt-4 px-6 py-2 rounded-lg" @click="loadConfig">
            {{ $t('common.retry') }}
          </button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
