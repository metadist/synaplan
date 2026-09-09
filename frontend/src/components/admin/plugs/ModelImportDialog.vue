<template>
  <div
    class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/60"
    data-testid="model-import-dialog"
    @click.self="close"
  >
    <div class="surface-card rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col shadow-2xl">
      <div
        class="flex items-center justify-between p-5 border-b border-light-border/30 dark:border-dark-border/20"
      >
        <div>
          <h2 class="text-lg font-semibold txt-primary">{{ $t('aiInfra.modelImport.title') }}</h2>
          <p class="text-sm txt-secondary mt-0.5">{{ label }}</p>
        </div>
        <button
          type="button"
          class="p-1 rounded txt-muted hover:txt-primary transition-colors"
          :aria-label="$t('common.close')"
          data-testid="model-import-close"
          @click="close"
        >
          <Icon icon="mdi:close" class="w-5 h-5" />
        </button>
      </div>

      <div class="p-5 overflow-y-auto scroll-thin">
        <div v-if="loading" class="text-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
        </div>

        <div
          v-else-if="!endpointOk"
          class="surface-card rounded-lg p-6 text-center"
          data-testid="model-import-unreachable"
        >
          <Icon icon="mdi:cloud-alert" class="w-8 h-8 mx-auto text-[var(--status-warning)]" />
          <p class="txt-secondary mt-3">{{ error || $t('aiInfra.modelImport.unreachable') }}</p>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
            @click="loadPreview"
          >
            {{ $t('common.retry') }}
          </button>
        </div>

        <div v-else-if="rows.length === 0" class="text-sm txt-secondary py-8 text-center">
          {{ $t('aiInfra.modelImport.empty') }}
        </div>

        <template v-else>
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <label class="inline-flex items-center gap-2 text-sm txt-primary">
              <input
                type="checkbox"
                :checked="allNewSelected"
                data-testid="model-import-select-all"
                @change="toggleAllNew(($event.target as HTMLInputElement).checked)"
              />
              {{ $t('aiInfra.modelImport.selectAllNew') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm txt-primary">
              <input
                v-model="probe"
                type="checkbox"
                data-testid="model-import-probe"
                @change="loadPreview"
              />
              {{ $t('aiInfra.modelImport.probeLabel') }}
            </label>
          </div>

          <div class="overflow-x-auto scroll-thin">
            <table class="w-full min-w-[640px] text-sm">
              <thead>
                <tr class="border-b-2 border-light-border/30 dark:border-dark-border/20 text-left">
                  <th class="py-2 px-2 w-8"></th>
                  <th class="py-2 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">
                    {{ $t('aiInfra.modelImport.colModel') }}
                  </th>
                  <th class="py-2 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">
                    {{ $t('aiInfra.modelImport.colTags') }}
                  </th>
                  <th class="py-2 px-2 txt-secondary text-xs font-semibold uppercase tracking-wide">
                    {{ $t('aiInfra.modelImport.colStatus') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in rows"
                  :key="row.providerId"
                  class="border-b border-light-border/10 dark:border-dark-border/10"
                  :data-testid="`model-import-row-${row.providerId}`"
                >
                  <td class="py-2 px-2 align-top">
                    <input
                      v-model="row.selected"
                      type="checkbox"
                      class="mt-1"
                      :data-testid="`model-import-select-${row.providerId}`"
                    />
                  </td>
                  <td class="py-2 px-2 align-top">
                    <div class="txt-primary font-medium">{{ row.name }}</div>
                    <div class="txt-secondary text-xs break-all">{{ row.providerId }}</div>
                    <div v-if="row.sizeBytes || row.family" class="txt-muted text-xs mt-0.5">
                      <span v-if="row.family">{{ row.family }}</span>
                      <span v-if="row.sizeBytes"> · {{ formatSize(row.sizeBytes) }}</span>
                    </div>
                  </td>
                  <td class="py-2 px-2 align-top">
                    <input
                      v-model="row.tagsText"
                      type="text"
                      class="w-full px-3 py-1.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                      :data-testid="`model-import-tags-${row.providerId}`"
                    />
                    <div
                      v-if="row.probe"
                      class="txt-muted text-xs mt-1"
                      :data-testid="`model-import-probe-${row.providerId}`"
                    >
                      chat: {{ row.probe.chat }} · embeddings: {{ row.probe.embeddings }}
                    </div>
                  </td>
                  <td class="py-2 px-2 align-top">
                    <span v-if="row.exists" class="pill text-[10px] px-1.5 py-0.5 txt-secondary">{{
                      $t('aiInfra.modelImport.exists')
                    }}</span>
                    <span v-else class="pill text-[10px] px-1.5 py-0.5 text-[var(--brand)]">{{
                      $t('aiInfra.modelImport.new')
                    }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="probeCostNote" class="text-xs txt-muted mt-2">{{ probeCostNote }}</p>
          <p class="text-xs txt-muted mt-2">
            {{ $t('aiInfra.modelImport.tagsHint', { tags: allowedTags.join(', ') }) }}
          </p>
        </template>
      </div>

      <div
        class="flex items-center justify-end gap-2 p-5 border-t border-light-border/30 dark:border-dark-border/20"
      >
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          @click="close"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="applying || selectedCount === 0"
          data-testid="model-import-apply"
          @click="apply"
        >
          {{
            applying
              ? $t('aiInfra.modelImport.applying')
              : $t('aiInfra.modelImport.apply', { count: selectedCount })
          }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { adminModelsApi, type ModelImportPreviewRow } from '@/services/api/adminModelsApi'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{ source: string; label: string }>()
const emit = defineEmits<{ close: []; applied: [] }>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

// Kept in sync with ModelImportApplier::ALLOWED_TAGS on the backend.
const allowedTags = [
  'chat',
  'vectorize',
  'pic2text',
  'rerank',
  'sound2text',
  'text2sound',
  'text2pic',
  'text2vid',
  'analyze',
]

interface Row extends ModelImportPreviewRow {
  selected: boolean
  tagsText: string
}

const loading = ref(true)
const applying = ref(false)
const endpointOk = ref(true)
const error = ref<string | null>(null)
const probe = ref(false)
const probeCostNote = ref<string | null>(null)
const rows = ref<Row[]>([])

const selectedCount = computed(() => rows.value.filter((r) => r.selected).length)
const allNewSelected = computed(() => {
  const newRows = rows.value.filter((r) => !r.exists)
  return newRows.length > 0 && newRows.every((r) => r.selected)
})

onMounted(loadPreview)

async function loadPreview(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const result = await adminModelsApi.importEndpointPreview(props.source, probe.value)
    endpointOk.value = result.endpointOk
    error.value = result.error
    probeCostNote.value = result.probeCostNote
    rows.value = result.rows.map((r) => ({
      ...r,
      selected: !r.exists,
      tagsText: r.guessedTags.join(', '),
    }))
  } catch (err) {
    endpointOk.value = false
    error.value = err instanceof Error ? err.message : t('aiInfra.modelImport.unreachable')
  } finally {
    loading.value = false
  }
}

function toggleAllNew(checked: boolean): void {
  for (const row of rows.value) {
    if (!row.exists) row.selected = checked
  }
}

function parseTags(text: string): string[] {
  const seen = new Set<string>()
  for (const raw of text.split(',')) {
    const tag = raw.trim().toLowerCase()
    if (tag && allowedTags.includes(tag)) seen.add(tag)
  }
  return [...seen]
}

async function apply(): Promise<void> {
  const selected = rows.value
    .filter((r) => r.selected)
    .map((r) => ({ providerId: r.providerId, name: r.name, tags: parseTags(r.tagsText) }))
    .filter((r) => r.tags.length > 0)

  if (selected.length === 0) {
    showError(t('aiInfra.modelImport.noValidTags'))
    return
  }

  applying.value = true
  try {
    const result = await adminModelsApi.importEndpointApply(props.source, selected)
    success(t('aiInfra.modelImport.applied', { created: result.created, skipped: result.skipped }))
    emit('applied')
    emit('close')
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.modelImport.applyFailed'))
  } finally {
    applying.value = false
  }
}

function formatSize(bytes: number): string {
  if (bytes >= 1_000_000_000) return `${(bytes / 1_000_000_000).toFixed(1)} GB`
  if (bytes >= 1_000_000) return `${Math.round(bytes / 1_000_000)} MB`
  return `${Math.round(bytes / 1000)} KB`
}

function close(): void {
  emit('close')
}
</script>
