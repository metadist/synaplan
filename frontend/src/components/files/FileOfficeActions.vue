<template>
  <div class="relative" data-testid="file-office-actions">
    <button
      type="button"
      class="shrink-0 px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 txt-secondary hover:txt-primary transition-colors text-[11px] flex items-center gap-1"
      :aria-expanded="open"
      :aria-label="$t('files.download')"
      data-testid="file-office-actions-trigger"
      @click.stop="open = !open"
    >
      <Icon icon="mdi:dots-vertical" class="w-3.5 h-3.5" />
    </button>
    <div
      v-if="open"
      class="absolute right-0 bottom-full mb-1 z-30 w-52 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5"
      data-testid="file-office-actions-menu"
      @click.stop
    >
      <button
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-download"
        @click="onDownload"
      >
        <Icon icon="mdi:download" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.download') }}</span>
      </button>
      <button
        v-if="canExportPdf"
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-pdf"
        @click="onExportPdf"
      >
        <Icon icon="mdi:file-pdf-box" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.downloadPdf') }}</span>
      </button>
      <button
        v-if="showPreview && canPreview"
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-preview"
        @click="onPreview"
      >
        <Icon icon="mdi:eye-outline" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.preview.open') }}</span>
      </button>
      <button
        v-if="canCombine"
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-combine"
        @click="onCombine"
      >
        <Icon icon="mdi:file-multiple-outline" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.combinePdf') }}</span>
      </button>
      <button
        v-if="canCombineOffice"
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-combine-office"
        @click="onCombineOffice"
      >
        <Icon icon="mdi:file-multiple" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.combineOffice', { format: officeFormatLabel }) }}</span>
      </button>
      <button
        v-if="canShowRevisions"
        type="button"
        class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
        data-testid="file-office-actions-revisions"
        @click="onRevisions"
      >
        <Icon icon="mdi:history" class="w-4 h-4 shrink-0" />
        <span>{{ $t('files.revisions') }}</span>
      </button>
    </div>
    <FileRevisionsPanel v-if="showRevisions" :file-id="fileId" @close="showRevisions = false" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { isOfficeConvertEnabled } from '@/composables/useOfficeConvertFeature'
import { isDocumentToolsEnabled } from '@/composables/useDocumentToolsFeature'
import { kindFromExtension, extensionOf } from '@/services/filePreview'
import * as filesService from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import FileRevisionsPanel from '@/components/files/FileRevisionsPanel.vue'

const props = withDefaults(
  defineProps<{
    fileId: number
    filename: string
    guestSessionId?: string | null
    showPreview?: boolean
    siblingFileIds?: number[]
  }>(),
  { guestSessionId: null, showPreview: true, siblingFileIds: () => [] }
)

const emit = defineEmits<{ preview: [] }>()

const { t } = useI18n()
const { error: showError, success: showSuccess } = useNotification()
const open = ref(false)
const showRevisions = ref(false)

const kind = computed(() => kindFromExtension(extensionOf(props.filename)))
const canExportPdf = computed(() => isOfficeConvertEnabled() && 'document' === kind.value)
const canPreview = computed(
  () => 'pdf' === kind.value || (isOfficeConvertEnabled() && 'document' === kind.value)
)
const combineIds = computed(() => [
  props.fileId,
  ...props.siblingFileIds.filter((id) => id !== props.fileId),
])
const canCombine = computed(
  () =>
    !props.guestSessionId &&
    combineIds.value.length >= 2 &&
    ('pdf' === kind.value || 'document' === kind.value)
)
const officeExt = computed(() => extensionOf(props.filename).toLowerCase())
const officeFormat = computed((): 'docx' | 'xlsx' | 'pptx' | null => {
  if (['doc', 'docx', 'odt', 'rtf', 'pages'].includes(officeExt.value)) return 'docx'
  if (['xls', 'xlsx', 'ods', 'csv', 'numbers'].includes(officeExt.value)) return 'xlsx'
  if (['ppt', 'pptx', 'odp', 'key'].includes(officeExt.value)) return 'pptx'
  return null
})
const officeFormatLabel = computed(() => (officeFormat.value ?? 'docx').toUpperCase())
const canCombineOffice = computed(
  () =>
    isDocumentToolsEnabled() &&
    !props.guestSessionId &&
    combineIds.value.length >= 2 &&
    null !== officeFormat.value
)
const canShowRevisions = computed(
  () => isDocumentToolsEnabled() && !props.guestSessionId && null !== officeFormat.value
)

const close = () => {
  open.value = false
}

const onDownload = async () => {
  close()
  try {
    if (props.guestSessionId) {
      await filesService.downloadGuestFile(props.guestSessionId, props.fileId, props.filename)
      return
    }
    await filesService.downloadFile(props.fileId, props.filename)
  } catch {
    showError(t('files.downloadFailed'))
  }
}

const pdfName = () => {
  const base = props.filename.replace(/\.[^.]+$/, '')
  return `${base || 'document'}.pdf`
}

const onExportPdf = async () => {
  close()
  try {
    if (props.guestSessionId) {
      await filesService.exportGuestFile(props.guestSessionId, props.fileId, 'pdf', pdfName())
      return
    }
    await filesService.exportFile(props.fileId, 'pdf', pdfName())
  } catch {
    showError(t('files.exportFailed'))
  }
}

const onPreview = () => {
  close()
  emit('preview')
}

const onCombine = async () => {
  close()
  try {
    await filesService.combineFiles(combineIds.value)
    showSuccess(t('files.combined'))
  } catch (err) {
    const reason = err instanceof ApiError ? err.details?.reason : undefined
    if ('engine_required' === reason) {
      showError(t('files.combineEngineRequired'))
      return
    }
    if ('too_many' === reason) {
      showError(t('files.combineTooMany', { count: 20 }))
      return
    }
    showError(t('files.combineFailed'))
  }
}

const onCombineOffice = async () => {
  close()
  const format = officeFormat.value
  if (!format) return
  try {
    await filesService.combineFiles(combineIds.value, undefined, format)
    showSuccess(t('files.combinedOffice'))
  } catch (err) {
    const reason = err instanceof ApiError ? err.details?.reason : undefined
    if ('too_many' === reason) {
      showError(t('files.combineTooMany', { count: 20 }))
      return
    }
    showError(t('files.combineFailed'))
  }
}

const onRevisions = () => {
  close()
  showRevisions.value = true
}

const onDocClick = () => close()
onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => document.removeEventListener('click', onDocClick))
</script>
