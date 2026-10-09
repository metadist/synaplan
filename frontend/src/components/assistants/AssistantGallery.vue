<template>
  <div data-testid="section-assistant-gallery">
    <div class="flex flex-wrap items-center gap-2 mb-4">
      <button
        v-for="chip in visibleChips"
        :key="chip.id"
        type="button"
        class="px-3 py-1.5 rounded-full text-sm font-medium inline-flex items-center gap-1.5"
        :class="filter === chip.id ? 'btn-primary' : 'btn-secondary'"
        :title="chip.hint"
        :aria-pressed="filter === chip.id"
        :data-testid="`chip-gallery-${chip.id}`"
        @click="filter = chip.id"
      >
        {{ chip.label }}
        <span class="text-xs opacity-80" :data-testid="`count-gallery-${chip.id}`">{{
          chip.count
        }}</span>
      </button>
      <input
        v-model="search"
        type="search"
        class="ml-auto min-w-[12rem] flex-1 max-w-xs px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('assistants.searchPlaceholder')"
        data-testid="input-gallery-search"
      />
    </div>

    <div v-if="store.loading" class="surface-card rounded-lg p-12 text-center">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <EmptyState
      v-else-if="visibleCards.length === 0 && filter === 'mine' && !search.trim()"
      :title="$t('assistants.galleryEmpty')"
      :action-label="$t('assistants.create')"
      action-test-id="btn-create-assistant"
      test-id="state-gallery-empty"
      @action="$emit('create')"
    />

    <div
      v-else-if="visibleCards.length === 0 && search.trim()"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="state-gallery-no-results"
    >
      {{ $t('assistants.noResults', { query: search.trim() }) }}
    </div>

    <div
      v-else-if="visibleCards.length === 0 && filter === 'shared'"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="state-gallery-shared-empty"
    >
      {{ $t('assistants.sharedEmpty') }}
    </div>

    <div
      v-else-if="visibleCards.length === 0 && filter === 'archived'"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="state-gallery-archived-empty"
    >
      {{ $t('assistants.archivedEmpty') }}
    </div>

    <div
      v-else-if="visibleCards.length === 0"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="state-gallery-plugins-empty"
    >
      {{ $t('assistants.pluginsEmpty') }}
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2" data-testid="list-assistant-cards">
      <AssistantCard
        v-for="card in visibleCards"
        :key="card.id ?? card.slug"
        :card="card"
        @start-chat="$emit('start-chat', $event)"
        @clone="$emit('clone', $event)"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import AssistantCard from './AssistantCard.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { useAgentsStore } from '@/stores/agents'

defineEmits<{
  create: []
  'start-chat': [id: number]
  clone: [id: number]
  edit: [id: number]
  delete: [id: number]
}>()

const { t } = useI18n()
const store = useAgentsStore()
const CHIP_LABEL = {
  mine: 'Mine',
  shared: 'Shared',
  plugin: 'Plugins',
  archived: 'Archived',
} as const
const filter = ref<'mine' | 'shared' | 'plugin' | 'archived'>('mine')
const search = ref('')

type GalleryFilter = 'mine' | 'shared' | 'plugin' | 'archived'
type GalleryCard = (typeof store.gallery)[number]

function inFilter(card: GalleryCard, id: GalleryFilter): boolean {
  const origin = card.origin ?? 'mine'
  if (id === 'archived') return card.status === 'archived' && origin === 'mine'
  return card.status !== 'archived' && origin === id
}

const chips = computed(() =>
  (['mine', 'shared', 'plugin', 'archived'] as const).map((id) => ({
    id,
    label: t(`assistants.filter${CHIP_LABEL[id]}`),
    hint: t(`assistants.filterHint.${id}`),
    count: store.gallery.filter((card) => inFilter(card, id)).length,
  }))
)

/** Empty chips only add noise; Mine stays as the place to start, the active chip stays so it can be left. */
const visibleChips = computed(() =>
  chips.value.filter((chip) => chip.id === 'mine' || chip.id === filter.value || chip.count > 0)
)

const visibleCards = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.gallery.filter((card) => {
    if (!inFilter(card, filter.value)) {
      return false
    }
    if (!q) {
      return true
    }
    const name = (card.name ?? '').toLowerCase()
    const description = (card.description ?? '').toLowerCase()
    return name.includes(q) || description.includes(q)
  })
})
</script>
