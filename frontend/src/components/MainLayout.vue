<template>
  <div class="flex h-screen" data-testid="comp-main-layout">
    <SidebarV2 v-if="isV2" />
    <Sidebar v-else />

    <div class="flex-1 flex flex-col min-w-0" data-testid="section-main-shell">
      <Header>
        <template #left>
          <slot name="header" />
        </template>
      </Header>
      <main class="flex-1 overflow-y-auto" data-testid="section-primary-content">
        <slot />
      </main>
    </div>

    <!-- Help system host -->
    <HelpHost />
  </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue'
import Sidebar from './Sidebar.vue'
import SidebarV2 from './SidebarV2.vue'
import Header from './Header.vue'
import HelpHost from './help/HelpHost.vue'
import { useSidebarStore } from '../stores/sidebar'
import { useDesignVariant } from '../composables/useDesignVariant'

const sidebarStore = useSidebarStore()
const { isV2 } = useDesignVariant()

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    sidebarStore.closeMobile()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
})
</script>
