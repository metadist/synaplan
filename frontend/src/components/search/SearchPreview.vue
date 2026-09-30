<template>
  <aside
    class="hidden lg:flex w-72 flex-shrink-0 flex-col gap-3 p-4 border-l border-light-border/30 dark:border-dark-border/20 overflow-y-auto"
    :aria-label="$t('search.palette.preview.label')"
    data-testid="preview-smart-search"
  >
    <div class="flex items-start gap-3">
      <span
        class="flex-shrink-0 w-10 h-10 rounded-lg surface-chip flex items-center justify-center"
        aria-hidden="true"
      >
        <component :is="result.icon" class="w-5 h-5" />
      </span>
      <div class="min-w-0">
        <p
          class="font-medium txt-primary break-words"
          data-testid="text-smart-search-preview-title"
        >
          {{ result.title }}
        </p>
        <p class="text-xs txt-secondary">{{ $t(`search.palette.kind.${result.kind}`) }}</p>
      </div>
    </div>

    <dl class="space-y-2 text-sm">
      <div v-if="result.subtitle">
        <dt class="text-xs txt-secondary">{{ $t('search.palette.preview.where') }}</dt>
        <dd class="txt-primary break-words">{{ result.subtitle }}</dd>
      </div>
      <div v-if="settingValue !== null">
        <dt class="text-xs txt-secondary">{{ $t('search.palette.preview.current') }}</dt>
        <dd class="txt-primary" data-testid="text-smart-search-preview-value">
          {{ settingValue }}
          <span v-if="result.setting?.envPinned" class="txt-secondary">
            · {{ $t('search.palette.setting.pinned') }}
          </span>
        </dd>
      </div>
    </dl>

    <p v-if="result.snippet" class="text-sm txt-secondary break-words">{{ result.snippet }}</p>
    <p
      v-if="result.matchedBy === 'semantic'"
      class="inline-flex items-center gap-1 text-xs txt-secondary"
    >
      <SparklesIcon class="w-3.5 h-3.5" aria-hidden="true" />
      {{ $t('search.palette.preview.byMeaning') }}
    </p>

    <div class="mt-auto flex flex-col gap-2 pt-2">
      <button
        v-for="(action, position) in actions"
        :key="action.id"
        type="button"
        :class="[
          position === 0 ? 'btn-primary' : 'btn-secondary',
          'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium',
        ]"
        :data-testid="`btn-smart-search-preview-${action.id}`"
        @mousedown.prevent
        @click="emit('run', position)"
      >
        <component :is="action.icon" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="truncate">{{ action.label }}</span>
      </button>
      <p class="text-[11px] txt-secondary">{{ $t('search.palette.preview.tabHint') }}</p>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { SparklesIcon } from '@heroicons/vue/24/outline'
import type { SearchResult } from '@/composables/search/types'
import type { PaletteAction } from '@/composables/search/usePaletteActions'

defineProps<{
  result: SearchResult
  actions: PaletteAction[]
  /** Value in force for a setting result, already as a label; null otherwise. */
  settingValue: string | null
}>()

const emit = defineEmits<{
  run: [position: number]
}>()
</script>
