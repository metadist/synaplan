<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import BrandingStyleResetCard from '@/components/admin/BrandingStyleResetCard.vue'
import ConfigSectionStack from '@/components/admin/ConfigSectionStack.vue'
import RestartRequiredBanner from '@/components/admin/RestartRequiredBanner.vue'
import UpdatePanel from '@/components/admin/UpdatePanel.vue'
import WebSearchPlugTab from '@/components/admin/plugs/WebSearchPlugTab.vue'
import { useSystemConfig } from '@/composables/useSystemConfig'
import { useTheme } from '@/composables/useTheme'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import { useAuthStore } from '@/stores/auth'
import { useUpdatesStore } from '@/stores/updates'
import { systemConfigRedirect } from '@/router/operateRedirects'
import {
  AI_INFRA_PATH,
  AI_TAB_SECTIONS,
  SYSTEM_CONFIG_GROUPS,
  sectionKey,
  type ConfigSectionRef,
  type SystemGroupId,
  type SystemTabDef,
} from '@/constants/operateSettings'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const updatesStore = useUpdatesStore()
const { isDark } = useTheme()
const systemConfig = useSystemConfig()
const { schema } = systemConfig

// Branding colors are stored per theme mode: the admin edits the set matching
// the mode the app is currently rendered in (a hint below the tab title says
// which one). The other mode's fields are hidden to avoid ambiguity.
const LIGHT_MODE_BRANDING_FIELDS = [
  'BRAND_PRIMARY_COLOR',
  'BRAND_SECONDARY_COLOR',
  'BRAND_ACCENT_COLOR',
]
const DARK_MODE_BRANDING_FIELDS = [
  'BRAND_PRIMARY_COLOR_DARK',
  'BRAND_SECONDARY_COLOR_DARK',
  'BRAND_ACCENT_COLOR_DARK',
]

const FALLBACK_TAB_PREFIX = 'more_'

interface ConfigTabView {
  id: string
  icon: string
  label: string
  intro: string
  sections: ConfigSectionRef[]
  panel?: SystemTabDef['panel']
}

interface ConfigGroupView {
  id: SystemGroupId
  label: string
  tabs: ConfigTabView[]
}

function tabLabel(id: string, fallback: string): string {
  return te(`admin.config.tabs.${id}`) ? t(`admin.config.tabs.${id}`) : fallback
}

function tabIntro(id: string): string {
  const key = `admin.config.tabIntro.${id.startsWith(FALLBACK_TAB_PREFIX) ? 'more' : id}`
  return te(key) ? t(key) : ''
}

/**
 * The topic groups of this page, resolved against the live schema. Sections
 * owned by AI infrastructure never show here; a section no topic claims lands
 * under "More settings" so it stays reachable.
 */
const groups = computed<ConfigGroupView[]>(() => {
  const current = schema.value
  if (!current) return []

  const exists = (ref: ConfigSectionRef) => !!current.tabs[ref.tab]?.sections[ref.section]
  const explicit = new Set<string>()
  for (const refs of Object.values(AI_TAB_SECTIONS))
    refs.forEach((r) => explicit.add(sectionKey(r)))
  for (const group of SYSTEM_CONFIG_GROUPS) {
    for (const tab of group.tabs) (tab.sections ?? []).forEach((r) => explicit.add(sectionKey(r)))
  }

  const placed = new Set<string>()
  const resolved: ConfigGroupView[] = SYSTEM_CONFIG_GROUPS.map((group) => ({
    id: group.id,
    label: t(`admin.config.groups.${group.id}`),
    tabs: group.tabs
      .map((def) => {
        const sections: ConfigSectionRef[] = []
        const backendTab = def.backendTab ? current.tabs[def.backendTab] : undefined
        if (def.backendTab && backendTab) {
          for (const section of Object.keys(backendTab.sections)) {
            const ref = { tab: def.backendTab, section }
            if (!explicit.has(sectionKey(ref))) sections.push(ref)
          }
        }
        sections.push(...(def.sections ?? []).filter(exists))
        sections.forEach((r) => placed.add(sectionKey(r)))
        return {
          id: def.id,
          icon: def.icon,
          label: tabLabel(def.id, backendTab?.label ?? def.id),
          intro: tabIntro(def.id),
          sections,
          panel: def.panel,
        }
      })
      .filter((tab) => tab.sections.length > 0 || tab.panel),
  }))

  const leftovers: ConfigTabView[] = []
  for (const [tabId, tab] of Object.entries(current.tabs)) {
    const sections = Object.keys(tab.sections)
      .map((section) => ({ tab: tabId, section }))
      .filter((ref) => !placed.has(sectionKey(ref)) && !explicit.has(sectionKey(ref)))
    if (sections.length === 0) continue
    const id = `${FALLBACK_TAB_PREFIX}${tabId}`
    leftovers.push({
      id,
      icon: 'mdi:cog',
      label: tabLabel(tabId, tab.label),
      intro: tabIntro(id),
      sections,
    })
  }
  if (leftovers.length > 0) {
    resolved.push({ id: 'more', label: t('admin.config.groups.more'), tabs: leftovers })
  }

  return resolved.filter((group) => group.tabs.length > 0)
})

const allTabs = computed(() => groups.value.flatMap((group) => group.tabs))

const requestedTab = ref(typeof route.query.tab === 'string' ? route.query.tab : '')
const currentTab = computed<ConfigTabView | null>(
  () => allTabs.value.find((tab) => tab.id === requestedTab.value) ?? allTabs.value[0] ?? null
)
const activeTab = computed(() => currentTab.value?.id ?? '')

const hiddenFields = computed(() => {
  if (activeTab.value !== 'branding') return []
  return isDark.value ? LIGHT_MODE_BRANDING_FIELDS : DARK_MODE_BRANDING_FIELDS
})

/**
 * Keep the tab in the URL so a setting can be linked to directly — "open
 * Settings and look for it" is not a usable instruction.
 */
function selectTab(tabId: string): void {
  requestedTab.value = tabId
  mobileTabMenuOpen.value = false
  if (route.query.tab === tabId) return
  void router.replace({ query: { ...route.query, tab: tabId, section: undefined } })
}

watch(
  () => route.query.tab,
  (tab) => {
    const redirect = systemConfigRedirect(route)
    if (redirect !== true) {
      void router.replace(redirect)
      return
    }
    requestedTab.value = typeof tab === 'string' ? tab : ''
  }
)

// Mobile: one dropdown lists every tab under its topic header, so the
// desktop topic list is not lost on a phone.
const mobileTabMenuOpen = ref(false)
const mobileTabDropdownRef = ref<HTMLElement | null>(null)

function toggleMobileTabMenu(): void {
  triggerHapticImpact('light')
  mobileTabMenuOpen.value = !mobileTabMenuOpen.value
}

function handleOutsideClick(event: MouseEvent): void {
  if (
    mobileTabMenuOpen.value &&
    mobileTabDropdownRef.value &&
    !mobileTabDropdownRef.value.contains(event.target as Node)
  ) {
    mobileTabMenuOpen.value = false
  }
}

function handleEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape') mobileTabMenuOpen.value = false
}

onMounted(async () => {
  document.addEventListener('click', handleOutsideClick)
  document.addEventListener('keydown', handleEscape)

  if (!authStore.isAdmin) {
    void router.push('/admin')
    return
  }
  await systemConfig.load()
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
  document.removeEventListener('keydown', handleEscape)
})
</script>

<template>
  <MainLayout data-testid="view-admin-config">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="container mx-auto max-w-6xl">
        <RestartRequiredBanner
          :visible="systemConfig.restartRequired.value"
          @dismiss="systemConfig.dismissRestart"
        />

        <PageHeader
          :title="$t('admin.config.title')"
          :subtitle="$t('admin.config.description')"
          icon="mdi:cog"
        />

        <p
          class="mb-6 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm txt-secondary"
          data-testid="config-ai-pointer"
        >
          <Icon icon="mdi:robot-outline" class="w-4 h-4 flex-shrink-0 text-[var(--brand)]" />
          <span>{{ $t('admin.config.aiPointer') }}</span>
          <RouterLink
            :to="AI_INFRA_PATH"
            class="font-medium text-[var(--brand)] hover:underline"
            data-testid="config-ai-pointer-link"
          >
            {{ $t('admin.config.aiPointerLink') }}
          </RouterLink>
        </p>

        <!-- Release notice: informs and links to the guide, never updates anything -->
        <UpdatePanel v-if="updatesStore.canRead" class="mb-6" />

        <div
          v-if="!schema && !systemConfig.loadFailed.value"
          class="flex items-center justify-center py-20"
          data-testid="config-loading"
        >
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
        </div>

        <div
          v-else-if="schema && currentTab"
          class="md:grid md:grid-cols-[13rem_minmax(0,1fr)] lg:grid-cols-[15rem_minmax(0,1fr)] md:gap-8"
        >
          <!-- Desktop / tablet: every topic and tab visible at once -->
          <nav class="hidden md:block" :aria-label="$t('admin.config.title')">
            <div class="sticky top-4 space-y-5" data-testid="config-topic-nav">
              <div
                v-for="group in groups"
                :key="group.id"
                :data-testid="`config-group-${group.id}`"
              >
                <p class="px-3 mb-1 text-xs font-semibold txt-secondary uppercase tracking-wider">
                  {{ group.label }}
                </p>
                <ul class="space-y-0.5">
                  <li v-for="tab in group.tabs" :key="tab.id">
                    <button
                      type="button"
                      :class="[
                        'w-full flex items-center gap-2 px-3 py-2 rounded-xl text-left text-sm transition-colors',
                        activeTab === tab.id
                          ? 'txt-brand bg-[var(--brand)]/5 font-medium'
                          : 'txt-secondary hover:txt-primary hover-surface',
                      ]"
                      :aria-current="activeTab === tab.id ? 'page' : undefined"
                      :data-testid="`btn-config-tab-${tab.id}`"
                      @click="selectTab(tab.id)"
                    >
                      <Icon :icon="tab.icon" class="w-4 h-4 flex-shrink-0" />
                      <span class="min-w-0 break-words">{{ tab.label }}</span>
                    </button>
                  </li>
                </ul>
              </div>
            </div>
          </nav>

          <!-- Phones: one dropdown, tabs listed under their topic header -->
          <div
            ref="mobileTabDropdownRef"
            class="md:hidden relative border-b border-light-border/30 dark:border-dark-border/20 pb-2 mb-6"
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
                <Icon :icon="currentTab.icon" class="w-5 h-5 flex-shrink-0" />
                <span class="truncate">{{ currentTab.label }}</span>
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
              <template v-for="(group, groupIdx) in groups" :key="group.id">
                <p
                  class="px-3 pt-2.5 pb-1 text-[10px] font-semibold txt-secondary uppercase tracking-wider"
                  :class="{
                    'border-t border-light-border/10 dark:border-dark-border/10 mt-1': groupIdx > 0,
                  }"
                >
                  {{ group.label }}
                </p>
                <button
                  v-for="tab in group.tabs"
                  :key="tab.id"
                  type="button"
                  role="menuitem"
                  :class="['dropdown-item', activeTab === tab.id && 'dropdown-item--active']"
                  :data-testid="`btn-config-tab-${tab.id}-mobile`"
                  @click="selectTab(tab.id)"
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

          <div class="min-w-0 space-y-6" :data-testid="`config-tab-${currentTab.id}`">
            <div>
              <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
                <Icon :icon="currentTab.icon" class="w-6 h-6 text-[var(--brand)]" />
                {{ currentTab.label }}
              </h2>
              <p v-if="currentTab.intro" class="text-sm txt-secondary mt-1">
                {{ currentTab.intro }}
              </p>
            </div>

            <!-- Branding is edited per theme mode: tell the admin which set they are changing -->
            <p
              v-if="currentTab.id === 'branding'"
              class="flex items-center gap-2 text-sm txt-secondary"
              data-testid="branding-mode-hint"
            >
              <Icon
                :icon="isDark ? 'mdi:weather-night' : 'mdi:white-balance-sunny'"
                class="w-4 h-4 flex-shrink-0 text-[var(--brand)]"
              />
              {{
                isDark
                  ? $t('admin.config.brandingMode.dark')
                  : $t('admin.config.brandingMode.light')
              }}
            </p>

            <WebSearchPlugTab v-if="currentTab.panel === 'web-search'" />

            <BrandingStyleResetCard v-if="currentTab.id === 'branding'" :config="systemConfig" />

            <section
              v-if="currentTab.sections.length > 0"
              class="space-y-3"
              :aria-labelledby="currentTab.panel ? `config-settings-${currentTab.id}` : undefined"
            >
              <div v-if="currentTab.panel">
                <h3
                  :id="`config-settings-${currentTab.id}`"
                  class="text-lg font-semibold txt-primary"
                >
                  {{ $t(`admin.config.panelSettings.${currentTab.id}.title`) }}
                </h3>
                <p class="text-sm txt-secondary mt-1">
                  {{ $t(`admin.config.panelSettings.${currentTab.id}.hint`) }}
                </p>
              </div>
              <ConfigSectionStack
                :key="currentTab.id"
                :config="systemConfig"
                :sections="currentTab.sections"
                :hidden-fields="hiddenFields"
              />
            </section>
          </div>
        </div>

        <!-- Error State -->
        <div v-else class="text-center py-20" data-testid="config-load-error">
          <Icon icon="mdi:alert-circle" class="w-12 h-12 txt-secondary mx-auto mb-4" />
          <p class="txt-secondary">{{ $t('admin.config.loadError') }}</p>
          <button
            type="button"
            class="btn-primary mt-4 px-4 py-2.5 rounded-xl text-sm font-medium"
            @click="systemConfig.load"
          >
            {{ $t('common.retry') }}
          </button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
