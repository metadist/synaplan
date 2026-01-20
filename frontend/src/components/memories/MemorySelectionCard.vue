<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { UserMemory } from '@/services/api/userMemoriesApi'

const props = defineProps<{
  memory: UserMemory
  categoryColor?: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'edit', memory: UserMemory): void
  (e: 'delete', memory: UserMemory): void
}>()

const { t } = useI18n()
</script>

<template>
  <div class="surface-card p-4 md:p-6 rounded-2xl">
    <div class="flex items-start justify-between gap-3 mb-3 md:mb-4">
      <div class="flex-1 min-w-0">
        <div class="text-xs font-semibold uppercase tracking-wider mb-1 flex items-center gap-2">
          <span
            class="inline-block w-2 h-2 rounded-full flex-shrink-0"
            :style="{ backgroundColor: props.categoryColor || 'var(--brand)' }"
          ></span>
          <span class="txt-brand truncate">
            {{ $t(`memories.categories.${props.memory.category}`, props.memory.category) }}
          </span>
        </div>
        <h3 class="text-base md:text-lg font-bold txt-primary truncate">
          {{ props.memory.key }}
        </h3>
      </div>

      <button
        class="icon-ghost flex-shrink-0"
        :aria-label="t('common.close')"
        @click="emit('close')"
      >
        <Icon icon="mdi:close" class="w-5 h-5" />
      </button>
    </div>

    <p class="txt-secondary text-xs md:text-sm mb-3 md:mb-4 whitespace-pre-wrap">
      {{ props.memory.value }}
    </p>

    <div class="flex items-center justify-between text-xs txt-tertiary mb-3 md:mb-4">
      <span>{{ $t(`memories.source.${props.memory.source}`) }}</span>
      <span class="truncate ml-2">{{
        new Date(props.memory.updated * 1000).toLocaleString()
      }}</span>
    </div>

    <div class="flex gap-2">
      <button class="btn-primary flex-1 py-2 text-sm" @click="emit('edit', props.memory)">
        <Icon icon="mdi:pencil" class="w-4 h-4 inline mr-1" />
        <span>{{ $t('common.edit') }}</span>
      </button>
      <button class="btn-secondary flex-1 py-2 text-sm" @click="emit('delete', props.memory)">
        <Icon icon="mdi:delete" class="w-4 h-4 inline mr-1" />
        <span>{{ $t('common.delete') }}</span>
      </button>
    </div>
  </div>
</template>
