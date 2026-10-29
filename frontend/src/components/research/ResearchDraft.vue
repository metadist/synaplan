<template>
  <section
    class="flex flex-col gap-1.5 pt-2 border-t border-light-border/30 dark:border-dark-border/20 processing-enter"
    data-testid="research-draft"
    :data-checking="checking ? 'true' : undefined"
    aria-live="polite"
  >
    <p class="text-xs txt-tertiary flex items-center gap-1.5">
      <PencilIcon class="w-3.5 h-3.5 flex-shrink-0" aria-hidden="true" />
      <span data-testid="text-research-draft-note">{{
        checking ? t('research.turn.draftChecking') : t('research.turn.draftWriting')
      }}</span>
    </p>
    <div class="txt-secondary" data-testid="text-research-draft">
      <MessageText :content="text" :is-streaming="!checking" readonly />
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { PencilIcon } from '@heroicons/vue/24/outline'
import MessageText from '@/components/MessageText.vue'

/**
 * The answer as the writer writes it, word by word, under the phases. It
 * is a draft: the fact-check that follows may soften or remove a
 * statement, and the line above the text says so. When the run is done
 * the checked report takes the bubble and the draft goes away.
 */
defineProps<{
  text: string
  /** The writer is done and the fact-check is going over the text. */
  checking: boolean
}>()

const { t } = useI18n()
</script>
