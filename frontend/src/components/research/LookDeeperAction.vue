<template>
  <div v-if="offered" class="px-4 pb-3 flex flex-col gap-1" data-testid="section-look-deeper">
    <div>
      <button
        type="button"
        class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="busy"
        data-testid="btn-look-deeper"
        @click="emit('look-deeper')"
      >
        <MagnifyingGlassPlusIcon class="w-4 h-4" aria-hidden="true" />
        {{ $t('research.lookDeeper') }}
      </button>
    </div>
    <p class="text-sm txt-secondary">{{ $t('research.lookDeeperHint', { minutes }) }}</p>
    <p
      v-if="research.startError && research.startError !== 'stopFailed'"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="text-look-deeper-error"
    >
      {{ $t(`research.${research.startError}`) }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { MagnifyingGlassPlusIcon } from '@heroicons/vue/24/outline'
import { useResearchStore } from '@/stores/research'
import { isTerminalStatus } from '@/services/chat/stream/handlers/research'

/**
 * Under a direct answer the chat chose to keep short: one click starts the
 * research it skipped. Absent when automatic research is off (U11), busy
 * while a run in this chat is still going.
 */
const props = defineProps<{
  /** `lookup` answers and answers that began with a limit sentence offer it. */
  effort?: string | null
  effortNotice?: string | null
}>()

const emit = defineEmits<{ 'look-deeper': [] }>()

const research = useResearchStore()
const { capabilities, progress, starting } = storeToRefs(research)

const offered = computed(
  () =>
    capabilities.value?.automatic === true &&
    (props.effort === 'lookup' || (props.effortNotice ?? null) !== null)
)
const busy = computed(
  () => starting.value || (progress.value !== null && !isTerminalStatus(progress.value.status))
)
const minutes = computed(() => capabilities.value?.auto_limits.minutes ?? 6)
</script>
