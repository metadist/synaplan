<template>
  <section class="surface-card rounded-xl p-5 space-y-3" data-testid="section-builder-instructions">
    <h2 class="txt-primary font-medium">{{ $t('assistants.instructions') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('assistants.instructionsHint') }}</p>
    <textarea
      v-model="text"
      rows="10"
      class="w-full"
      :placeholder="$t('assistants.instructionsPlaceholder')"
      data-testid="input-assistant-instructions"
      @change="save"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { promptsApi } from '@/services/api/promptsApi'
import { useAgentsStore } from '@/stores/agents'

const store = useAgentsStore()
const text = ref('')
let loadedPromptId: number | null = null

async function load(): Promise<void> {
  const promptId = store.current?.promptId
  if (!promptId) {
    return
  }
  const prompt = await promptsApi.getPrompt(promptId)
  text.value = prompt.prompt
  loadedPromptId = promptId
}

async function save(): Promise<void> {
  const promptId = store.current?.promptId
  if (!promptId) {
    return
  }
  await promptsApi.updatePrompt(promptId, { prompt: text.value })
}

onMounted(() => {
  void load()
})

watch(
  () => store.current?.promptId,
  (id) => {
    if (id && id !== loadedPromptId) {
      void load()
    }
  }
)
</script>
