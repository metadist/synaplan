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
      <main class="flex-1 min-h-0 overflow-y-auto" data-testid="section-primary-content">
        <slot />
      </main>
    </div>

    <!-- Help system host -->
    <HelpHost />
  </div>
</template>

<script setup lang="ts">
import { computed, watchEffect, onMounted, onBeforeUnmount } from 'vue'
import Sidebar from './Sidebar.vue'
import SidebarV2 from './SidebarV2.vue'
import Header from './Header.vue'
import HelpHost from './help/HelpHost.vue'
import { useSidebarStore } from '../stores/sidebar'
import { useAuthStore } from '../stores/auth'
import { useGuestStore } from '../stores/guest'

const sidebarStore = useSidebarStore()
const authStore = useAuthStore()
const guestStore = useGuestStore()

const isV2 = computed(() => !authStore.isAuthenticated && guestStore.isGuestMode)

watchEffect(() => {
  document.documentElement.classList.toggle('design-v2', isV2.value)
})

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
