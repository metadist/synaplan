<template>
  <div
    class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
    data-testid="section-assistant-builder"
  >
    <div class="space-y-4 min-w-0">
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
          :disabled="!store.dirty || store.saving"
          data-testid="btn-save-assistant"
          @click="onSave"
        >
          {{ store.saving ? $t('assistants.saving') : $t('assistants.save') }}
        </button>
        <span v-if="store.dirty" class="txt-secondary text-sm">{{ $t('assistants.unsaved') }}</span>
        <span v-else-if="!store.saving" class="txt-secondary text-sm">{{
          $t('assistants.saved')
        }}</span>
      </div>
      <BuilderBasics />
      <BuilderInstructions />
      <BuilderModels />
      <BuilderKnowledge />
      <AssistantPublishSection @deleted="emit('deleted')" />
    </div>
    <AssistantTestPanel />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useAgentsStore } from '@/stores/agents'
import BuilderBasics from './BuilderBasics.vue'
import BuilderInstructions from './BuilderInstructions.vue'
import BuilderModels from './BuilderModels.vue'
import BuilderKnowledge from './BuilderKnowledge.vue'
import AssistantTestPanel from './AssistantTestPanel.vue'
import AssistantPublishSection from './AssistantPublishSection.vue'

const emit = defineEmits<{
  deleted: []
}>()

const store = useAgentsStore()
const { t } = useI18n()
const { error } = useNotification()

async function onSave(): Promise<void> {
  try {
    await store.saveDraft()
  } catch {
    error(t('assistants.saveFailed'))
  }
}
</script>
