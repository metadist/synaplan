<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  messages: Array<{ role: string; text: string }> | null
  truncated: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()

const blocks = computed(() => props.messages ?? [])
const empty = computed(() => blocks.value.length === 0)

const heading = (role: string) => {
  if (role === 'instructions') return t('chatMessage.whatWasSentInstructions')
  if (role === 'you') return t('chatMessage.whatWasSentYou')
  return t('chatMessage.whatWasSentAssistant')
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div
    class="fixed inset-0 z-[200] flex items-end sm:items-center justify-center p-4 bg-black/40"
    role="presentation"
    @click.self="emit('close')"
  >
    <div
      class="surface-card w-full max-w-lg max-h-[80vh] overflow-y-auto rounded-xl p-4 space-y-4"
      role="dialog"
      aria-modal="true"
      :aria-label="t('chatMessage.whatWasSentTitle')"
      data-testid="dialog-what-was-sent"
    >
      <h2 class="text-base font-semibold txt-primary">{{ t('chatMessage.whatWasSentTitle') }}</h2>
      <p class="text-sm txt-secondary">{{ t('chatMessage.whatWasSentBody') }}</p>
      <p v-if="empty" class="text-sm txt-primary" data-testid="text-what-was-sent-empty">
        {{ t('chatMessage.whatWasSentEmpty') }}
      </p>
      <div v-else class="space-y-3">
        <section
          v-for="(block, index) in blocks"
          :key="index"
          class="rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 space-y-1"
          data-testid="block-what-was-sent"
        >
          <h3 class="text-xs font-medium txt-secondary">{{ heading(block.role) }}</h3>
          <p class="text-sm txt-primary whitespace-pre-wrap break-words">{{ block.text }}</p>
        </section>
      </div>
      <p v-if="truncated" class="text-sm txt-secondary" data-testid="text-what-was-sent-truncated">
        {{ t('chatMessage.whatWasSentTruncated') }}
      </p>
      <p class="text-sm txt-secondary">{{ t('chatMessage.whatWasSentSession') }}</p>
      <div class="flex justify-end">
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-what-was-sent-close"
          @click="emit('close')"
        >
          {{ t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>
