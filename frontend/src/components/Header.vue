<template>
  <header
    :class="[
      'fixed top-0 inset-x-0 z-50 bg-header border-b border-black/[0.04] dark:border-white/[0.04] md:hidden',
      'transition-transform duration-200 ease-out',
      hidden ? '-translate-y-full' : 'translate-y-0',
    ]"
    data-testid="comp-app-header"
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
</template>

<script setup lang="ts">
import { Bars3Icon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'
import { useHeaderVisibility } from '../composables/useHeaderVisibility'

const sidebarStore = useSidebarStore()
const { hidden } = useHeaderVisibility()
</script>
