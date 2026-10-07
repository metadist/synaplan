<template>
  <aside class="v2-sidebar-rail flex flex-col items-center" data-testid="section-sidebar-rail">
    <div class="flex items-center justify-center flex-shrink-0 h-14 w-full">
      <router-link
        to="/"
        class="inline-flex items-center justify-center rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :aria-label="$t('nav.home')"
        :title="$t('nav.home')"
        data-testid="link-sidebar-brand"
      >
        <img
          :src="iconSrc"
          :alt="configStore.branding.name"
          class="h-6 w-auto"
          data-testid="img-sidebar-brand"
        />
      </router-link>
    </div>

    <button
      v-if="!panelVisible"
      type="button"
      class="v2-rail-icon mb-1 inline-flex h-9 w-11 flex-shrink-0 items-center justify-center rounded-xl"
      :aria-label="$t('nav.expandSidebar')"
      :title="$t('nav.expandSidebar')"
      aria-controls="sidebar-v2-panel"
      aria-expanded="false"
      data-testid="btn-sidebar-v2-expand"
      @click="expand"
    >
      <ChevronDoubleRightIcon class="h-4 w-4" aria-hidden="true" />
    </button>

    <nav
      class="flex-1 flex flex-col items-center gap-1 py-1 w-full overflow-y-auto sidebar-scroll"
      :aria-label="$t('nav.menu')"
      @scroll="hideTipNow"
    >
      <button
        v-for="section in sections"
        :key="section.key"
        type="button"
        class="v2-rail-icon relative w-11 h-11 inline-flex items-center justify-center rounded-xl"
        :class="activeKey === section.key && 'v2-rail-icon--active'"
        :aria-label="railLabel(section)"
        :aria-describedby="tip?.key === section.key ? TOOLTIP_ID : undefined"
        :aria-current="activeKey === section.key ? 'page' : undefined"
        :data-testid="section.testId"
        @pointerenter="scheduleTip(section, $event)"
        @pointerleave="hideTipSoon"
        @focus="scheduleTip(section, $event, true)"
        @blur="hideTipNow"
        @click="openSection(section.key)"
      >
        <component :is="section.icon" class="w-6 h-6" aria-hidden="true" />
        <span
          v-if="section.key === 'chats' && incomingStore.hasNew"
          class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-[var(--status-error)] ring-2 ring-[var(--bg-sidebar-panel)]"
          aria-hidden="true"
          data-testid="dot-sidebar-v2-incoming-new"
        />
      </button>
    </nav>

    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-150 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="tip"
          :id="TOOLTIP_ID"
          role="tooltip"
          class="dropdown-panel pointer-events-none fixed z-[200] -translate-y-1/2 px-3 py-2"
          :style="{ top: `${tip.top}px`, left: `${tip.left}px` }"
          data-testid="tooltip-sidebar-rail"
        >
          <p class="text-sm font-medium txt-primary whitespace-nowrap">{{ tip.label }}</p>
          <p
            v-if="tip.description !== tip.label"
            class="mt-0.5 max-w-[14rem] text-xs leading-snug txt-secondary"
          >
            {{ tip.description }}
          </p>
          <p
            v-if="tip.key === 'chats' && incomingStore.hasNew"
            class="mt-0.5 text-xs font-medium text-[var(--status-error-text)] whitespace-nowrap"
          >
            {{ incomingNewLabel }}
          </p>
        </div>
      </Transition>
    </Teleport>

    <div
      v-if="versionLabel || schedulerStore.isStale"
      class="mb-3 flex w-full flex-shrink-0 flex-col items-center"
      data-testid="section-sidebar-v2-version"
    >
      <SchedulerStaleHint compact />
      <div class="flex h-11 w-full items-center justify-center px-0.5">
        <a
          v-if="showUpdateLink"
          :href="updatesStore.guideUrl ?? undefined"
          target="_blank"
          rel="noopener noreferrer"
          class="max-w-full text-center text-[10px] font-semibold leading-tight break-all"
          :class="
            isSecurityUpdate
              ? 'text-[var(--status-error-text)]'
              : 'text-[var(--status-warning-text)]'
          "
          :title="updateHint"
          :aria-label="updateHint"
          data-testid="link-sidebar-v2-update"
        >
          {{ versionLabel }}
        </a>
        <span
          v-else-if="versionLabel"
          class="max-w-full text-center text-[10px] leading-tight txt-secondary break-all"
          :title="$t('updates.runningVersion', { version: versionLabel })"
          data-testid="text-sidebar-v2-version"
        >
          {{ versionLabel }}
        </span>
      </div>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, computed, watch, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDoubleRightIcon } from '@heroicons/vue/24/outline'
import SchedulerStaleHint from '@/components/SchedulerStaleHint.vue'
import { useConfigStore } from '@/stores/config'
import { focusSidebarToggle, useSidebarLayout } from '@/composables/useSidebarLayout'
import { useSchedulerStore } from '@/stores/scheduler'
import { useUpdatesStore } from '@/stores/updates'
import { useIncomingStore } from '@/stores/incoming'
import { useTheme } from '@/composables/useTheme'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useNavSections, type NavSection } from '@/composables/useNavSections'
import { formatRunningVersion } from '@/utils/formatRunningVersion'

const configStore = useConfigStore()
const { panelOverlay, panelVisible, openPanel, closePanel } = useSidebarLayout()
const updatesStore = useUpdatesStore()
const schedulerStore = useSchedulerStore()
const incomingStore = useIncomingStore()
const { t } = useI18n()

const incomingNewLabel = computed(() =>
  t('iam.incoming.newCount', { count: incomingStore.unseenCount })
)

/** The chats icon carries the incoming badge, so its name says so too. */
function railLabel(section: NavSection): string {
  if (section.key !== 'chats' || !incomingStore.hasNew) return section.label
  return `${section.label}, ${incomingNewLabel.value}`
}
const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)
const { sections, activeKey, selectSection, loadFeatureStatus } = useNavSections()

const TOOLTIP_ID = 'tooltip-sidebar-rail'
const TIP_DELAY_MS = 180

const tip = ref<{
  key: string
  label: string
  description: string
  top: number
  left: number
} | null>(null)
let tipTimer = 0

function placeTip(section: NavSection, anchor: HTMLElement) {
  const rect = anchor.getBoundingClientRect()
  tip.value = {
    key: section.key,
    label: section.label,
    description: section.description,
    top: rect.top + rect.height / 2,
    left: rect.right + 10,
  }
}

function scheduleTip(section: NavSection, event: Event, immediate = false) {
  const anchor = event.currentTarget
  if (!(anchor instanceof HTMLElement)) return
  if (!immediate && !window.matchMedia('(hover: hover)').matches) return
  window.clearTimeout(tipTimer)
  if (immediate || tip.value) {
    placeTip(section, anchor)
    return
  }
  tipTimer = window.setTimeout(() => placeTip(section, anchor), TIP_DELAY_MS)
}

function expand() {
  openPanel()
  focusSidebarToggle('collapse')
}

function openSection(key: NavSection['key']) {
  hideTipNow()
  // A rail icon names a panel. In the overlay, a second click on the open
  // section closes it again; otherwise choosing an icon shows its panel.
  if (panelOverlay.value && activeKey.value === key) {
    closePanel()
    return
  }
  if (!panelVisible.value) openPanel()
  selectSection(key)
}

function hideTipNow() {
  window.clearTimeout(tipTimer)
  tip.value = null
}

/** Brief pause so moving to the next icon keeps the tooltip and only moves it. */
function hideTipSoon() {
  window.clearTimeout(tipTimer)
  tipTimer = window.setTimeout(() => {
    tip.value = null
  }, 80)
}

/** Five minutes. Polling while the admin is signed in, not a race workaround. */
const SCHEDULER_POLL_MS = 5 * 60 * 1000
let schedulerPoll: ReturnType<typeof setInterval> | null = null

function stopSchedulerPoll(): void {
  if (schedulerPoll === null) return
  clearInterval(schedulerPoll)
  schedulerPoll = null
}

onUnmounted(() => {
  hideTipNow()
  stopSchedulerPoll()
})

const versionLabel = computed(() => formatRunningVersion(configStore.build.version))
const showUpdateLink = computed(() => updatesStore.showBadge && !!updatesStore.guideUrl)
const isSecurityUpdate = computed(() => updatesStore.severity === 'security')
const updateHint = computed(() =>
  t(isSecurityUpdate.value ? 'updates.badge.securityHint' : 'updates.badge.availableHint', {
    version: updatesStore.latestVersion ?? '',
  })
)

watch(
  () => updatesStore.canRead,
  (canRead) => {
    if (canRead) updatesStore.ensureLoaded()
  },
  { immediate: true }
)

watch(
  () => schedulerStore.canRead,
  (canRead) => {
    stopSchedulerPoll()
    if (!canRead) return
    void schedulerStore.ensureLoaded()
    schedulerPoll = setInterval(() => {
      void schedulerStore.load()
    }, SCHEDULER_POLL_MS)
  },
  { immediate: true }
)

onMounted(() => {
  loadFeatureStatus()
})
</script>
