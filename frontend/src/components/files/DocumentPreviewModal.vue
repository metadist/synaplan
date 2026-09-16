<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="open && file"
        class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
        data-testid="document-preview-modal"
        @click.self="close"
      >
        <div
          class="modal-panel surface-card max-w-5xl w-full h-[85vh] flex flex-col rounded-xl shadow-2xl overflow-hidden"
          role="dialog"
          aria-modal="true"
          :aria-label="file.filename"
          @click.stop
        >
          <div
            class="flex items-center justify-between gap-3 p-4 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <h2 class="text-lg font-semibold txt-primary truncate min-w-0">{{ file.filename }}</h2>
            <div class="flex items-center gap-2 shrink-0">
              <button
                type="button"
                class="btn-secondary text-xs px-3 py-1.5"
                data-testid="document-preview-download"
                @click="download"
              >
                {{ $t('files.download') }}
              </button>
              <button
                v-if="canExportPdf"
                type="button"
                class="btn-secondary text-xs px-3 py-1.5"
                data-testid="document-preview-download-pdf"
                @click="downloadPdf"
              >
                {{ $t('files.downloadPdf') }}
              </button>
              <button
                type="button"
                class="p-2 rounded-lg icon-ghost"
                :aria-label="$t('common.close')"
                data-testid="document-preview-close"
                @click="close"
              >
                <Icon icon="mdi:close" class="w-5 h-5" />
              </button>
            </div>
          </div>
          <iframe
            v-if="iframeSrc"
            :src="iframeSrc"
            class="flex-1 w-full bg-[var(--bg-chip)]"
            data-testid="document-preview-frame"
            title="PDF"
          />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { isOfficeConvertEnabled } from '@/composables/useOfficeConvertFeature'
import { useNotification } from '@/composables/useNotification'
import { useMediaSrc } from '@/services/api/mediaAuth'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { kindFromExtension, extensionOf } from '@/services/filePreview'
import * as filesService from '@/services/filesService'

export type PreviewFile = { id: number; filename: string }

const props = withDefaults(
  defineProps<{
    open: boolean
    file: PreviewFile | null
    guestSessionId?: string | null
  }>(),
  { guestSessionId: null }
)

const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { error: showError } = useNotification()
const { mediaSrc } = useMediaSrc()

const kind = computed(() =>
  props.file ? kindFromExtension(extensionOf(props.file.filename)) : 'unknown'
)
const canExportPdf = computed(() => isOfficeConvertEnabled() && 'document' === kind.value)

const iframeSrc = computed(() => {
  if (!props.file) return null
  return mediaSrc(filesService.exportUrl(props.file.id, 'pdf', true))
})

const close = () => emit('close')

const pdfName = (): string => {
  const base = (props.file?.filename ?? 'document').replace(/\.[^.]+$/, '')
  return `${base || 'document'}.pdf`
}

const download = async () => {
  if (!props.file) return
  try {
    if (props.guestSessionId) {
      await filesService.downloadGuestFile(props.guestSessionId, props.file.id, props.file.filename)
      return
    }
    await filesService.downloadFile(props.file.id, props.file.filename)
  } catch {
    showError(t('files.downloadFailed'))
  }
}

const downloadPdf = async () => {
  if (!props.file) return
  try {
    if (props.guestSessionId) {
      await filesService.exportGuestFile(props.guestSessionId, props.file.id, 'pdf', pdfName())
      return
    }
    await filesService.exportFile(props.file.id, 'pdf', pdfName())
  } catch {
    showError(t('files.exportFailed'))
  }
}

const onKey = (e: KeyboardEvent) => {
  if ('Escape' === e.key && props.open) close()
}

watch(
  () => props.open,
  async (isOpen) => {
    // MOBILE-APP SEAM: iOS/Android WebViews do not render PDFs in iframes
    // reliably. Fall back to Download as PDF instead of a blank preview.
    if (isOpen && isNativeApp() && props.file) {
      await downloadPdf()
      close()
    }
  }
)

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.15s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
