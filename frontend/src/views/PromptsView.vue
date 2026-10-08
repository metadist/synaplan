<template>
  <MainLayout>
    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-8" data-testid="page-prompts">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h1 class="text-xl font-semibold txt-primary">{{ t('savedPrompts.title') }}</h1>
          <p class="mt-1 text-sm txt-secondary">{{ t('savedPrompts.empty') }}</p>
        </div>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-prompt-new"
          @click="startNew"
        >
          {{ t('savedPrompts.new') }}
        </button>
      </div>

      <form
        v-if="editing"
        class="surface-card space-y-3 p-4"
        data-testid="form-saved-prompt"
        @submit.prevent="save"
      >
        <h2 class="text-base font-semibold txt-primary" data-testid="text-saved-prompt-form-title">
          {{ editingId === null ? t('savedPrompts.new') : t('savedPrompts.editTitle') }}
        </h2>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.name') }}
          <input
            v-model="draft.name"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-saved-prompt-name"
            required
          />
        </label>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.command') }}
          <input
            v-model="draft.command"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-saved-prompt-command"
            required
          />
        </label>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.body') }}
          <textarea
            v-model="draft.body"
            rows="5"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-saved-prompt-body"
            required
          />
        </label>
        <p v-if="errorText" class="text-sm text-red-600 dark:text-red-400">{{ errorText }}</p>
        <div class="flex gap-2">
          <button
            type="submit"
            class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="saving"
            data-testid="btn-saved-prompt-save"
          >
            {{ t('savedPrompts.save') }}
          </button>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-saved-prompt-cancel"
            @click="closeForm"
          >
            {{ t('chatMessage.editCancel') }}
          </button>
        </div>
      </form>

      <ul v-if="prompts.length > 0" class="space-y-2" data-testid="list-saved-prompts">
        <li
          v-for="prompt in prompts"
          :key="prompt.id"
          class="surface-card flex flex-wrap items-center justify-between gap-3 p-4"
          data-testid="row-saved-prompt"
        >
          <div class="min-w-0 flex-1 basis-40">
            <p class="truncate font-medium txt-primary">{{ prompt.name }}</p>
            <p class="truncate text-sm txt-secondary">/{{ prompt.command }}</p>
          </div>
          <div class="flex flex-shrink-0 gap-2">
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 text-sm font-medium"
              data-testid="btn-saved-prompt-edit"
              @click="startEdit(prompt)"
            >
              {{ t('savedPrompts.edit') }}
            </button>
            <button
              type="button"
              class="btn-danger rounded-lg px-4 py-2.5 text-sm font-medium"
              data-testid="btn-saved-prompt-delete"
              @click="remove(prompt.id)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </li>
      </ul>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import { useNotification } from '@/composables/useNotification'
import { httpClient } from '@/services/api/httpClient'
import {
  GetApiSavedPromptsListResponseSchema,
  PostApiSavedPromptsCreateResponseSchema,
  PutApiSavedPromptsUpdateResponseSchema,
} from '@/generated/api-schemas'
import { useCommandsStore, type SavedPromptRow } from '@/stores/commands'

const { t } = useI18n()
const { success, error } = useNotification()
const commandsStore = useCommandsStore()
const prompts = ref<SavedPromptRow[]>([])
const editing = ref(false)
/** The prompt the form changes, or null while a new prompt is written. */
const editingId = ref<number | null>(null)
const saving = ref(false)
const errorText = ref('')
const draft = ref({ name: '', command: '', body: '' })

async function load(): Promise<void> {
  const data = await httpClient('/api/v1/saved-prompts', {
    schema: GetApiSavedPromptsListResponseSchema,
  })
  prompts.value = data.prompts ?? []
  // The slash menu and the command search list the same prompts.
  commandsStore.setSavedPrompts(prompts.value)
}

function openForm(id: number | null, values: { name: string; command: string; body: string }) {
  editingId.value = id
  draft.value = { ...values }
  errorText.value = ''
  editing.value = true
}

function startNew(): void {
  openForm(null, { name: '', command: '', body: '' })
}

function startEdit(prompt: SavedPromptRow): void {
  openForm(prompt.id, { name: prompt.name, command: prompt.command, body: prompt.body })
}

function closeForm(): void {
  editing.value = false
  editingId.value = null
}

async function writeDraft(id: number | null): Promise<void> {
  if (id === null) {
    await httpClient('/api/v1/saved-prompts', {
      method: 'POST',
      body: JSON.stringify(draft.value),
      schema: PostApiSavedPromptsCreateResponseSchema,
    })
    return
  }
  // An update replaces the tags too, so send back the ones the row has.
  const tags = prompts.value.find((row) => row.id === id)?.tags ?? []
  await httpClient(`/api/v1/saved-prompts/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ ...draft.value, tags }),
    schema: PutApiSavedPromptsUpdateResponseSchema,
  })
}

async function save(): Promise<void> {
  if (saving.value) return
  errorText.value = ''
  saving.value = true
  try {
    await writeDraft(editingId.value)
    closeForm()
    success(t('savedPrompts.saved'))
    await load()
  } catch (err) {
    errorText.value = err instanceof Error ? err.message : t('taskPlan.askFailed')
    error(errorText.value)
  } finally {
    saving.value = false
  }
}

async function remove(id: number): Promise<void> {
  try {
    await httpClient(`/api/v1/saved-prompts/${id}`, { method: 'DELETE' })
  } catch {
    error(t('savedPrompts.deleteFailed'))
    return
  }
  if (editingId.value === id) closeForm()
  await load()
}

onMounted(() => {
  void load()
})
</script>
