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
import { useI18n } from 'vue-i18n'
import { promptsApi } from '@/services/api/promptsApi'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { derivedTexts } from '@/utils/agentDescribe'
import { useAgentsStore } from '@/stores/agents'

const store = useAgentsStore()
const { tm } = useI18n()
const text = ref('')
let loadedPromptId: number | null = null
let loadedSource: string | null = null

/**
 * An empty description or greeting takes the sentence derived from these
 * instructions, so the same thought is never typed twice. Texts the user
 * wrote stay untouched.
 */
function applyDerived(): void {
  const agent = store.current
  if (!agent) {
    return
  }
  const draft = agent.draft ?? emptyAgentDraft()
  const greetingTemplate = tm('assistants.generatedGreeting')
  const derived = derivedTexts({
    instructions: text.value,
    description: agent.description ?? '',
    greeting: draft.behaviour.greeting ?? '',
    name: agent.name ?? '',
    greetingTemplate: typeof greetingTemplate === 'string' ? greetingTemplate : '',
  })
  let changed = false
  if (derived.description !== undefined) {
    agent.description = derived.description
    changed = true
  }
  if (derived.greeting !== undefined) {
    agent.draft = { ...draft, behaviour: { ...draft.behaviour, greeting: derived.greeting } }
    changed = true
  }
  if (changed) {
    store.markDirty()
  }
}

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
  applyDerived()
}

async function save(): Promise<void> {
  const promptId = store.current?.promptId
  if (!promptId) {
    return
  }
  await promptsApi.updatePrompt(promptId, { prompt: text.value })
  applyDerived()
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
