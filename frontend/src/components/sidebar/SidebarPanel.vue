<template>
  <aside class="v2-sidebar-panel flex flex-col min-h-0" data-testid="section-sidebar-panel">
    <div
      v-if="activeKey === 'chats'"
      class="sidebar-scroll flex-1 min-h-0 overflow-y-auto"
      @scroll="onChatsScroll"
    >
      <div class="flex min-h-full flex-col">
        <header class="flex items-center h-14 px-3">
          <h2 class="text-[15px] font-semibold txt-primary truncate">{{ activeSection.label }}</h2>
        </header>
        <SidebarPanelChats ref="chatsPanel" class="flex-1" />
        <SidebarPanelFooter class="mt-auto" />
      </div>
    </div>

    <template v-else>
      <header class="flex items-center h-14 px-3 flex-shrink-0">
        <h2 class="text-[15px] font-semibold txt-primary truncate">{{ activeSection.label }}</h2>
      </header>

      <div class="flex-1 min-h-0 flex flex-col">
        <div class="flex-1 min-h-0 overflow-y-auto sidebar-scroll">
          <SidebarPanelLibrary v-if="activeKey === 'library'" />
          <SidebarPanelLinks v-else :groups="activeGroups" :section-path="activeSectionPath" />
        </div>
      </div>

      <SidebarPanelFooter />
    </template>
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

const onChatsScroll = (event: Event) => {
  const el = event.currentTarget
  if (!(el instanceof HTMLElement)) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight > 160) return
  chatsPanel.value?.showMoreChats()
}
</script>
