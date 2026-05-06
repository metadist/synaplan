<template>
  <header
    class="bg-header relative z-50 border-b border-black/[0.04] dark:border-white/[0.04] md:hidden"
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

const sidebarStore = useSidebarStore()
</script>
