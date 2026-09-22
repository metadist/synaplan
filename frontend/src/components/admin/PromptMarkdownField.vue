<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EyeIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'
import { useMarkdown } from '@/composables/useMarkdown'

const model = defineModel<string>({ required: true })

const props = withDefaults(
  defineProps<{
    label: string
    testid: string
    rows?: number
    placeholder?: string
  }>(),
  {
    rows: 12,
    placeholder: '',
  }
)

const { t } = useI18n()
const { render } = useMarkdown()
const mode = ref<'write' | 'preview'>('write')
const inputId = computed(() => `prompt-md-${props.testid}`)

const previewHtml = computed(() => render(model.value, { processFileMarkers: false }))

const fieldClass =
  'w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'

function tabClass(active: boolean): string {
  const tone = active ? 'btn-primary' : 'btn-secondary'
  return `${tone} inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium`
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
      <label :for="inputId" class="block text-sm font-medium txt-primary">{{ label }}</label>
      <div class="flex gap-2" role="tablist" :aria-label="label">
        <button
          type="button"
          role="tab"
          :class="tabClass(mode === 'write')"
          :aria-selected="mode === 'write'"
          :data-testid="`btn-prompt-write-${testid}`"
          @click="mode = 'write'"
        >
          <PencilSquareIcon class="w-4 h-4" />
          {{ t('admin.prompts.write') }}
        </button>
        <button
          type="button"
          role="tab"
          :class="tabClass(mode === 'preview')"
          :aria-selected="mode === 'preview'"
          :data-testid="`btn-prompt-preview-${testid}`"
          @click="mode = 'preview'"
        >
          <EyeIcon class="w-4 h-4" />
          {{ t('admin.prompts.preview') }}
        </button>
      </div>
    </div>

    <textarea
      v-show="mode === 'write'"
      :id="inputId"
      v-model="model"
      :rows="rows"
      :placeholder="placeholder"
      :class="fieldClass"
      :data-testid="testid"
    />

    <div
      v-show="mode === 'preview'"
      class="rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 px-3 py-2"
      :data-testid="`preview-${testid}`"
    >
      <p v-if="!model.trim()" class="text-sm txt-secondary">
        {{ t('admin.prompts.previewEmpty') }}
      </p>
      <!-- eslint-disable vue/no-v-html -- sanitized by useMarkdown -->
      <div
        v-else
        class="prompt-markdown markdown-content txt-primary text-sm"
        v-html="previewHtml"
      />
      <!-- eslint-enable vue/no-v-html -->
    </div>
  </div>
</template>

<style scoped>
.prompt-markdown :deep(> :first-child) {
  margin-top: 0;
}
</style>
