<template>
  <nav class="flex flex-col gap-0.5 px-3 pt-3 pb-3" :aria-label="$t('nav.files')">
    <SidebarNavLink
      v-for="item in links"
      :key="item.id"
      :to="item.to"
      :label="item.label"
      :icon="item.icon"
      :badge="item.badge"
      :active="isActive(item.to)"
      :test-id="item.sidebarTestId"
    />
  </nav>
</template>

<script setup lang="ts">
import { useRoute } from 'vue-router'
import { isLibraryLinkActive, useLibraryLinks } from '@/composables/useLibraryLinks'
import SidebarNavLink from './SidebarNavLink.vue'

const route = useRoute()
const { links } = useLibraryLinks()

function isActive(path: string): boolean {
  return isLibraryLinkActive(path, route.path)
}
</script>
