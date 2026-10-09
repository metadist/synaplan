<template>
  <div data-testid="section-files-header">
    <PageHeader
      :title="$t('nav.files')"
      :subtitle="subtitle"
      icon="heroicons:folder-open"
      tour-id="library"
    >
      <TabNav
        :model-value="activeId"
        :tabs="tabs"
        :aria-label="$t('nav.files')"
        testid="files-tabs"
        mobile-trigger-testid="files-tabs-mobile-trigger"
        mobile-menu-testid="files-tabs-mobile-menu"
      />
    </PageHeader>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import {
  isLibraryLinkActive,
  useLibraryLinks,
  type LibraryLink,
} from '@/composables/useLibraryLinks'

const TAB_ICONS: Record<LibraryLink['id'], string> = {
  browse: 'heroicons:folder',
  incoming: 'heroicons:inbox-arrow-down',
  generated: 'heroicons:sparkles',
  workspace: 'heroicons:folder-plus',
  search: 'heroicons:magnifying-glass',
}

const { t } = useI18n()
const route = useRoute()
const { links } = useLibraryLinks()

const tabs = computed<TabNavItem[]>(() =>
  links.value.map((link) => ({
    id: link.id,
    label: link.label,
    icon: TAB_ICONS[link.id],
    to: link.to,
    badge: link.badge,
    testid: `tab-files-${link.id}`,
  }))
)

const activeId = computed(
  () => links.value.find((link) => isLibraryLinkActive(link.to, route.path))?.id ?? 'browse'
)

const subtitle = computed(() => {
  switch (activeId.value) {
    case 'incoming':
      return t('files.incoming.subtitle')
    case 'generated':
      return t('files.generated.subtitle')
    case 'workspace':
      return t('files.workspace.subtitle')
    case 'search':
      return t('files.searchIntro')
    default:
      return `${t('files.intro')} ${t('files.introCta')}`
  }
})
</script>
