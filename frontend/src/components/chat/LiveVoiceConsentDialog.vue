<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

defineProps<{
  title: string
  body: string
  allowLabel: string
  declineLabel: string
}>()

const emit = defineEmits<{
  allow: []
  decline: []
}>()

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  event.preventDefault()
  emit('decline')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
    role="presentation"
  >
    <button
      type="button"
      class="absolute inset-0 bg-black/40"
      :aria-label="declineLabel"
      data-testid="btn-live-voice-consent-backdrop"
      @click="emit('decline')"
    />
    <div
      class="surface-card relative w-full max-w-md rounded-xl p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="live-voice-consent-title"
      data-testid="dialog-live-voice-consent"
    >
      <h2 id="live-voice-consent-title" class="text-lg font-medium txt-primary">{{ title }}</h2>
      <p class="mt-2 text-sm txt-secondary">{{ body }}</p>
      <div class="mt-6 flex flex-col gap-2">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-live-voice-consent-allow"
          autofocus
          @click="emit('allow')"
        >
          {{ allowLabel }}
        </button>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-live-voice-consent-decline"
          @click="emit('decline')"
        >
          {{ declineLabel }}
        </button>
      </div>
    </div>
  </div>
</template>
