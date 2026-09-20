<template>
  <div class="flex gap-0.5 shrink-0">
    <button
      v-for="action in actions"
      :key="action.id"
      type="button"
      :class="action.id === 'delete' ? DELETE_BUTTON_CLASS : ACTION_BUTTON_CLASS"
      :title="action.title"
      :aria-label="action.title"
      :data-testid="action.testid"
      :disabled="action.disabled"
      @click="action.onSelect"
    >
      <component :is="ACTION_ICONS[action.id]" class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup lang="ts">
import {
  ArrowDownTrayIcon,
  ChatBubbleLeftRightIcon,
  EyeIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'

/**
 * One file-row action. Glyph, order position, density and delete styling are
 * fixed by the house icon map (docs/FRONTEND_CONVENTIONS.md § Iconography) —
 * callers pass titles, test IDs and handlers only, so the same action can
 * never ship two icons (U9/U12).
 */
export interface FileRowAction {
  id: 'openInChat' | 'preview' | 'download' | 'delete'
  title: string
  testid?: string
  disabled?: boolean
  onSelect: () => void
}

defineProps<{
  actions: FileRowAction[]
}>()

const ACTION_ICONS = {
  openInChat: ChatBubbleLeftRightIcon,
  preview: EyeIcon,
  download: ArrowDownTrayIcon,
  delete: TrashIcon,
} as const

const ACTION_BUTTON_CLASS =
  'p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:txt-primary transition-colors disabled:opacity-50 disabled:cursor-not-allowed'
const DELETE_BUTTON_CLASS =
  'p-1.5 rounded-lg hover:bg-red-500/10 text-red-400/70 hover:text-red-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed'
</script>
