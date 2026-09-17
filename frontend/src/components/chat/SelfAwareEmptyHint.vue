<template>
  <div v-if="visible" class="flex justify-center" data-testid="self-aware-empty-hint">
    <button
      type="button"
      class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
      data-testid="btn-self-aware-empty-hint"
      @click="emit('ask', question)"
    >
      {{ $t('companionLinks.ask') }}
    </button>
  </div>
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
const question = computed(() => t('selfAware.examplePrompt'))
</script>
