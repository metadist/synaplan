<template>
  <button
    v-if="!sidebarStore.isMobileOpen"
    type="button"
    class="fixed top-3 left-3 z-50 w-[42px] h-[42px] rounded-full bg-header shadow-lg border-2 border-black/[0.12] dark:border-white/[0.18] flex items-center justify-center md:hidden"
    :style="{ marginTop: 'env(safe-area-inset-top)' }"
    aria-label="Toggle sidebar"
    data-testid="btn-sidebar-toggle"
    @click="sidebarStore.toggleMobile()"
  >
    <Bars3Icon class="w-5 h-5" aria-hidden="true" />
  </button>
</template>

<script setup lang="ts">
import { Bars3Icon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'

const sidebarStore = useSidebarStore()
</script>
