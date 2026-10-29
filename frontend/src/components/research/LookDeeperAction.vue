<template>
  <div v-if="offered" class="flex flex-col items-start gap-1" data-testid="section-look-deeper">
    <button
      type="button"
      class="pill text-xs whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="busy"
      :aria-label="$t('research.lookDeeper')"
      data-testid="btn-look-deeper"
      @click="emit('look-deeper')"
    >
      <MagnifyingGlassPlusIcon class="w-4 h-4" aria-hidden="true" />
      <span class="font-medium hidden sm:inline">{{ $t('research.lookDeeper') }}</span>
    </button>
    <p
      v-if="research.startError && research.startError !== 'stopFailed'"
      class="max-w-[14rem] text-xs text-red-600 dark:text-red-400"
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
 * In the message footer of a direct answer the chat chose to keep short:
 * one click starts the research it skipped. Absent when automatic research
 * is off (U11), busy while a run in this chat is still going.
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
</script>
