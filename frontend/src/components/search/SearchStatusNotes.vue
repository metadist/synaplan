<template>
  <p
    v-for="note in notes"
    :key="note.testId"
    class="px-3 py-2 text-xs txt-secondary"
    role="status"
    :data-testid="note.testId"
  >
    {{ note.text }}
  </p>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  statusText: string | null
  remoteNote: string | null
  aiNote: string | null
}>()

const notes = computed(() =>
  [
    { testId: 'text-smart-search-status', text: props.statusText },
    { testId: 'text-smart-search-remote-note', text: props.remoteNote },
    { testId: 'text-smart-search-ai-note', text: props.aiNote },
  ].filter((note): note is { testId: string; text: string } => Boolean(note.text))
)
</script>
