<template>
  <button
    class="fixed bottom-6 right-6 w-14 h-14 rounded-full bg-[var(--brand)] text-white shadow-lg hover:shadow-xl transition-all hover:scale-110 flex items-center justify-center z-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--brand)]"
    :aria-label="$t('help.openHelp')"
    data-testid="btn-help"
    @click="$emit('click')"
  >
    <QuestionMarkCircleIcon class="w-7 h-7" />
  </button>
</template>

<script setup lang="ts">
import { QuestionMarkCircleIcon } from '@heroicons/vue/24/outline'

defineEmits<{
  click: []
}>()
</script>
