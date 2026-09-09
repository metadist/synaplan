<template>
  <div v-if="visible" class="surface-card p-6 space-y-4" data-testid="section-export-import">
    <h2 class="text-lg font-semibold txt-primary">{{ $t('bundle.title') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('bundle.description') }}</p>
    <p class="txt-secondary text-sm">{{ $t('bundle.createsDrafts') }}</p>

    <div v-if="loading" class="txt-secondary text-sm">{{ $t('bundle.loading') }}</div>
    <div v-else-if="sections.length === 0" class="txt-secondary text-sm">
      {{ $t('bundle.noSections') }}
    </div>
    <fieldset v-else class="space-y-2">
      <legend class="txt-primary text-sm font-medium">{{ $t('bundle.sections') }}</legend>
      <label
        v-for="section in sections"
        :key="section.kind"
        class="flex items-center gap-2 text-sm txt-primary"
      >
        <input v-model="selectedKinds" type="checkbox" :value="section.kind" />
        {{ sectionLabel(section.kind) }}
        <span class="txt-secondary">({{ section.itemCount }})</span>
      </label>
    </fieldset>

    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="selectedKinds.length === 0 || busy"
        data-testid="btn-bundle-export"
        @click="exportBundle"
      >
        {{ $t('bundle.export') }}
      </button>
      <label class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium cursor-pointer">
        {{ $t('bundle.chooseFile') }}
        <input
          type="file"
          accept=".json,application/json"
          class="sr-only"
          data-testid="input-bundle-file"
          @change="onFile"
        />
      </label>
    </div>

    <p v-if="errorText" class="text-sm text-red-600 dark:text-red-400">{{ errorText }}</p>

    <div
      v-if="preview"
      class="rounded-lg border border-light-border/30 dark:border-dark-border/20 p-4 space-y-3"
      data-testid="bundle-preview"
    >
      <h3 class="txt-primary text-sm font-medium">{{ $t('bundle.previewTitle') }}</h3>
      <p v-if="preview.fromOtherInstance" class="txt-secondary text-sm">
        {{ $t('bundle.fromAnotherInstance') }}
      </p>
      <ul v-if="checklist.length > 0" class="space-y-1">
        <li
          v-for="(row, index) in checklist"
          :key="`${row.code}-${row.itemKey}-${index}`"
          class="txt-primary text-sm"
        >
          {{ checklistLabel(row) }}
        </li>
      </ul>
      <p v-else class="txt-secondary text-sm">{{ $t('bundle.emptyChecklist') }}</p>
      <label class="flex items-center gap-2 text-sm txt-primary">
        <input v-model="overwrite" type="checkbox" data-testid="input-bundle-overwrite" />
        {{ $t('bundle.overwriteExisting') }}
      </label>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="busy"
        data-testid="btn-bundle-import"
        @click="importBundle"
      >
        {{ $t('bundle.importAction') }}
      </button>
    </div>

    <ul v-if="results.length > 0" class="space-y-1" data-testid="bundle-results">
      <li v-for="result in results" :key="result.kind" class="txt-primary text-sm">
        {{ sectionLabel(result.kind) }}:
        {{ $t('bundle.resultCreated', { count: result.created.length }) }},
        {{ $t('bundle.resultSkipped', { count: result.skipped.length }) }},
        {{ $t('bundle.resultFailed', { count: result.failed.length }) }}
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isBundleEnabled } from '@/composables/useBundleFeature'
import { useNotification } from '@/composables/useNotification'
import {
  bundleApi,
  type BundleChecklistItem,
  type BundleImportResult,
  type BundlePreview,
  type BundleScope,
  type BundleSection,
} from '@/services/api/bundleApi'

const props = withDefaults(
  defineProps<{
    scope?: BundleScope
  }>(),
  { scope: 'user' }
)

const { t } = useI18n()
const { success, error: showError } = useNotification()

const visible = computed(() => isBundleEnabled())
const loading = ref(false)
const busy = ref(false)
const sections = ref<BundleSection[]>([])
const selectedKinds = ref<string[]>([])
const preview = ref<BundlePreview | null>(null)
const pendingBundle = ref<unknown>(null)
const overwrite = ref(false)
const results = ref<BundleImportResult['results']>([])
const errorText = ref('')

const checklist = computed<BundleChecklistItem[]>(() =>
  (preview.value?.sections ?? []).flatMap((section) => section.items)
)

watch(
  visible,
  (on) => {
    if (on && sections.value.length === 0 && !loading.value) {
      void loadSections()
    }
  },
  { immediate: true }
)

async function loadSections(): Promise<void> {
  loading.value = true
  try {
    sections.value = await bundleApi.sections(props.scope)
    selectedKinds.value = sections.value.map((section) => section.kind)
  } catch {
    sections.value = []
  } finally {
    loading.value = false
  }
}

function sectionLabel(kind: string): string {
  const key = `bundle.kind.${kind}`
  const translated = t(key)
  return translated === key ? kind : translated
}

function checklistLabel(row: BundleChecklistItem): string {
  const key = `bundle.check.${row.code}`
  return t(key, { key: row.itemKey, detail: row.detail ?? '' })
}

async function exportBundle(): Promise<void> {
  busy.value = true
  errorText.value = ''
  try {
    const document = await bundleApi.export(selectedKinds.value, props.scope)
    const blob = new Blob([JSON.stringify(document, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = window.document.createElement('a')
    link.href = url
    link.download = 'synaplan-bundle.json'
    link.click()
    URL.revokeObjectURL(url)
    success(t('bundle.exported'))
  } catch (err) {
    errorText.value = err instanceof Error ? err.message : t('bundle.exportFailed')
    showError(errorText.value)
  } finally {
    busy.value = false
  }
}

async function onFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) {
    return
  }
  errorText.value = ''
  preview.value = null
  results.value = []
  try {
    const text = await file.text()
    const parsed: unknown = JSON.parse(text)
    pendingBundle.value = parsed
    preview.value = await bundleApi.preview(parsed)
  } catch (err) {
    errorText.value = err instanceof Error ? err.message : t('bundle.invalidFile')
    showError(errorText.value)
  }
}

async function importBundle(): Promise<void> {
  if (pendingBundle.value == null) {
    return
  }
  busy.value = true
  errorText.value = ''
  try {
    const imported = await bundleApi.import(
      pendingBundle.value,
      overwrite.value ? 'overwrite' : 'skip'
    )
    results.value = imported.results
    success(t('bundle.imported'))
  } catch (err) {
    errorText.value = err instanceof Error ? err.message : t('bundle.importFailed')
    showError(errorText.value)
  } finally {
    busy.value = false
  }
}
</script>
