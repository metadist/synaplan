<template>
  <aside
    id="sidebar-v2-panel"
    class="v2-sidebar-panel flex h-full min-h-0 flex-col"
    :class="overlay && 'v2-sidebar-panel--overlay'"
    :role="overlay ? 'dialog' : undefined"
    :aria-label="overlay ? (onSettings ? $t('nav.profile') : activeSection.label) : undefined"
    data-testid="section-sidebar-panel"
    @click="onPanelClick"
  >
    <header class="flex h-14 flex-shrink-0 items-center gap-3 pl-4 pr-3">
      <span
        class="sidebar-panel-badge inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl"
        aria-hidden="true"
      >
        <component :is="headerIcon" class="h-5 w-5" />
      </span>
      <div class="min-w-0 flex-1">
        <h2 class="truncate text-[15px] font-semibold leading-5 txt-primary">
          {{ onSettings ? $t('nav.profile') : activeSection.label }}
        </h2>
        <p
          v-if="headerDescription"
          class="truncate text-xs leading-4 txt-secondary"
          data-testid="text-sidebar-panel-description"
        >
          {{ headerDescription }}
        </p>
      </div>
      <button
        type="button"
        class="icon-ghost inline-flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg"
        :aria-label="$t('nav.collapseSidebar')"
        :title="$t('nav.collapseSidebar')"
        aria-controls="sidebar-v2-panel"
        aria-expanded="true"
        data-testid="btn-sidebar-v2-collapse"
        @click="collapse"
      >
        <ChevronDoubleLeftIcon class="h-4 w-4" aria-hidden="true" />
      </button>
    </header>

    <!--
      The list is the only part that scrolls. Search and the profile stay in
      the footer, so a long history cannot push them off the menu.
    -->
    <div
      ref="scroller"
      class="sidebar-scroll min-h-0 flex-1 overflow-y-auto"
      data-testid="section-sidebar-scroll"
      @scroll="onPanelScroll"
    >
      <SidebarPanelSettings v-if="onSettings" />
      <SidebarPanelChats v-else-if="activeKey === 'chats'" ref="chatsPanel" />
      <SidebarPanelLibrary v-else-if="activeKey === 'library'" />
      <SidebarPanelLinks v-else :groups="activeGroups" :section-path="activeSectionPath" />
    </div>

    <SidebarPanelFooter :content-clipped="contentClipped" />
  </aside>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronDoubleLeftIcon, UserCircleIcon } from '@heroicons/vue/24/outline'
import { isAccountPanelPath, useNavSections } from '@/composables/useNavSections'
import { focusSidebarToggle, useSidebarLayout } from '@/composables/useSidebarLayout'
import { useAuthStore } from '@/stores/auth'
import SidebarPanelChats from './SidebarPanelChats.vue'
import SidebarPanelSettings from './SidebarPanelSettings.vue'
import SidebarPanelLibrary from './SidebarPanelLibrary.vue'
import SidebarPanelLinks from './SidebarPanelLinks.vue'
import SidebarPanelFooter from './SidebarPanelFooter.vue'

const props = withDefaults(defineProps<{ overlay?: boolean }>(), { overlay: false })

const emit = defineEmits<{ navigate: [] }>()

/** Picking one of these leaves the panel: a chat row, a page link, New Chat, or search. */
const LEAVES_PANEL =
  'a[href], .chat-row-btn, [data-testid="btn-sidebar-v2-new-chat"], [data-testid="btn-sidebar-v2-search"]'

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const onSettings = computed(() => authStore.isAuthenticated && isAccountPanelPath(route.path))

const { activeKey, activeSection, activeGroups, activeSectionPath } = useNavSections()
const headerIcon = computed(() => (onSettings.value ? UserCircleIcon : activeSection.value.icon))
/** The chats list repeats "Chat history" as its own heading right below. */
const headerDescription = computed(() => {
  if (onSettings.value) return t('nav.accountDescription')
  return activeKey.value === 'chats' ? null : activeSection.value.description
})
const { closePanel } = useSidebarLayout()

const chatsPanel = ref<{ showMoreChats: () => void } | null>(null)
const scroller = ref<HTMLElement | null>(null)
/** Content still sits below the footer edge, so the list is cut off there. */
const contentClipped = ref(false)
let contentObserver: ResizeObserver | null = null

const updateClip = (target?: EventTarget | null) => {
  const el = target instanceof HTMLElement ? target : scroller.value
  if (!el) return
  // A few pixels of slack: sub-pixel rounding must not leave a shadow on a
  // list that already ends flush with the separator.
  contentClipped.value = el.scrollHeight - el.scrollTop - el.clientHeight > 8
}

/** The list grows after the first paint (pages, pins). Watch its box, not only scroll events. */
const observeContent = () => {
  contentObserver?.disconnect()
  const el = scroller.value
  if (!el || typeof ResizeObserver === 'undefined') return
  contentObserver = new ResizeObserver(() => updateClip())
  contentObserver.observe(el)
  if (el.firstElementChild instanceof HTMLElement) {
    contentObserver.observe(el.firstElementChild)
  }
  updateClip()
}

const collapse = () => {
  closePanel()
  focusSidebarToggle('expand')
}

const onPanelClick = (event: MouseEvent) => {
  if (!props.overlay) return
  const target = event.target
  if (target instanceof Element && target.closest(LEAVES_PANEL)) emit('navigate')
}

const onPanelScroll = (event: Event) => {
  updateClip(event.currentTarget)
  if (activeKey.value !== 'chats') return
  const el = event.currentTarget
  if (!(el instanceof HTMLElement)) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight > 160) return
  return chatsPanel.value?.showMoreChats()
}

watch([activeKey, onSettings], () => {
  void nextTick(() => observeContent())
})

onMounted(() => observeContent())
onBeforeUnmount(() => contentObserver?.disconnect())
</script>

<style scoped>
/* Mirrors the active rail icon, so the panel reads as that icon's page. */
.sidebar-panel-badge {
  color: var(--brand);
  background: linear-gradient(135deg, rgba(0, 63, 199, 0.18), rgba(0, 63, 199, 0.08));
  box-shadow:
    inset 0 0 0 1px rgba(0, 63, 199, 0.16),
    0 2px 10px rgba(0, 63, 199, 0.12);
}

.dark .sidebar-panel-badge {
  background: linear-gradient(135deg, rgba(107, 143, 214, 0.24), rgba(107, 143, 214, 0.1));
  box-shadow:
    inset 0 0 0 1px rgba(107, 143, 214, 0.22),
    0 2px 12px rgba(0, 0, 0, 0.35);
}
</style>
