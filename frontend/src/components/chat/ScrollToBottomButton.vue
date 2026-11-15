<template>
  <Transition
    enter-active-class="transition-opacity duration-150 ease-out"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-100 ease-in"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <button
      v-if="visible"
      type="button"
      class="surface-card hover-surface absolute left-1/2 bottom-full z-30 mb-3 flex h-11 w-11 -translate-x-1/2 items-center justify-center !rounded-full shadow-md txt-primary touch-manipulation focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
      :aria-label="t('scrollToBottom')"
      data-testid="btn-scroll-to-bottom"
      @click="emit('jump')"
    >
      <ArrowDownIcon class="h-5 w-5" aria-hidden="true" />
    </button>
  </Transition>
</template>

<script setup lang="ts">
import { ArrowDownIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'

defineProps<{
  visible: boolean
}>()

const emit = defineEmits<{
  jump: []
}>()

const { t } = useI18n()
</script>
