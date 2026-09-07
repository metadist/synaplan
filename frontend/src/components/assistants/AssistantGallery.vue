<template>
  <div data-testid="section-assistant-gallery">
    <div class="flex flex-wrap items-center gap-2 mb-4">
      <button
        v-for="chip in chips"
        :key="chip.id"
        type="button"
        class="px-3 py-1.5 rounded-full text-sm font-medium"
        :class="filter === chip.id ? 'btn-primary' : 'btn-secondary'"
        :data-testid="`chip-gallery-${chip.id}`"
        @click="filter = chip.id"
      >
        {{ chip.label }}
      </button>
      <input
        v-model="search"
        type="search"
        class="ml-auto min-w-[12rem] flex-1 max-w-xs"
        :placeholder="$t('assistants.searchPlaceholder')"
        data-testid="input-gallery-search"
      />
    </div>

    <div v-if="store.loading" class="surface-card rounded-lg p-12 text-center">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div
      v-else-if="visibleCards.length === 0 && filter === 'mine'"
      class="surface-card rounded-lg p-12 text-center"
      data-testid="state-gallery-empty"
    >
      <p class="txt-secondary mb-4">{{ $t('assistants.galleryEmpty') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-create-assistant"
        @click="$emit('create')"
      >
        {{ $t('assistants.create') }}
      </button>
    </div>

    <div
      v-else-if="visibleCards.length === 0 && filter === 'shared'"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="state-gallery-shared-empty"
    >
      {{ $t('assistants.sharedEmpty') }}
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
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import AssistantCard from './AssistantCard.vue'
import { useAgentsStore } from '@/stores/agents'

defineEmits<{
  create: []
  'start-chat': [id: number]
  clone: [id: number]
  edit: [id: number]
}>()

const { t } = useI18n()
const store = useAgentsStore()
const filter = ref<'mine' | 'shared' | 'plugin'>('mine')
const search = ref('')

const chips = computed(() => [
  { id: 'mine' as const, label: t('assistants.filterMine') },
  { id: 'shared' as const, label: t('assistants.filterShared') },
  { id: 'plugin' as const, label: t('assistants.filterPlugins') },
])

const visibleCards = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.gallery.filter((card) => {
    if ((card.origin ?? 'mine') !== filter.value) {
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
