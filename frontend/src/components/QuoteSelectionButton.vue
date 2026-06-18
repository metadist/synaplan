<template>
  <Teleport to="body">
    <button
      v-if="visible"
      type="button"
      class="fixed z-[9999] flex items-center gap-1.5 px-3 py-1.5 rounded-lg surface-card shadow-lg border border-light-border/30 dark:border-dark-border/20 text-sm font-medium txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
      :style="style"
      data-testid="btn-quote-selection"
      @mousedown.prevent
      @click="emit('quote')"
    >
      <Icon icon="mdi:format-quote-close" class="w-4 h-4 text-[var(--brand)]" />
      {{ t('chat.quote.button') }}
    </button>
  </Teleport>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { FloatingButtonPosition } from '@/composables/useMessageQuoting'

const props = defineProps<{
  visible: boolean
  position: FloatingButtonPosition
}>()

const emit = defineEmits<{
  quote: []
}>()

const { t } = useI18n()

const GAP = 8
const MIN_TOP_SPACE = 56

// Place the button centered above the selection, flipping below when the
// selection sits too close to the viewport's top edge.
const style = computed(() => {
  const openBelow = props.position.top < MIN_TOP_SPACE
  return {
    left: `${props.position.left}px`,
    top: openBelow ? `${props.position.bottom + GAP}px` : `${props.position.top - GAP}px`,
    transform: openBelow ? 'translate(-50%, 0)' : 'translate(-50%, -100%)',
  }
})
</script>
