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
      <div
        v-if="otherFieldErrors.length > 0"
        class="rounded-lg border border-red-600/30 dark:border-red-400/30 px-3 py-2 space-y-1"
        data-testid="notice-save-errors"
      >
        <p class="txt-primary text-sm font-medium">{{ $t('assistants.couldNotSaveField') }}</p>
        <p
          v-for="entry in otherFieldErrors"
          :key="entry.path"
          class="text-sm text-red-600 dark:text-red-400"
        >
          {{ entry.message }}
        </p>
      </div>
      <BuilderBasics />
      <BuilderInstructions />
      <BuilderModels />
      <BuilderKnowledge />
      <BuilderToolsSkills />
      <BuilderTriggers />
      <AssistantPublishSection @deleted="emit('deleted')" />
    </div>
    <AssistantTestPanel />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAgentsStore } from '@/stores/agents'
import BuilderBasics from './BuilderBasics.vue'
import BuilderInstructions from './BuilderInstructions.vue'
import BuilderModels from './BuilderModels.vue'
import BuilderKnowledge from './BuilderKnowledge.vue'
import BuilderToolsSkills from './BuilderToolsSkills.vue'
import BuilderTriggers from './BuilderTriggers.vue'
import AssistantTestPanel from './AssistantTestPanel.vue'
import AssistantPublishSection from './AssistantPublishSection.vue'

const emit = defineEmits<{
  deleted: []
}>()

const INLINE_ERROR_PATHS = new Set(['name', 'description', 'behaviour.greeting'])

const store = useAgentsStore()

const otherFieldErrors = computed(() =>
  Object.entries(store.fieldErrors)
    .filter(([path]) => !INLINE_ERROR_PATHS.has(path))
    .map(([path, message]) => ({ path, message }))
)

async function onSave(): Promise<void> {
  try {
    await store.saveDraft()
  } catch {
    // saveDraft already recorded fieldErrors and toasted a nameless failure.
  }
}
</script>
