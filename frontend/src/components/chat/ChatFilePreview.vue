<template>
  <Teleport to="#app">
    <div
      v-if="open && file"
      class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 p-3"
      data-testid="chat-file-preview"
      @click.self="close"
    >
      <div
        ref="panelRef"
        class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl surface-card shadow-2xl"
        role="dialog"
        aria-modal="true"
        :aria-label="file.filename"
        tabindex="-1"
      >
        <div class="flex items-start justify-between gap-3 border-b border-light-border/20 p-4">
          <div class="min-w-0">
            <h2 class="truncate text-base font-semibold txt-primary">{{ file.filename }}</h2>
            <p class="mt-1 text-sm txt-secondary">
              {{ metaLine }}
            </p>
          </div>
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-chat-file-preview-close"
            @click="close"
          >
            <XMarkIcon class="h-4 w-4" aria-hidden="true" />
            {{ t('common.close') }}
          </button>
        </div>
        <div class="min-h-0 flex-1 overflow-auto p-4">
          <img
            v-if="kind === 'image' && imageSrc"
            :src="imageSrc"
            :alt="file.filename"
            class="mx-auto max-h-[60vh] max-w-full"
          />
          <iframe
            v-else-if="showPdf"
            :src="pdfSrc || undefined"
            class="h-[60vh] w-full bg-[var(--bg-chip)]"
            title="PDF"
          />
          <pre
            v-else-if="csvTable"
            class="overflow-auto text-sm txt-primary"
          ><table class="w-full border-collapse text-left"><tbody>
            <tr v-for="(row, index) in csvTable" :key="index">
              <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="border border-light-border/30 px-2 py-1">{{ cell }}</td>
            </tr>
          </tbody></table></pre>
          <div
            v-else-if="textBody"
            class="whitespace-pre-wrap text-sm txt-primary"
            data-testid="chat-file-preview-text"
          >
            {{ textBody }}
          </div>
          <p v-else-if="loading" class="text-sm txt-secondary">
            {{ t('chatMessage.filePreviewLoading') }}
          </p>
          <p v-else class="text-sm txt-secondary">{{ t('chatMessage.filePreviewEmpty') }}</p>
        </div>
        <div class="flex flex-wrap gap-2 border-t border-light-border/20 p-4">
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-chat-file-download"
            @click="emit('download')"
          >
            <ArrowDownTrayIcon class="h-4 w-4" aria-hidden="true" />
            {{ t('files.download') }}
          </button>
          <button
            v-if="canReattach"
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-chat-file-reattach"
            @click="emit('reattach')"
          >
            {{ t('chatMessage.fileReattach') }}
          </button>
          <router-link
            to="/files"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-chat-file-library"
          >
            {{ t('chatMessage.fileOpenLibrary') }}
          </router-link>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownTrayIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { isOfficeConvertEnabled } from '@/composables/useOfficeConvertFeature'
import { useMediaSrc } from '@/services/api/mediaAuth'
import { extensionOf, kindFromExtension } from '@/services/filePreview'
import * as filesService from '@/services/filesService'

const props = defineProps<{
  open: boolean
  file: { id: number; filename: string; fileSize?: number; sharedBy?: string | null } | null
  canReattach?: boolean
  returnFocus?: HTMLElement | null
}>()

const emit = defineEmits<{ close: []; download: []; reattach: [] }>()
const { t } = useI18n()
const { mediaSrc } = useMediaSrc()
const panelRef = ref<HTMLElement | null>(null)
const textBody = ref('')
const loading = ref(false)
const searchable = ref(false)

const kind = computed(() =>
  props.file ? kindFromExtension(extensionOf(props.file.filename)) : 'unknown'
)
const showPdf = computed(
  () => kind.value === 'pdf' || (kind.value === 'document' && isOfficeConvertEnabled())
)
const pdfSrc = computed(() =>
  props.file && showPdf.value ? mediaSrc(filesService.exportUrl(props.file.id, 'pdf', true)) : ''
)
const imageSrc = computed(() =>
  props.file && kind.value === 'image' ? mediaSrc(`/api/v1/files/${props.file.id}/download`) : ''
)

const csvTable = computed(() => {
  if (!props.file?.filename.toLowerCase().endsWith('.csv')) {
    return null
  }
  if (!textBody.value) return null
  return textBody.value
    .split('\n')
    .filter((line) => line.trim() !== '')
    .slice(0, 40)
    .map((line) => line.split(',').slice(0, 12))
})

const metaLine = computed(() => {
  const parts = []
  if (props.file?.fileSize) parts.push(String(props.file.fileSize))
  parts.push(
    searchable.value ? t('chatMessage.fileSearchable') : t('chatMessage.fileNotSearchable')
  )
  if (props.file?.sharedBy) parts.push(props.file.sharedBy)
  return parts.join(' · ')
})

function close(): void {
  emit('close')
  nextTick(() => props.returnFocus?.focus())
}

function onKey(event: KeyboardEvent): void {
  if (event.key !== 'Escape' || !props.open) return
  event.preventDefault()
  close()
}

watch(
  () => [props.open, props.file?.id] as const,
  async ([open, id]) => {
    textBody.value = ''
    searchable.value = false
    if (!open || !id || showPdf.value || kind.value === 'image') return
    loading.value = true
    try {
      const content = await filesService.getFileContent(id)
      textBody.value = content.extracted_text || ''
      searchable.value = content.status === 'vectorized' || content.extracted_text.length > 0
    } catch {
      textBody.value = ''
    } finally {
      loading.value = false
    }
  }
)

watch(
  () => props.open,
  (open) => {
    if (open) {
      window.addEventListener('keydown', onKey)
      nextTick(() => panelRef.value?.focus())
      return
    }
    window.removeEventListener('keydown', onKey)
  }
)

onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>
