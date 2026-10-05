<template>
  <MainLayout data-testid="view-admin-setup">
    <div class="container mx-auto px-6 py-8 max-w-5xl overflow-x-hidden">
      <PageHeader
        :title="$t('adminSetup.title')"
        :subtitle="$t('adminSetup.description')"
        icon="mdi:robot-outline"
      />

      <TabNav
        v-model="activeTab"
        class="mb-6"
        :tabs="tabs"
        :aria-label="$t('adminSetup.title')"
        testid="admin-setup-tabs"
        mobile-trigger-testid="admin-setup-tabs-mobile-trigger"
        mobile-menu-testid="admin-setup-tabs-mobile-menu"
      />

      <RestartRequiredBanner
        :visible="systemConfig.restartRequired.value"
        @dismiss="systemConfig.dismissRestart"
      />

      <div v-if="needsSettings && systemConfig.loadFailed.value" class="mb-6">
        <div
          class="surface-card rounded-lg p-4 flex flex-wrap items-center justify-between gap-3"
          data-testid="ai-settings-load-error"
        >
          <p class="text-sm txt-secondary">{{ $t('adminSetup.settingsLoadFailed') }}</p>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
            @click="systemConfig.load"
          >
            {{ $t('common.retry') }}
          </button>
        </div>
      </div>

      <ModelsAndKeysTab
        v-if="activeTab === 'providers'"
        :config="systemConfig"
        :settings="AI_TAB_SECTIONS.providers"
        :focus-section="focusSection"
      />

      <ModelHealthPanel v-else-if="activeTab === 'health'" />

      <div v-else-if="activeTab === 'documents'" class="space-y-8" data-testid="ai-tab-documents">
        <ExtractionPlugTab />
        <section class="space-y-3" aria-labelledby="ai-documents-services">
          <div>
            <h2 id="ai-documents-services" class="text-lg font-semibold txt-primary">
              {{ $t('aiInfra.extraction.servicesTitle') }}
            </h2>
            <p class="text-sm txt-secondary mt-1">{{ $t('aiInfra.extraction.servicesHint') }}</p>
          </div>
          <ConfigSectionStack
            :config="systemConfig"
            :sections="AI_TAB_SECTIONS.documents"
            testid="ai-documents-accordion"
          />
        </section>
      </div>

      <div v-else-if="activeTab === 'search'" class="space-y-8" data-testid="ai-tab-search">
        <section class="space-y-3">
          <p class="text-sm txt-secondary">{{ $t('adminSetup.intro.search') }}</p>
          <ConfigSectionStack
            :config="systemConfig"
            :sections="AI_TAB_SECTIONS.search"
            testid="ai-search-accordion"
          />
        </section>
        <SmartSearchModelsCard />
        <section class="space-y-3" aria-labelledby="ai-search-rerank">
          <h2 id="ai-search-rerank" class="text-lg font-semibold txt-primary">
            {{ $t('aiInfra.rerank.title') }}
          </h2>
          <RerankPlugTab />
        </section>
      </div>

      <div v-else-if="activeTab === 'behavior'" class="space-y-3" data-testid="ai-tab-behavior">
        <p class="text-sm txt-secondary">{{ $t('adminSetup.intro.behavior') }}</p>
        <ConfigSectionStack
          :config="systemConfig"
          :sections="AI_TAB_SECTIONS.behavior"
          testid="ai-behavior-accordion"
        />
      </div>

      <!-- Stays mounted after the first visit so an unsaved prompt draft survives a tab switch. -->
      <div
        v-if="promptsTabMounted"
        v-show="activeTab === 'prompts'"
        class="space-y-3"
        data-testid="ai-tab-prompts"
      >
        <p class="text-sm txt-secondary">{{ $t('adminSetup.intro.prompts') }}</p>
        <AdminPromptsPanel />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import ConfigSectionStack from '@/components/admin/ConfigSectionStack.vue'
import ModelHealthPanel from '@/components/admin/ModelHealthPanel.vue'
import RestartRequiredBanner from '@/components/admin/RestartRequiredBanner.vue'
import ExtractionPlugTab from '@/components/admin/plugs/ExtractionPlugTab.vue'
import ModelsAndKeysTab from '@/components/admin/plugs/ModelsAndKeysTab.vue'
import RerankPlugTab from '@/components/admin/plugs/RerankPlugTab.vue'
import SmartSearchModelsCard from '@/components/admin/search/SmartSearchModelsCard.vue'
import { modelsNeedingAttention } from '@/composables/useNavItems'
import { useSystemConfig } from '@/composables/useSystemConfig'
import { AI_TAB_SECTIONS, isAiTabId, type AiTabId } from '@/constants/operateSettings'
import { aiInfrastructureRedirect } from '@/router/operateRedirects'

const AdminPromptsPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminPromptsPanel.vue')
)

const DEFAULT_TAB: AiTabId = 'providers'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const systemConfig = useSystemConfig()

const tabFromQuery = (): AiTabId => {
  const tab = route.query.tab
  return isAiTabId(tab) ? tab : DEFAULT_TAB
}
const activeTab = ref<AiTabId>(tabFromQuery())
const promptsTabMounted = ref(activeTab.value === 'prompts')

/** Tabs that render backend settings next to their own controls. */
const needsSettings = computed(() => AI_TAB_SECTIONS[activeTab.value].length > 0)
const focusSection = computed(() =>
  typeof route.query.section === 'string' ? route.query.section : undefined
)

const tabs = computed<TabNavItem[]>(() => [
  {
    id: 'providers',
    label: t('adminSetup.tabs.providers'),
    icon: 'mdi:key-outline',
    testid: 'admin-setup-tab-providers',
  },
  {
    id: 'health',
    label: t('adminSetup.tabs.health'),
    icon: 'mdi:heart-pulse',
    testid: 'admin-setup-tab-health',
    badge: modelsNeedingAttention.value,
  },
  {
    id: 'documents',
    label: t('adminSetup.tabs.documents'),
    icon: 'mdi:file-document-outline',
    testid: 'admin-setup-tab-documents',
  },
  {
    id: 'search',
    label: t('adminSetup.tabs.search'),
    icon: 'mdi:database-search',
    testid: 'admin-setup-tab-search',
  },
  {
    id: 'behavior',
    label: t('adminSetup.tabs.behavior'),
    icon: 'mdi:tune-variant',
    testid: 'admin-setup-tab-behavior',
  },
  {
    id: 'prompts',
    label: t('adminSetup.tabs.prompts'),
    icon: 'mdi:text-box-multiple',
    testid: 'admin-setup-tab-prompts',
  },
])

watch(
  activeTab,
  (id) => {
    if (id === 'prompts') promptsTabMounted.value = true
    if (AI_TAB_SECTIONS[id].length > 0) void systemConfig.ensureLoaded()

    const tab = route.query.tab
    if (tab === id || (id === DEFAULT_TAB && (tab === undefined || tab === ''))) return
    const query = { ...route.query }
    delete query.section
    if (id === DEFAULT_TAB) {
      delete query.tab
    } else {
      query.tab = id
    }
    void router.replace({ query })
  },
  { immediate: true }
)

watch(
  () => route.query.tab,
  () => {
    const redirect = aiInfrastructureRedirect(route)
    if (redirect !== true) {
      void router.replace(redirect)
      return
    }
    activeTab.value = tabFromQuery()
  }
)
</script>
