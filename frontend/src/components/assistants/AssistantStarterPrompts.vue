<template>
  <div
    v-if="prompts.length > 0"
    class="w-full max-w-2xl mx-auto"
    data-testid="comp-assistant-starters"
  >
    <p class="text-center text-xs font-medium txt-muted uppercase tracking-wide mb-3">
      {{ $t('assistants.suggested') }}
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <button
        v-for="(prompt, index) in prompts"
        :key="`${index}-${prompt}`"
        type="button"
        class="surface-card hover-surface p-4 rounded-xl text-left text-sm txt-secondary leading-snug transition-all"
        :data-testid="`btn-assistant-starter-${index}`"
        @click="emit('pick', prompt)"
      >
        {{ prompt }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  prompts: string[]
}>()

const emit = defineEmits<{
  pick: [prompt: string]
}>()
</script>
