<template>
  <nav class="flex flex-col gap-0.5 px-2 py-2" :aria-label="$t('nav.files')">
    <router-link
      v-for="item in items"
      :key="item.id"
      :to="item.to"
      class="flex items-center gap-2 min-h-11 px-2 rounded-lg text-[15px] transition-colors"
      :class="
        isActive(item.to)
          ? 'text-[var(--brand)] bg-[var(--brand)]/[0.08] font-medium'
          : 'txt-primary hover:bg-black/[0.04] dark:hover:bg-white/[0.04]'
      "
      :aria-current="isActive(item.to) ? 'page' : undefined"
      :data-testid="item.testid"
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
import { computed, onMounted, ref, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  CircleStackIcon,
  FolderIcon,
  FolderPlusIcon,
  InboxArrowDownIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { getConfigSync } from '@/services/api/httpClient'
import filesService from '@/services/filesService'

type LibraryLink = {
  id: string
  to: string
  label: string
  icon: Component
  testid: string
  badge?: number
}

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const incomingCount = ref(0)

const items = computed<LibraryLink[]>(() => {
  const links: LibraryLink[] = [
    {
      id: 'files',
      to: '/files',
      label: t('files.tabBrowse'),
      icon: FolderIcon,
      testid: 'link-sidebar-v2-files-browse',
    },
    {
      id: 'incoming',
      to: '/files/incoming',
      label: t('files.tabIncoming'),
      icon: InboxArrowDownIcon,
      testid: 'link-sidebar-v2-files-incoming',
      badge: incomingCount.value > 0 ? incomingCount.value : undefined,
    },
    {
      id: 'generated',
      to: '/files/generated',
      label: t('files.tabGenerated'),
      icon: SparklesIcon,
      testid: 'link-sidebar-v2-files-generated',
    },
  ]

  if (getConfigSync().features?.computeWorkspacesEnabled === true) {
    links.push({
      id: 'workspace',
      to: '/files/workspace',
      label: t('files.tabWorkspace'),
      icon: FolderPlusIcon,
      testid: 'link-sidebar-v2-files-workspace',
    })
  }

  links.push({
    id: 'search',
    to: '/files/search',
    label: t('files.tabSearch'),
    icon: MagnifyingGlassIcon,
    testid: 'link-sidebar-v2-files-search',
  })

  if (authStore.isAdmin) {
    links.push({
      id: 'vectors',
      to: '/files/vectors',
      label: t('files.tabVectors'),
      icon: CircleStackIcon,
      testid: 'link-sidebar-v2-files-vectors',
    })
  }

  return links
})

function isActive(path: string): boolean {
  return path === '/files' ? route.path === '/files' : route.path.startsWith(path)
}

onMounted(async () => {
  try {
    const facets = await filesService.getFacets()
    incomingCount.value = facets.incoming
  } catch {
    incomingCount.value = 0
  }
})
</script>
