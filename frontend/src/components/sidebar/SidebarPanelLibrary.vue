<template>
  <nav class="flex flex-col gap-0.5 px-2 py-2" :aria-label="$t('nav.files')">
    <router-link
      v-for="item in links"
      :key="item.id"
      :to="item.to"
      class="flex items-center gap-2 min-h-11 px-2 rounded-lg text-[15px] transition-colors"
      :class="
        isActive(item.to)
          ? 'text-[var(--brand)] bg-[var(--brand)]/[0.08] font-medium'
          : 'txt-primary hover:bg-black/[0.04] dark:hover:bg-white/[0.04]'
      "
      :aria-current="isActive(item.to) ? 'page' : undefined"
      :data-testid="item.sidebarTestId"
    >
      <component :is="item.icon" class="w-5 h-5 flex-shrink-0" aria-hidden="true" />
      <span class="flex-1 truncate">{{ item.label }}</span>
      <span
        v-if="item.badge"
        class="text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] tabular-nums"
      >
        {{ item.badge }}
      </span>
    </router-link>
  </nav>
</template>

<script setup lang="ts">
import { useRoute } from 'vue-router'
import { isLibraryLinkActive, useLibraryLinks } from '@/composables/useLibraryLinks'

const route = useRoute()
const { links } = useLibraryLinks()

function isActive(path: string): boolean {
  return isLibraryLinkActive(path, route.path)
}
</script>
