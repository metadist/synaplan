<template>
  <div
    class="absolute left-3 right-3 bottom-3 sm:left-auto sm:bottom-12 sm:w-72 z-10 surface-card !rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-2xl overflow-hidden"
    data-testid="pane-smart-search-actions"
  >
    <p class="px-3 pt-3 pb-1 text-xs font-semibold txt-secondary truncate">
      {{ $t('search.palette.actions.title', { title }) }}
    </p>
    <div
      :id="listId"
      role="listbox"
      :aria-label="$t('search.palette.actions.title', { title })"
      class="p-1"
    >
      <div
        v-for="(action, position) in actions"
        :id="optionId(position)"
        :key="action.id"
        role="option"
        :aria-selected="position === index"
        :class="['dropdown-item cursor-pointer', position === index && 'dropdown-item--active']"
        :data-testid="`action-smart-search-${action.id}`"
        @mousedown.prevent
        @click="emit('run', position)"
        @mousemove="emit('hover', position)"
      >
        <component :is="action.icon" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="flex-1 min-w-0 truncate">{{ action.label }}</span>
        <kbd
          v-if="action.shortcut"
          class="hidden sm:inline text-[10px] px-1.5 py-0.5 rounded surface-chip txt-secondary"
          >{{ action.shortcut }}</kbd
        >
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { PaletteAction } from '@/composables/search/usePaletteActions'

defineProps<{
  actions: PaletteAction[]
  index: number
  /** Title of the result the actions belong to. */
  title: string
  listId: string
  optionId: (position: number) => string
}>()

const emit = defineEmits<{
  run: [position: number]
  hover: [position: number]
}>()
</script>
