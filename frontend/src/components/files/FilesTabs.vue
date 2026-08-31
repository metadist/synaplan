<template>
  <div data-testid="tabs-files">
    <PageHeader :title="$t('nav.files')" icon="heroicons:folder-open">
      <!-- §4.8 #1: one vocabulary — say what the chat input already implies. -->
      <template #subtitle>{{ $t('files.intro') }} {{ $t('files.introCta') }}</template>
      <TabNav
        :model-value="active"
        :tabs="tabNavItems"
        :aria-label="$t('nav.files')"
        mobile-trigger-testid="tab-files-mobile-trigger"
        mobile-menu-testid="tab-files-mobile-menu"
        @update:model-value="onTabChange"
      />
    </PageHeader>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import filesService from '@/services/filesService'
import { useAuthStore } from '@/stores/auth'

type FilesTab = 'files' | 'search' | 'vectors' | 'incoming' | 'generated'

defineProps<{
  active: FilesTab
}>()

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()

const pathById: Record<FilesTab, string> = {
  files: '/files',
  incoming: '/files/incoming',
  generated: '/files/generated',
  search: '/files/search',
  vectors: '/files/vectors',
}

// Incoming count badge — shown so the user notices when integrations have
// pushed new files for triage (§4.5). Best-effort, silent.
const incomingCount = ref(0)

const tabNavItems = computed<TabNavItem[]>(() => [
  {
    id: 'files',
    label: t('files.tabBrowse'),
    icon: 'heroicons:folder',
    testid: 'tab-files-browse',
    to: pathById.files,
  },
  {
    id: 'incoming',
    label: t('files.tabIncoming'),
    icon: 'heroicons:inbox-arrow-down',
    testid: 'tab-files-incoming',
    to: pathById.incoming,
    badge: incomingCount.value,
  },
  {
    id: 'generated',
    label: t('files.tabGenerated'),
    icon: 'heroicons:sparkles',
    testid: 'tab-files-generated',
    to: pathById.generated,
  },
  {
    id: 'search',
    label: t('files.tabSearch'),
    icon: 'heroicons:magnifying-glass',
    testid: 'tab-files-search',
    to: pathById.search,
  },
  ...(authStore.isAdmin
    ? [
        {
          id: 'vectors',
          label: t('files.tabVectors'),
          icon: 'heroicons:circle-stack',
          testid: 'tab-files-vectors',
          to: pathById.vectors,
        } satisfies TabNavItem,
      ]
    : []),
])

function onTabChange(id: string) {
  const path = pathById[id as FilesTab]
  if (path) router.push(path)
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
