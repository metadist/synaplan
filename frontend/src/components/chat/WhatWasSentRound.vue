<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import {
  ChatBubbleLeftEllipsisIcon,
  ChatBubbleLeftRightIcon,
  ClockIcon,
  DocumentTextIcon,
} from '@heroicons/vue/24/outline'
import type {
  ProviderTranscriptRound,
  ProviderTranscriptTurn,
} from '@/utils/providerTranscriptView'
import WhatWasSentMarkdown from '@/components/chat/WhatWasSentMarkdown.vue'

const props = defineProps<{
  round: ProviderTranscriptRound
  idPrefix: string
  /** Test ids for the request that produced this reply. Earlier steps omit them. */
  primary?: boolean
}>()

const { t } = useI18n()

function sectionId(part: string): string {
  return `${props.idPrefix}-${part}`
}

function headingId(part: string): string {
  return `${props.idPrefix}-${part}-label`
}

function testId(part: string): string | undefined {
  return props.primary ? `section-what-was-sent-${part}` : undefined
}

function turnLabel(role: ProviderTranscriptTurn['role']): string {
  return role === 'you' ? t('chatMessage.whatWasSentYou') : t('chatMessage.whatWasSentAssistant')
}
</script>

<template>
  <div class="space-y-4">
    <section
      v-if="round.question"
      :id="sectionId('question')"
      class="scroll-mt-3 rounded-xl border border-[var(--brand)]/30 bg-[var(--brand)]/10 px-4 py-3.5"
      :aria-labelledby="headingId('question')"
      :data-testid="testId('question')"
    >
      <div class="mb-2 flex items-center gap-2.5">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--brand)]/10 text-[var(--brand)]"
          aria-hidden="true"
        >
          <ChatBubbleLeftRightIcon class="h-4 w-4" />
        </span>
        <h3 :id="headingId('question')" class="text-sm font-semibold txt-primary">
          {{ t('chatMessage.whatWasSentQuestion') }}
        </h3>
      </div>
      <WhatWasSentMarkdown
        :text="round.question"
        prominent
        :data-testid="primary ? 'block-what-was-sent' : undefined"
      />
    </section>

    <section
      v-if="round.history.length > 0"
      :id="sectionId('history')"
      class="scroll-mt-3"
      :aria-labelledby="headingId('history')"
      :data-testid="testId('history')"
    >
      <div class="mb-2 flex items-center gap-2.5">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--brand-alpha-light)] text-[var(--brand)]"
          aria-hidden="true"
        >
          <ClockIcon class="h-4 w-4" />
        </span>
        <h3 :id="headingId('history')" class="text-sm font-semibold txt-primary">
          {{ t('chatMessage.whatWasSentHistory') }}
        </h3>
      </div>
      <ol class="space-y-2">
        <li
          v-for="(turn, index) in round.history"
          :key="`${idPrefix}-history-${index}`"
          class="rounded-xl border border-light-border/30 px-3.5 py-3 dark:border-dark-border/20"
          :data-testid="primary ? 'block-what-was-sent' : undefined"
        >
          <p class="mb-1.5 text-xs font-medium txt-secondary">{{ turnLabel(turn.role) }}</p>
          <WhatWasSentMarkdown :text="turn.text" />
        </li>
      </ol>
    </section>

    <section
      v-if="round.after.length > 0"
      :id="sectionId('after')"
      class="scroll-mt-3"
      :aria-labelledby="headingId('after')"
      :data-testid="testId('after')"
    >
      <div class="mb-2 flex items-center gap-2.5">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--brand-alpha-light)] text-[var(--brand)]"
          aria-hidden="true"
        >
          <ChatBubbleLeftEllipsisIcon class="h-4 w-4" />
        </span>
        <h3 :id="headingId('after')" class="text-sm font-semibold txt-primary">
          {{ t('chatMessage.whatWasSentAfter') }}
        </h3>
      </div>
      <ol class="space-y-2">
        <li
          v-for="(turn, index) in round.after"
          :key="`${idPrefix}-after-${index}`"
          class="rounded-xl border border-light-border/30 px-3.5 py-3 dark:border-dark-border/20"
          :data-testid="primary ? 'block-what-was-sent' : undefined"
        >
          <p class="mb-1.5 text-xs font-medium txt-secondary">{{ turnLabel(turn.role) }}</p>
          <WhatWasSentMarkdown :text="turn.text" />
        </li>
      </ol>
    </section>

    <section
      v-if="round.instructions.length > 0"
      :id="sectionId('instructions')"
      class="scroll-mt-3 rounded-xl border border-light-border/30 bg-chat px-4 py-3.5 dark:border-dark-border/20"
      :aria-labelledby="headingId('instructions')"
      :data-testid="testId('instructions')"
    >
      <div class="mb-2 flex items-center gap-2.5">
        <span
          class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--brand)]/10 text-[var(--brand)]"
          aria-hidden="true"
        >
          <DocumentTextIcon class="h-4 w-4" />
        </span>
        <h3 :id="headingId('instructions')" class="text-sm font-semibold txt-primary">
          {{ t('chatMessage.whatWasSentInstructions') }}
        </h3>
      </div>
      <div
        v-for="(text, index) in round.instructions"
        :key="`${idPrefix}-instructions-${index}`"
        :class="
          index > 0
            ? 'mt-4 border-t border-light-border/30 pt-4 dark:border-dark-border/20'
            : undefined
        "
        :data-testid="primary ? 'block-what-was-sent' : undefined"
      >
        <WhatWasSentMarkdown :text="text" />
      </div>
    </section>
  </div>
</template>
