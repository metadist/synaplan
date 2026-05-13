<template>
  <div
    class="md:hidden overflow-hidden"
    :style="{ maxHeight: `${(1 - progress) * 100}px` }"
    data-testid="comp-app-header"
  >
    <header
      class="bg-header border-b border-black/[0.04] dark:border-white/[0.04]"
      :style="{ paddingTop: 'env(safe-area-inset-top)' }"
    >
      <div class="flex items-center px-3 py-1.5" data-testid="section-header-bar">
        <button
          type="button"
          class="btn-sidebar-burger"
          aria-label="Toggle sidebar"
          data-testid="btn-sidebar-toggle"
          @click="sidebarStore.toggleMobile()"
        >
          <Bars3Icon class="w-6 h-6" aria-hidden="true" />
        </button>
      </div>
    </header>
  </div>

  <button
    v-if="!sidebarStore.isMobileOpen"
    type="button"
    class="fixed top-3 left-3 z-50 w-[42px] h-[42px] rounded-full bg-header shadow-lg border-2 border-black/[0.12] dark:border-white/[0.18] flex items-center justify-center md:hidden transition-opacity duration-100"
    :style="{
      marginTop: 'env(safe-area-inset-top)',
      opacity: progress,
      pointerEvents: progress < 0.5 ? 'none' : 'auto',
    }"
    aria-label="Toggle sidebar"
    data-testid="btn-sidebar-fab"
    @click="sidebarStore.toggleMobile()"
  >
    <Bars3Icon class="w-5 h-5" aria-hidden="true" />
  </button>
</template>

<script setup lang="ts">
import { Bars3Icon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'
import { useHeaderVisibility } from '../composables/useHeaderVisibility'

const sidebarStore = useSidebarStore()
const { progress } = useHeaderVisibility()
</script>
