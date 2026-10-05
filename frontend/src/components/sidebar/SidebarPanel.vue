<template>
  <aside
    id="sidebar-v2-panel"
    class="v2-sidebar-panel flex h-full min-h-0 flex-col"
    :class="overlay && 'v2-sidebar-panel--overlay'"
    :role="overlay ? 'dialog' : undefined"
    :aria-label="overlay ? activeSection.label : undefined"
    data-testid="section-sidebar-panel"
    @click="onPanelClick"
  >
    <header class="flex h-14 flex-shrink-0 items-center gap-2 pl-3 pr-2">
      <h2 class="min-w-0 flex-1 truncate text-[15px] font-semibold txt-primary">
        {{ activeSection.label }}
      </h2>
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
      class="sidebar-scroll min-h-0 flex-1 overflow-y-auto"
      data-testid="section-sidebar-scroll"
      @scroll="onPanelScroll"
    >
      <SidebarPanelChats v-if="activeKey === 'chats'" ref="chatsPanel" />
      <SidebarPanelLibrary v-else-if="activeKey === 'library'" />
      <SidebarPanelLinks v-else :groups="activeGroups" :section-path="activeSectionPath" />
    </div>

    <SidebarPanelFooter />
  </aside>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { ChevronDoubleLeftIcon } from '@heroicons/vue/24/outline'
import { useNavSections } from '@/composables/useNavSections'
import { focusSidebarToggle, useSidebarLayout } from '@/composables/useSidebarLayout'
import SidebarPanelChats from './SidebarPanelChats.vue'
import SidebarPanelLibrary from './SidebarPanelLibrary.vue'
import SidebarPanelLinks from './SidebarPanelLinks.vue'
import SidebarPanelFooter from './SidebarPanelFooter.vue'

const props = withDefaults(defineProps<{ overlay?: boolean }>(), { overlay: false })

const emit = defineEmits<{ navigate: [] }>()

/** Picking one of these leaves the panel: a chat row, a page link, New Chat, or search. */
const LEAVES_PANEL =
  'a[href], .chat-row-btn, [data-testid="btn-sidebar-v2-new-chat"], [data-testid="btn-sidebar-v2-search"]'

const { activeKey, activeSection, activeGroups, activeSectionPath } = useNavSections()
const { closePanel } = useSidebarLayout()

const chatsPanel = ref<{ showMoreChats: () => void } | null>(null)

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
  if (activeKey.value !== 'chats') return
  const el = event.currentTarget
  if (!(el instanceof HTMLElement)) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight > 160) return
  return chatsPanel.value?.showMoreChats()
}
</script>
