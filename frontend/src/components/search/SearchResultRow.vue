<template>
  <div
    :id="optionId"
    role="option"
    :aria-selected="active"
    :class="['dropdown-item cursor-pointer', active && 'dropdown-item--active']"
    :data-testid="`row-smart-search-${result.kind}`"
    :data-result-id="result.id"
    @mousedown.prevent
    @click="emit('select', $event)"
    @mousemove="emit('hover')"
  >
    <span
      class="relative flex-shrink-0 w-8 h-8 rounded-lg surface-chip flex items-center justify-center"
      :title="adminOnly ? $t('search.palette.adminOnlyHint') : undefined"
      aria-hidden="true"
    >
      <component :is="result.icon" class="w-4 h-4" />
      <span
        v-if="adminOnly"
        class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-[var(--status-error)] ring-2 ring-[var(--bg-card)]"
        data-testid="dot-smart-search-admin"
      />
    </span>
    <span class="flex-1 min-w-0">
      <span class="flex items-center gap-2 min-w-0">
        <span class="truncate font-medium">{{ result.title }}</span>
        <span v-if="adminOnly" class="sr-only">{{ $t('search.palette.adminOnly') }}</span>
        <span
          v-if="result.matchedBy === 'semantic'"
          class="flex-shrink-0 inline-flex items-center gap-1 text-[10px] txt-secondary"
          :title="$t('search.palette.foundByMeaning')"
          data-testid="badge-smart-search-semantic"
        >
          <SparklesIcon class="w-3 h-3" aria-hidden="true" />
          <span class="hidden sm:inline">{{ $t('search.palette.foundByMeaning') }}</span>
        </span>
      </span>
      <span v-if="result.subtitle || result.snippet" class="block text-xs txt-secondary truncate">
        <template v-if="result.subtitle">{{ result.subtitle }}</template>
        <template v-if="result.subtitle && result.snippet"> · </template>
        <template v-if="result.snippet">{{ result.snippet }}</template>
      </span>
    </span>
    <slot name="trailing">
      <span
        class="hidden sm:inline flex-shrink-0 text-[10px] px-1.5 py-0.5 rounded surface-chip txt-secondary"
      >
        {{ $t(`search.palette.kind.${result.kind}`) }}
      </span>
    </slot>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import type { SearchResult } from '@/composables/search/types'
import { isAdminOnlyResult } from '@/composables/search/adminOnly'

const props = defineProps<{
  result: SearchResult
  active: boolean
  optionId: string
}>()

const adminOnly = computed(() => isAdminOnlyResult(props.result))

const emit = defineEmits<{
  select: [event: MouseEvent]
  hover: []
}>()
</script>
