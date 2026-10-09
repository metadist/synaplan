<template>
  <MainLayout>
    <div class="mx-auto max-w-3xl space-y-6 p-4 md:p-8" data-testid="page-prompts">
      <PageHeader
        :title="t('savedPrompts.title')"
        :subtitle="t('savedPrompts.intro')"
        icon="heroicons:command-line"
        tour-id="shortcuts"
      >
        <template #actions>
          <button
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-prompt-new"
            @click="startNew"
          >
            {{ t('savedPrompts.new') }}
          </button>
        </template>
      </PageHeader>

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
            :placeholder="t('savedPrompts.commandPlaceholder')"
            aria-describedby="saved-prompt-command-hint"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-saved-prompt-command"
            required
          />
          <span id="saved-prompt-command-hint" class="mt-1 block text-xs txt-secondary">
            {{ t('savedPrompts.commandHint', { command: draft.command.trim() || 'summary' }) }}
          </span>
        </label>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.body') }}
          <textarea
            v-model="draft.body"
            :placeholder="t('savedPrompts.bodyPlaceholder')"
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

      <div
        v-if="loadFailed"
        class="surface-card p-4 flex flex-wrap items-center justify-between gap-3"
        data-testid="saved-prompts-load-error"
      >
        <p class="text-sm txt-secondary">{{ t('savedPrompts.loadFailed') }}</p>
        <button type="button" class="btn-secondary px-4 py-2.5 text-sm font-medium" @click="load">
          {{ t('common.retry') }}
        </button>
      </div>

      <EmptyState
        v-else-if="loaded && prompts.length === 0 && !editing"
        :title="t('savedPrompts.empty')"
        :hint="t('savedPrompts.emptyExample')"
        :action-label="t('savedPrompts.new')"
        test-id="saved-prompts-empty"
        @action="startNew"
      />

      <ul v-else-if="prompts.length > 0" class="space-y-2" data-testid="list-saved-prompts">
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
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-saved-prompt-edit"
              @click="startEdit(prompt)"
            >
              <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
              {{ t('savedPrompts.edit') }}
            </button>
            <button
              type="button"
              class="btn-danger inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium"
              data-testid="btn-saved-prompt-delete"
              @click="remove(prompt)"
            >
              <TrashIcon class="h-4 w-4" aria-hidden="true" />
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
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { useDialog } from '@/composables/useDialog'
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
const { confirm } = useDialog()
const commandsStore = useCommandsStore()
const prompts = ref<SavedPromptRow[]>([])
const editing = ref(false)
/** The prompt the form changes, or null while a new prompt is written. */
const editingId = ref<number | null>(null)
const saving = ref(false)
const errorText = ref('')
const loaded = ref(false)
const loadFailed = ref(false)
const draft = ref({ name: '', command: '', body: '' })

/** Show these rows and hand them to the slash menu and the command search. */
function showPrompts(rows: SavedPromptRow[]): void {
  prompts.value = rows
  commandsStore.setSavedPrompts(rows)
}

async function load(): Promise<void> {
  try {
    const data = await httpClient('/api/v1/saved-prompts', {
      schema: GetApiSavedPromptsListResponseSchema,
    })
    showPrompts(data.prompts ?? [])
    loadFailed.value = false
  } catch {
    loadFailed.value = true
  } finally {
    loaded.value = true
  }
}

/** The server lists prompts by name; keep that order for a saved row. */
function withSavedRow(saved: SavedPromptRow): SavedPromptRow[] {
  return [...prompts.value.filter((row) => row.id !== saved.id), saved].sort((a, b) =>
    a.name.localeCompare(b.name)
  )
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

/** Write the form and return the prompt as the server stored it. */
async function writeDraft(id: number | null): Promise<SavedPromptRow> {
  if (id === null) {
    const created = await httpClient('/api/v1/saved-prompts', {
      method: 'POST',
      body: JSON.stringify(draft.value),
      schema: PostApiSavedPromptsCreateResponseSchema,
    })
    return created.prompt
  }
  // An update replaces the tags too, so send back the ones the row has.
  const tags = prompts.value.find((row) => row.id === id)?.tags ?? []
  const updated = await httpClient(`/api/v1/saved-prompts/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ ...draft.value, tags }),
    schema: PutApiSavedPromptsUpdateResponseSchema,
  })
  return updated.prompt
}

async function save(): Promise<void> {
  if (saving.value) return
  errorText.value = ''
  saving.value = true
  try {
    const saved = await writeDraft(editingId.value)
    showPrompts(withSavedRow(saved))
    closeForm()
    success(t('savedPrompts.saved'))
  } catch (err) {
    // The server explains a rejected save in plain words (for example a command already in use).
    errorText.value =
      err instanceof Error && err.message ? err.message : t('savedPrompts.saveFailed')
    error(errorText.value)
  } finally {
    saving.value = false
  }
}

async function remove(prompt: SavedPromptRow): Promise<void> {
  const id = prompt.id
  const confirmed = await confirm({
    title: t('savedPrompts.deleteTitle'),
    message: t('savedPrompts.deleteConfirm', { command: prompt.command }),
    confirmText: t('common.delete'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await httpClient(`/api/v1/saved-prompts/${id}`, { method: 'DELETE' })
  } catch {
    error(t('savedPrompts.deleteFailed'))
    return
  }
  if (editingId.value === id) closeForm()
  showPrompts(prompts.value.filter((row) => row.id !== id))
}

onMounted(() => {
  void load()
})
</script>
