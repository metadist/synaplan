<template>
  <section
    v-if="templateId"
    class="surface-card rounded-xl p-5 space-y-3"
    data-testid="section-template-checklist"
  >
    <p class="txt-secondary text-xs" data-testid="badge-from-template">
      {{ $t('templates.fromTemplate') }}
    </p>
    <h2 class="txt-primary font-medium">{{ title }}</h2>

    <p v-if="updateAvailable" class="txt-primary text-sm" data-testid="notice-template-update">
      {{ $t('templates.updateAvailable') }}
    </p>
    <div v-if="updateAvailable" class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-template-show-changes"
        @click="toggleDiff"
      >
        {{ showDiff ? $t('templates.hideChanges') : $t('templates.showChanges') }}
      </button>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        :disabled="updating"
        data-testid="btn-template-update"
        @click="applyUpdate"
      >
        {{ updating ? $t('templates.updating') : $t('templates.update') }}
      </button>
    </div>
    <div v-if="showDiff && diff" class="space-y-2" data-testid="section-template-diff">
      <p class="txt-secondary text-sm">{{ $t('templates.updateKeepsName') }}</p>
      <p class="txt-primary text-sm font-medium">{{ $t('templates.diffFrom') }}</p>
      <p class="txt-secondary text-sm whitespace-pre-wrap">{{ diff.from }}</p>
      <p class="txt-primary text-sm font-medium">{{ $t('templates.diffTo') }}</p>
      <p class="txt-secondary text-sm whitespace-pre-wrap">{{ diff.to }}</p>
    </div>

    <p v-if="pending.length === 0" class="txt-primary text-sm" data-testid="text-template-ready">
      {{ $t('templates.checklistReady') }}
    </p>
    <ul v-else class="space-y-2">
      <li
        v-for="(requirement, index) in pending"
        :key="`${requirement.kind}-${index}`"
        class="flex flex-wrap items-center justify-between gap-2"
      >
        <span class="txt-primary text-sm">{{ requirementText(requirement) }}</span>
        <button
          v-if="requirement.action === 'chooseFolder'"
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-template-choose-folder"
          @click="chooseFolder"
        >
          {{ $t('templates.chooseFolder') }}
        </button>
        <button
          v-else-if="requirement.action === 'connectMail'"
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-template-connect-mail"
          @click="router.push('/apps/mailbox')"
        >
          {{ $t('templates.connectMail') }}
        </button>
      </li>
    </ul>

    <div v-if="examples.length > 0" class="flex flex-wrap gap-2">
      <button
        v-for="(example, index) in examples"
        :key="index"
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :data-testid="`btn-template-try-${index}`"
        @click="tryExample(example)"
      >
        {{ $t('templates.tryIt') }}: {{ example }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAgentsStore } from '@/stores/agents'
import { useNotification } from '@/composables/useNotification'
import {
  agentTemplatesApi,
  type AgentTemplate,
  type AgentTemplateRequirement,
} from '@/services/api/agentTemplatesApi'

const { t } = useI18n()
const router = useRouter()
const store = useAgentsStore()
const { success, error } = useNotification()

const template = ref<AgentTemplate | null>(null)
const showDiff = ref(false)
const updating = ref(false)
const diff = ref<{ from?: string; to?: string } | null>(null)

const templateId = computed(() => {
  const source = store.current?.source ?? ''
  const match = /^template:([a-z0-9][a-z0-9-]{0,31})@/.exec(source)
  return match?.[1] ?? null
})

const title = computed(() =>
  template.value?.titleKey ? t(template.value.titleKey) : t('templates.checklistTitle')
)

const knowledgeChosen = computed(() => {
  const knowledge = store.current?.draft?.knowledge
  return (knowledge?.folders.length ?? 0) > 0 || (knowledge?.fileIds?.length ?? 0) > 0
})

const pending = computed(() =>
  (template.value?.requires ?? []).filter((requirement) => {
    if (requirement.optional && requirement.kind === 'connection') {
      return requirement.state !== 'ready'
    }
    if (requirement.kind === 'knowledgeFolder') return !knowledgeChosen.value
    return requirement.state !== 'ready'
  })
)

const examples = computed(() => store.current?.draft?.behaviour.starterPrompts ?? [])
const updateAvailable = computed(() => template.value?.updateAvailable === true)

function requirementText(requirement: AgentTemplateRequirement): string {
  if (requirement.state === 'unavailable' && requirement.value === 'pic2text') {
    return t('templates.unavailableImage')
  }
  if (requirement.state === 'unavailable') return t('templates.unavailableChat')
  if (requirement.hintKey) return t(requirement.hintKey)
  return t('templates.checklistTitle')
}

function chooseFolder(): void {
  document.querySelector('[data-testid="section-builder-knowledge"]')?.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
  })
}

async function load(): Promise<void> {
  const id = templateId.value
  if (!id) {
    template.value = null
    return
  }
  try {
    const data = await agentTemplatesApi.list()
    template.value = data.templates.find((row) => row.id === id) ?? null
  } catch {
    template.value = null
  }
}

async function toggleDiff(): Promise<void> {
  if (showDiff.value) {
    showDiff.value = false
    return
  }
  const id = templateId.value
  if (!id) return
  try {
    diff.value = await agentTemplatesApi.diff(id)
    showDiff.value = true
  } catch {
    error(t('templates.updateFailed'))
  }
}

async function applyUpdate(): Promise<void> {
  const id = templateId.value
  const agentId = store.current?.id
  if (!id || agentId == null) return
  updating.value = true
  try {
    await agentTemplatesApi.update(id)
    await store.load(agentId)
    showDiff.value = false
    success(t('templates.updateDone'))
    await load()
  } catch {
    error(t('templates.updateFailed'))
  } finally {
    updating.value = false
  }
}

function tryExample(example: string): void {
  const id = store.current?.id
  if (id == null) return
  void router.push({ name: 'chat', query: { agentId: String(id), prefill: example } })
}

onMounted(() => {
  void load()
})

watch(templateId, () => {
  void load()
})
</script>
