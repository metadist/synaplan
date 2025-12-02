<template>
  <div class="my-3 space-y-2" data-testid="section-message-docs">
    <div
      v-for="(match, index) in matches"
      :key="index"
      class="p-3 surface-chip"
    >
      <div class="flex items-start gap-3">
        <DocumentTextIcon class="w-5 h-5 txt-secondary flex-shrink-0 mt-0.5" />
        <div class="flex-1 min-w-0">
          <h4 class="text-sm font-medium txt-primary mb-1 font-mono">{{ match.filename }}</h4>
          <p class="text-xs txt-secondary">{{ match.snippet }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { DocumentTextIcon } from '@heroicons/vue/24/outline'

interface DocMatch {
  filename: string
  snippet: string
}

interface Props {
  matches: DocMatch[]
}

defineProps<Props>()
</script>
