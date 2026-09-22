<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import PromptMarkdownField from '@/components/admin/PromptMarkdownField.vue'
import { useAccordion } from '@/composables/useAccordion'
import { useDialog } from '@/composables/useDialog'
import { useMarkdown } from '@/composables/useMarkdown'
import { useNotification } from '@/composables/useNotification'
import { adminApi, type SystemPrompt } from '@/services/api/adminApi'

interface PromptDraft {
  shortDescription: string
  prompt: string
  selectionRules: string
}

const { t } = useI18n()
const { render } = useMarkdown()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const prompts = ref<SystemPrompt[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const draft = ref<PromptDraft>({ shortDescription: '', prompt: '', selectionRules: '' })

const sectionIds = computed(() => prompts.value.map((prompt) => String(prompt.id)))
const {
  isOpen: isPromptOpen,
  toggle: togglePrompt,
  open: openPrompt,
  expandAll,
  collapseAll,
  allOpen: allPromptsOpen,
} = useAccordion(sectionIds)

const fieldClass =
  'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'

onMounted(() => {
  void loadPrompts()
})

async function loadPrompts(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    const response = await adminApi.getSystemPrompts()
    prompts.value = response.prompts
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function rendered(markdown: string): string {
  return render(markdown, { processFileMarkers: false })
}

function isDirty(): boolean {
  if (editingId.value === null) return false
  const current = prompts.value.find((prompt) => prompt.id === editingId.value)
  if (!current) return false
  return (
    draft.value.shortDescription !== current.shortDescription ||
    draft.value.prompt !== current.prompt ||
    draft.value.selectionRules !== (current.selectionRules ?? '')
  )
}

async function startEdit(prompt: SystemPrompt): Promise<void> {
  if (saving.value) return
  if (editingId.value !== null && editingId.value !== prompt.id && isDirty()) {
    const discard = await confirm({
      title: t('admin.prompts.discardTitle'),
      message: t('admin.prompts.discardMessage'),
      danger: true,
    })
    if (!discard) return
  }

  openPrompt(String(prompt.id))
  editingId.value = prompt.id
  draft.value = {
    shortDescription: prompt.shortDescription,
    prompt: prompt.prompt,
    selectionRules: prompt.selectionRules ?? '',
  }
}

function cancelEdit(): void {
  editingId.value = null
}

async function savePrompt(promptId: number): Promise<void> {
  const payload: PromptDraft = { ...draft.value }
  saving.value = true
  try {
    const response = await adminApi.updatePrompt(promptId, payload)
    const index = prompts.value.findIndex((prompt) => prompt.id === promptId)
    if (index !== -1) {
      prompts.value[index] = response.prompt
    }
    if (editingId.value === promptId) {
      editingId.value = null
    }
    success(t('admin.prompts.saved', { topic: response.prompt.topic }))
  } catch {
    showError(t('admin.prompts.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="text-center py-12">
    <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
  </div>

  <div v-else-if="loadFailed" class="surface-card rounded-lg p-6 space-y-3">
    <p class="text-sm txt-primary">{{ t('admin.prompts.loadFailed') }}</p>
    <button
      type="button"
      class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
      data-testid="btn-prompts-retry"
      @click="loadPrompts()"
    >
      {{ t('admin.prompts.retry') }}
    </button>
  </div>

  <p v-else-if="prompts.length === 0" class="text-sm txt-secondary">
    {{ t('admin.prompts.empty') }}
  </p>

  <div v-else class="space-y-4">
    <div class="flex justify-end">
      <button
        type="button"
        class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
        data-testid="btn-prompts-accordion-toggle-all"
        @click="allPromptsOpen ? collapseAll() : expandAll()"
      >
        {{
          allPromptsOpen
            ? t('admin.config.accordion.collapseAll')
            : t('admin.config.accordion.expandAll')
        }}
      </button>
    </div>

    <AccordionStack testid="prompts-accordion">
      <AccordionSection
        v-for="prompt in prompts"
        :key="prompt.id"
        :panel-id="`prompt-section-${prompt.id}`"
        :title="prompt.topic"
        :open="isPromptOpen(String(prompt.id))"
        :header-testid="`btn-prompt-section-${prompt.id}`"
        :testid="`prompt-section-${prompt.id}`"
        @toggle="togglePrompt(String(prompt.id))"
      >
        <template #badge>
          <span class="surface-chip px-2 py-0.5 rounded-full text-xs txt-secondary">
            {{ prompt.language }}
          </span>
          <span class="hidden md:inline text-sm font-normal txt-secondary truncate max-w-xs">
            {{ prompt.shortDescription }}
          </span>
        </template>
        <template v-if="editingId !== prompt.id" #actions>
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="saving"
            :data-testid="`btn-edit-prompt-${prompt.id}`"
            @click="startEdit(prompt)"
          >
            <PencilSquareIcon class="w-4 h-4" />
            {{ t('admin.prompts.edit') }}
          </button>
        </template>

        <form
          v-if="editingId === prompt.id"
          class="space-y-4"
          @submit.prevent="savePrompt(prompt.id)"
        >
          <div>
            <label class="block text-sm font-medium txt-primary" :for="`prompt-desc-${prompt.id}`">
              {{ t('admin.prompts.shortDesc') }}
            </label>
            <input
              :id="`prompt-desc-${prompt.id}`"
              v-model="draft.shortDescription"
              type="text"
              :class="fieldClass"
              :data-testid="`input-prompt-desc-${prompt.id}`"
            />
          </div>
          <PromptMarkdownField
            v-model="draft.prompt"
            :label="t('admin.prompts.prompt')"
            :testid="`textarea-prompt-${prompt.id}`"
            :rows="14"
          />
          <PromptMarkdownField
            v-model="draft.selectionRules"
            :label="t('admin.prompts.selectionRules')"
            :testid="`textarea-selection-rules-${prompt.id}`"
            :placeholder="t('admin.prompts.selectionRulesPlaceholder')"
            :rows="6"
          />
          <div class="flex justify-end gap-3">
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
              data-testid="btn-cancel-edit-prompt"
              @click="cancelEdit()"
            >
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="saving"
              data-testid="btn-save-prompt"
            >
              {{ t('common.save') }}
            </button>
          </div>
        </form>

        <div v-else class="space-y-4">
          <p class="text-sm txt-secondary">{{ prompt.shortDescription }}</p>
          <!-- eslint-disable vue/no-v-html -- sanitized by useMarkdown -->
          <div
            class="prompt-markdown markdown-content txt-primary text-sm rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 px-4 py-3"
            :data-testid="`prompt-preview-${prompt.id}`"
            v-html="rendered(prompt.prompt)"
          />
          <div v-if="prompt.selectionRules" class="space-y-1">
            <p class="text-sm font-medium txt-primary">{{ t('admin.prompts.selectionRules') }}</p>
            <div
              class="prompt-markdown markdown-content txt-primary text-sm"
              v-html="rendered(prompt.selectionRules)"
            />
          </div>
          <!-- eslint-enable vue/no-v-html -->
        </div>
      </AccordionSection>
    </AccordionStack>
  </div>
</template>

<style scoped>
.prompt-markdown :deep(> :first-child) {
  margin-top: 0;
}
</style>
