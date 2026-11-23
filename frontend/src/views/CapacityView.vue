<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-capacity"
    >
      <div class="max-w-[100rem] mx-auto space-y-6">
        <PageHeader
          :title="t('capacity.title')"
          :subtitle="t('capacity.subtitle')"
          icon="mdi:server-network"
        >
          <TabNav
            v-model="tab"
            :tabs="tabs"
            :aria-label="t('capacity.tabs.label')"
            testid="tabs-capacity"
            mobile-trigger-testid="tabs-capacity-mobile-trigger"
            mobile-menu-testid="tabs-capacity-mobile-menu"
          />
        </PageHeader>

        <CapacityServersTab v-if="tab === 'servers'" />
        <CapacityRequestsTab v-else-if="tab === 'requests'" />
        <CapacityAiCallsTab v-else-if="tab === 'ai'" />
        <CapacityToolsTab v-else-if="tab === 'tools'" />
        <CapacityOutboundTab v-else-if="tab === 'outbound'" />

        <p class="px-2 text-xs txt-secondary flex flex-wrap gap-x-3 gap-y-1">
          <span>{{ t('capacity.footer') }}</span>
          <a
            :href="SIZING_GUIDE_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="underline hover:no-underline txt-primary"
            data-testid="link-capacity-sizing-guide"
          >
            {{ t('capacity.sizingGuide') }}
          </a>
        </p>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import CapacityAiCallsTab from '@/components/admin/capacity/CapacityAiCallsTab.vue'
import CapacityOutboundTab from '@/components/admin/capacity/CapacityOutboundTab.vue'
import CapacityRequestsTab from '@/components/admin/capacity/CapacityRequestsTab.vue'
import CapacityServersTab from '@/components/admin/capacity/CapacityServersTab.vue'
import CapacityToolsTab from '@/components/admin/capacity/CapacityToolsTab.vue'

/**
 * Admin › System › Capacity (architecture update AU7 and AU33, journeys
 * J-AU-2 and J-AU-3): every server and how loaded it is, how long requests
 * take and where their time goes, and where Synaplan calls out to. The
 * open tab lives in `?tab=` so a link opens the same view.
 */
const SIZING_GUIDE_URL = 'https://github.com/metadist/synaplan/blob/main/docs/CAPACITY.md'

const TAB_IDS = ['servers', 'requests', 'ai', 'tools', 'outbound'] as const
type CapacityTab = (typeof TAB_IDS)[number]

const TAB_ICONS: Record<CapacityTab, string> = {
  servers: 'heroicons:server-stack',
  requests: 'heroicons:clock',
  ai: 'heroicons:sparkles',
  tools: 'heroicons:wrench-screwdriver',
  outbound: 'heroicons:arrow-top-right-on-square',
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const tab = computed<CapacityTab>({
  get: () => {
    const requested = route.query.tab
    return TAB_IDS.find((id) => id === requested) ?? 'servers'
  },
  set: (next) => {
    const query = { ...route.query }
    if (next === 'servers') delete query.tab
    else query.tab = next
    void router.replace({ query })
  },
})

const tabs = computed<TabNavItem[]>(() =>
  TAB_IDS.map((id) => ({
    id,
    label: t(`capacity.tabs.${id}`),
    icon: TAB_ICONS[id],
    testid: `tab-capacity-${id}`,
  }))
)
</script>
