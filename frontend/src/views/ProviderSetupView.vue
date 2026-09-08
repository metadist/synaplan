<template>
  <MainLayout data-testid="view-admin-setup">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <PageHeader
        :title="$t('adminSetup.title')"
        :subtitle="$t('adminSetup.description')"
        icon="mdi:rocket-launch-outline"
      />

      <TabNav
        v-model="activeTab"
        class="mb-6"
        :tabs="tabs"
        :aria-label="$t('adminSetup.title')"
        testid="admin-setup-tabs"
      />

      <ModelsAndKeysTab v-if="activeTab === 'models'" />
      <ExtractionPlugTab v-else-if="activeTab === 'extraction'" />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import ExtractionPlugTab from '@/components/admin/plugs/ExtractionPlugTab.vue'
import ModelsAndKeysTab from '@/components/admin/plugs/ModelsAndKeysTab.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const tabFromQuery = (): string => (route.query.tab === 'extraction' ? 'extraction' : 'models')
const activeTab = ref(tabFromQuery())

const tabs = computed<TabNavItem[]>(() => [
  {
    id: 'models',
    label: t('adminSetup.tabs.models'),
    icon: 'mdi:key-outline',
    testid: 'admin-setup-tab-models',
  },
  {
    id: 'extraction',
    label: t('adminSetup.tabs.extraction'),
    icon: 'mdi:file-document-outline',
    testid: 'admin-setup-tab-extraction',
  },
])

watch(activeTab, (id) => {
  if (route.query.tab === id || (id === 'models' && !route.query.tab)) return
  void router.replace({ query: id === 'models' ? {} : { tab: id } })
})

watch(
  () => route.query.tab,
  () => {
    activeTab.value = tabFromQuery()
  }
)
</script>
