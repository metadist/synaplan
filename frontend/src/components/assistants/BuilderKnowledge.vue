<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-knowledge">
    <h2 class="txt-primary font-medium">{{ $t('assistants.knowledge') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('assistants.ownFolderHint') }}</p>
    <div
      v-if="files.length === 0"
      class="txt-secondary text-sm"
      data-testid="state-knowledge-empty"
    >
      {{ $t('assistants.noFiles') }}
    </div>
    <ul v-else class="space-y-2">
      <li
        v-for="file in files"
        :key="file.messageId"
        class="flex items-center justify-between gap-2 text-sm txt-primary"
      >
        <span class="truncate">{{ file.fileName }}</span>
      </li>
    </ul>
    <label
      class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center"
    >
      {{ $t('assistants.uploadFile') }}
      <input type="file" class="sr-only" data-testid="input-knowledge-file" @change="onUpload" />
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.ragLimit') }}</span>
      <input
        :value="ragLimit"
        type="number"
        min="1"
        max="50"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-rag-limit"
        @input="patchKnowledge('ragLimit', Number(($event.target as HTMLInputElement).value))"
      />
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.ragMinScore') }}</span>
      <input
        :value="ragMinScore"
        type="number"
        min="0"
        max="1"
        step="0.05"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-rag-min-score"
        @input="patchKnowledge('ragMinScore', Number(($event.target as HTMLInputElement).value))"
      />
    </label>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { promptsApi, type PromptFile } from '@/services/api/promptsApi'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'

const store = useAgentsStore()
const files = ref<PromptFile[]>([])

const topic = computed(() => {
  const slug = store.current?.slug
  return slug ? `agent:${slug}` : ''
})

const ragLimit = computed(() => store.current?.draft?.knowledge.ragLimit ?? 8)
const ragMinScore = computed(() => store.current?.draft?.knowledge.ragMinScore ?? 0.6)

async function loadFiles(): Promise<void> {
  if (!topic.value) {
    files.value = []
    return
  }
  files.value = await promptsApi.getPromptFiles(topic.value)
}

async function onUpload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !topic.value) {
    return
  }
  await promptsApi.uploadPromptFile(topic.value, file)
  input.value = ''
  await loadFiles()
}

function patchKnowledge(key: 'ragLimit' | 'ragMinScore', value: number): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    knowledge: { ...draft.knowledge, ownFolder: true, [key]: value },
  }
  store.markDirty()
}

onMounted(() => {
  void loadFiles()
})
</script>
