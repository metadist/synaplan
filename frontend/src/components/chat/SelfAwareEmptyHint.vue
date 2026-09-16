<template>
  <p v-if="visible" class="txt-secondary mt-2" data-testid="self-aware-empty-hint">
    <span>{{ lead }}</span>
    <button
      type="button"
      class="underline txt-brand ml-1"
      data-testid="btn-self-aware-empty-hint"
      @click="emit('ask', question)"
    >
      {{ action }}
    </button>
  </p>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useIncognitoStore } from '@/stores/incognito'

const emit = defineEmits<{
  ask: [question: string]
}>()

const { t } = useI18n()
const configStore = useConfigStore()
const incognitoStore = useIncognitoStore()

const visible = computed(() => configStore.features.selfAware && !incognitoStore.active)
const question = computed(() => t('selfAware.emptyHintQuestion'))

const splitHint = computed(() => {
  const hint = t('selfAware.emptyHint')
  const idx = hint.lastIndexOf('?')
  if (idx === -1) {
    return { lead: hint, action: question.value }
  }
  const action = hint.slice(idx + 1).trim()
  return {
    lead: hint.slice(0, idx + 1).trimEnd(),
    action: action || question.value,
  }
})

const lead = computed(() => splitHint.value.lead)
const action = computed(() => splitHint.value.action)
</script>
