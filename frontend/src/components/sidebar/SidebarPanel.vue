<template>
  <aside class="v2-sidebar-panel flex h-full min-h-0 flex-col" data-testid="section-sidebar-panel">
    <header class="flex h-14 flex-shrink-0 items-center px-3">
      <h2 class="truncate text-[15px] font-semibold txt-primary">{{ activeSection.label }}</h2>
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
import { useNavSections } from '@/composables/useNavSections'
import SidebarPanelChats from './SidebarPanelChats.vue'
import SidebarPanelLibrary from './SidebarPanelLibrary.vue'
import SidebarPanelLinks from './SidebarPanelLinks.vue'
import SidebarPanelFooter from './SidebarPanelFooter.vue'

const { activeKey, activeSection, activeGroups, activeSectionPath } = useNavSections()

const chatsPanel = ref<{ showMoreChats: () => void } | null>(null)

const onPanelScroll = (event: Event) => {
  if (activeKey.value !== 'chats') return
  const el = event.currentTarget
  if (!(el instanceof HTMLElement)) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight > 160) return
  chatsPanel.value?.showMoreChats()
}
</script>
