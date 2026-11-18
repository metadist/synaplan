<template>
  <section class="space-y-4" data-testid="section-project-instructions">
    <p class="text-sm txt-secondary">{{ $t('projects.instructions.intro') }}</p>
    <div class="space-y-2">
      <label class="block text-sm font-medium txt-primary" :for="fieldId">
        {{ $t('projects.instructions.label') }}
      </label>
      <textarea
        :id="fieldId"
        v-model="draft"
        rows="10"
        :maxlength="maxChars"
        class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :placeholder="$t('projects.instructions.placeholder')"
        data-testid="input-project-instructions"
      ></textarea>
      <p class="text-xs txt-secondary text-right tabular-nums">
        {{ draft.length }} / {{ maxChars }}
      </p>
    </div>
    <div class="flex items-center justify-end gap-3">
      <p
        v-if="saved"
        class="text-sm txt-secondary"
        role="status"
        data-testid="text-project-instructions-saved"
      >
        {{ $t('projects.instructions.saved') }}
      </p>
      <p v-if="failed" class="text-sm text-red-600 dark:text-red-400" role="alert">
        {{ $t('projects.instructions.saveFailed') }}
      </p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        :disabled="!dirty || saving"
        data-testid="btn-project-instructions-save"
        @click="save"
      >
        {{ $t('common.save') }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useProjectsStore } from '@/stores/projects'
import type { ProjectItem } from '@/services/api/projectsApi'

/** The server's default for PROJECTS.INSTRUCTIONS_MAX_CHARS. A longer text is cut there. */
const maxChars = 4000

const props = defineProps<{ project: ProjectItem }>()

const store = useProjectsStore()
const fieldId = `project-instructions-${useId()}`

const draft = ref(props.project.instructions ?? '')
const saving = ref(false)
const saved = ref(false)
const failed = ref(false)

const dirty = computed(() => draft.value !== (props.project.instructions ?? ''))

watch(
  () => props.project.id,
  () => {
    draft.value = props.project.instructions ?? ''
    saved.value = false
    failed.value = false
  }
)

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  failed.value = false
  try {
    const text = draft.value.trim()
    await store.update(props.project.id, { instructions: text === '' ? null : text })
    draft.value = text
    saved.value = true
  } catch {
    failed.value = true
  } finally {
    saving.value = false
  }
}
</script>
