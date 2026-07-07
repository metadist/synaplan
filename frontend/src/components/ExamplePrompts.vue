<template>
  <div class="w-full max-w-2xl mx-auto" data-testid="comp-example-prompts">
    <p class="text-center text-xs font-medium txt-muted uppercase tracking-wide mb-3">
      {{ $t('examplePrompts.heading') }}
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <button
        v-for="example in examples"
        :key="example.id"
        type="button"
        class="surface-card hover-surface p-4 rounded-xl text-left flex flex-col gap-2 transition-all"
        :data-testid="`btn-example-prompt-${example.id}`"
        @click="emit('pick', $t(`examplePrompts.items.${example.id}`))"
      >
        <span
          class="w-8 h-8 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center flex-shrink-0"
        >
          <Icon :icon="example.icon" class="w-5 h-5 text-[var(--brand)]" />
        </span>
        <span class="text-sm txt-secondary leading-snug">
          {{ $t(`examplePrompts.items.${example.id}`) }}
        </span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

const emit = defineEmits<{
  pick: [prompt: string]
}>()

const examples = [
  { id: 'poemMp3', icon: 'mdi:music-note' },
  { id: 'everglades', icon: 'mdi:file-word-box' },
  { id: 'cabinImage', icon: 'mdi:image' },
] as const
</script>
