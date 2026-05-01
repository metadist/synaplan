<template>
  <header
    class="bg-header relative z-50 border-b border-black/[0.04] dark:border-white/[0.04]"
    data-testid="comp-app-header"
  >
    <div
      class="flex items-center justify-between px-4 sm:px-6 py-3"
      data-testid="section-header-bar"
    >
      <div class="flex items-center gap-3 flex-1 min-w-0" data-testid="section-header-left">
        <button
          class="md:hidden icon-ghost h-[44px] min-w-[44px] flex items-center justify-center rounded-lg"
          aria-label="Toggle sidebar"
          data-testid="btn-sidebar-toggle"
          @click="sidebarStore.toggleMobile()"
        >
          <Bars3Icon class="w-6 h-6" />
        </button>
        <slot name="left" />
      </div>

      <div class="flex items-center gap-2" data-testid="section-header-actions">
        <slot name="actions" />
      </div>
    </div>
  </header>
</template>

<script setup lang="ts">
import { Bars3Icon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'

const sidebarStore = useSidebarStore()
</script>
