<template>
  <section class="surface-card rounded-xl p-5 space-y-3" data-testid="section-builder-instructions">
    <h2 class="txt-primary font-medium">{{ $t('assistants.instructions') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('assistants.instructionsHint') }}</p>
    <textarea
      v-model="text"
      rows="10"
      class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
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
let loadedSource: string | null = null

async function load(): Promise<void> {
  const promptId = store.current?.promptId
  if (!promptId) {
    return
  }
  const source = store.current?.source ?? null
  const prompt = await promptsApi.getPrompt(promptId)
  text.value = prompt.prompt
  loadedPromptId = promptId
  loadedSource = source
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
  () => [store.current?.promptId, store.current?.source] as const,
  ([id, source]) => {
    if (id && (id !== loadedPromptId || source !== loadedSource)) {
      void load()
    }
  }
)
</script>
