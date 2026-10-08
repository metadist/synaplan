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
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.name') }}
          <input
            v-model="draft.name"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            required
          />
        </label>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.command') }}
          <input
            v-model="draft.command"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            required
          />
        </label>
        <label class="block text-sm txt-primary">
          {{ t('savedPrompts.body') }}
          <textarea
            v-model="draft.body"
            rows="5"
            class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            required
          />
        </label>
        <p v-if="errorText" class="text-sm text-red-600 dark:text-red-400">{{ errorText }}</p>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary px-4 py-2.5 text-sm font-medium">
            {{ t('savedPrompts.saved') }}
          </button>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            @click="editing = false"
          >
            {{ t('chatMessage.editCancel') }}
          </button>
        </div>
      </form>

      <ul v-if="prompts.length > 0" class="space-y-2" data-testid="list-saved-prompts">
        <li
          v-for="prompt in prompts"
          :key="prompt.id"
          class="surface-card flex items-center justify-between gap-3 p-4"
        >
          <div class="min-w-0">
            <p class="truncate font-medium txt-primary">{{ prompt.name }}</p>
            <p class="text-sm txt-secondary">/{{ prompt.command }}</p>
          </div>
          <button
            type="button"
            class="btn-danger rounded-lg px-4 py-2.5 text-sm font-medium"
            @click="remove(prompt.id)"
          >
            {{ t('common.delete') }}
          </button>
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
import { GetApiSavedPromptsListResponseSchema } from '@/generated/api-schemas'
import { useCommandsStore, type SavedPromptRow } from '@/stores/commands'

const { t } = useI18n()
const { success, error } = useNotification()
const commandsStore = useCommandsStore()
const prompts = ref<SavedPromptRow[]>([])
const editing = ref(false)
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

function startNew(): void {
  draft.value = { name: '', command: '', body: '' }
  errorText.value = ''
  editing.value = true
}

async function save(): Promise<void> {
  errorText.value = ''
  try {
    await httpClient('/api/v1/saved-prompts', {
      method: 'POST',
      body: JSON.stringify(draft.value),
    })
    editing.value = false
    success(t('savedPrompts.saved'))
    await load()
  } catch (err) {
    errorText.value = err instanceof Error ? err.message : t('taskPlan.askFailed')
    error(errorText.value)
  }
}

async function remove(id: number): Promise<void> {
  await httpClient(`/api/v1/saved-prompts/${id}`, { method: 'DELETE' })
  await load()
}

onMounted(() => {
  void load()
})
</script>
