<template>
  <div class="flex h-dvh overflow-hidden" data-testid="comp-main-layout">
    <SidebarV2 />

    <div class="flex-1 flex flex-col min-w-0" data-testid="section-main-shell">
      <Header />
      <main
        ref="mainRef"
        class="flex-1 min-h-0 overflow-y-auto overscroll-contain pt-[calc(36px+env(safe-area-inset-top))] md:pt-0"
        data-testid="section-primary-content"
        @scroll="handleMainScroll"
      >
        <slot />
      </main>
    </div>

    <!-- Help system host -->
    <HelpHost />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import SidebarV2 from './SidebarV2.vue'
import Header from './Header.vue'
import HelpHost from './help/HelpHost.vue'
import { useSidebarStore } from '../stores/sidebar'
import { useHeaderVisibility } from '../composables/useHeaderVisibility'

const sidebarStore = useSidebarStore()
const { onScroll } = useHeaderVisibility()
const mainRef = ref<HTMLElement | null>(null)

const handleMainScroll = () => {
  if (!mainRef.value) return
  onScroll(mainRef.value.scrollTop)
}

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
