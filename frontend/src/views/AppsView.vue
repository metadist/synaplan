<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-apps"
    >
      <div class="max-w-[100rem] mx-auto">
        <PageHeader
          :title="$t('apps.title')"
          :subtitle="$t('apps.subtitle')"
          icon="heroicons:squares-2x2"
          tour-id="apps"
        >
          <template #actions>
            <label class="relative w-full sm:w-64">
              <span class="sr-only">{{ $t('apps.searchLabel') }}</span>
              <MagnifyingGlassIcon
                class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 txt-secondary"
                aria-hidden="true"
              />
              <input
                v-model="query"
                type="search"
                class="w-full pl-9 pr-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('apps.searchPlaceholder')"
                data-testid="input-apps-search"
                data-tour="apps-search"
              />
            </label>
          </template>
          <TabNav
            :tabs="tabs"
            :model-value="connectedOnly ? 'connected' : 'all'"
            :aria-label="$t('apps.title')"
            testid="tabs-apps"
            data-tour="apps-tabs"
          />
        </PageHeader>

        <div v-if="loading" class="txt-secondary text-sm" data-testid="apps-loading">
          {{ $t('common.loading') }}
        </div>

        <EmptyState
          v-else-if="connectedOnly && connectedCount === 0 && !query"
          :icon="LinkIcon"
          :title="$t('apps.connectedEmpty')"
          :action-label="$t('apps.browseAll')"
          to="/apps"
          test-id="apps-connected-empty"
        />

        <EmptyState
          v-else-if="sections.length === 0"
          :icon="MagnifyingGlassIcon"
          :title="$t('apps.noResults', { query })"
          :action-label="$t('apps.clearSearch')"
          test-id="apps-no-results"
          @action="query = ''"
        />

        <div v-else class="space-y-8" data-tour="apps-list">
          <section
            v-for="section in sections"
            :key="section.id"
            :data-testid="`section-apps-${section.id}`"
          >
            <h2 class="text-sm font-semibold txt-secondary mb-3">
              {{ $t(`apps.categories.${section.id}`) }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              <AppCard
                v-for="card in section.cards"
                :key="card.id"
                :to="card.to"
                :name="card.name"
                :tagline="card.tagline"
                :icon="card.icon"
                :connected="card.connected"
                :test-id="`card-app-${card.id}`"
              />
            </div>
          </section>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { LinkIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import AppCard from '@/components/apps/AppCard.vue'
import { APP_CATEGORIES, appMessageKey, availableApps, type AppDefinition } from '@/apps/catalog'
import { loadConnectedAppIds } from '@/apps/status'
import { useConfigStore } from '@/stores/config'

interface Card {
  id: string
  to: string
  name: string
  tagline: string
  icon: string
  connected: boolean
}

const { t } = useI18n()
const route = useRoute()
const configStore = useConfigStore()

const apps = ref<AppDefinition[]>([])
const connectedIds = ref<Set<string>>(new Set())
const loading = ref(true)
const query = ref('')

const connectedOnly = computed(() => route.path === '/apps/connected')

const tabs = computed<TabNavItem[]>(() => [
  { id: 'all', label: t('apps.tabAll'), to: '/apps', testid: 'tab-apps-all' },
  {
    id: 'connected',
    label: t('apps.tabConnected'),
    to: '/apps/connected',
    testid: 'tab-apps-connected',
    badge: connectedCount.value,
  },
])

const pluginCards = computed<Card[]>(() =>
  (configStore.plugins as { name?: string }[])
    .filter((plugin): plugin is { name: string } => !!plugin.name)
    .map((plugin) => ({
      id: `plugin-${plugin.name}`,
      to: `/plugins/${plugin.name}`,
      name: plugin.name.charAt(0).toUpperCase() + plugin.name.slice(1),
      tagline: t('apps.pluginTagline'),
      icon: 'heroicons:puzzle-piece',
      // A plugin installed for the user is in use by definition.
      connected: true,
    }))
)

const connectedCount = computed(() => connectedIds.value.size + pluginCards.value.length)

function toCard(app: AppDefinition): Card {
  const key = appMessageKey(app.id)
  return {
    id: app.id,
    to: app.to ?? `/apps/${app.id}`,
    name: t(`apps.items.${key}.name`),
    tagline: t(`apps.items.${key}.tagline`),
    icon: app.icon,
    connected: connectedIds.value.has(app.id),
  }
}

const sections = computed(() => {
  const needle = query.value.trim().toLowerCase()
  const keep = (card: Card) =>
    (!connectedOnly.value || card.connected) &&
    (needle === '' || `${card.name} ${card.tagline}`.toLowerCase().includes(needle))

  const result = APP_CATEGORIES.map((category) => ({
    id: category as string,
    cards: apps.value
      .filter((app) => app.category === category)
      .map(toCard)
      .filter(keep),
  }))
  result.push({ id: 'extensions', cards: pluginCards.value.filter(keep) })
  return result.filter((section) => section.cards.length > 0)
})

onMounted(async () => {
  try {
    apps.value = await availableApps()
    connectedIds.value = await loadConnectedAppIds(apps.value)
  } finally {
    loading.value = false
  }
})
</script>
