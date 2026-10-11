<template>
  <div data-testid="section-files-header">
    <PageHeader
      :title="$t('nav.files')"
      :subtitle="subtitle"
      icon="heroicons:folder-open"
      tour-id="library"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { isLibraryLinkActive, useLibraryLinks } from '@/composables/useLibraryLinks'

const { t } = useI18n()
const route = useRoute()
const { links } = useLibraryLinks()

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
