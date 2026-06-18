<template>
  <div
    class="flex items-start gap-2 px-3 py-2 surface-chip rounded-lg max-w-full md:max-w-md"
    data-testid="chip-quote"
  >
    <Icon icon="mdi:format-quote-close" class="w-4 h-4 mt-0.5 flex-shrink-0 text-[var(--brand)]" />
    <span class="text-sm txt-secondary line-clamp-2 break-words min-w-0">{{ quote.text }}</span>
    <button
      type="button"
      class="icon-ghost p-0 min-w-0 w-auto h-auto flex-shrink-0"
      :aria-label="t('chat.quote.remove')"
      data-testid="btn-quote-remove"
      @click="emit('remove')"
    >
      <XMarkIcon class="w-4 h-4" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import type { QuotedReference } from '@/composables/useMessageQuoting'

defineProps<{
  quote: QuotedReference
}>()

const emit = defineEmits<{
  remove: []
}>()

const { t } = useI18n()
</script>
