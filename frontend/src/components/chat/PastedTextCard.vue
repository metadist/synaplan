<template>
  <div
    class="pasted-card"
    role="button"
    tabindex="0"
    :aria-label="t('chatInput.pastedText.open')"
    data-testid="chip-pasted-text"
    @click="emit('open')"
    @keydown="handleKeydown"
  >
    <p class="pasted-card__preview">{{ preview }}</p>
    <span class="pasted-card__label">{{ t('chatInput.pastedText.label') }}</span>
    <button
      v-if="!readonly"
      type="button"
      class="pasted-card__remove icon-ghost"
      :aria-label="t('chatInput.pastedText.remove')"
      data-testid="btn-pasted-text-remove"
      @click.stop="emit('remove')"
    >
      <XMarkIcon class="w-3.5 h-3.5" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon } from '@heroicons/vue/24/outline'

const props = withDefaults(
  defineProps<{
    content: string
    readonly?: boolean
  }>(),
  {
    readonly: false,
  }
)

const emit = defineEmits<{
  open: []
  remove: []
}>()

const { t } = useI18n()

const preview = computed(() => {
  const collapsed = props.content.replace(/\s+/g, ' ').trim()
  return collapsed.length > 160 ? `${collapsed.slice(0, 160)}…` : collapsed
})

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    emit('open')
  }
}
</script>
