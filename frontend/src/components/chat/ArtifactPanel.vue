<template>
  <Teleport to="#app">
    <div
      class="fixed inset-0 z-[80] flex items-stretch justify-end bg-black/50"
      data-testid="artifact-panel"
      @click.self="emit('close')"
    >
      <div
        class="flex h-full w-full flex-col surface-card shadow-2xl md:max-w-3xl"
        :class="fullscreen ? 'max-w-none' : ''"
        role="dialog"
        aria-modal="true"
        :aria-label="t('chatMessage.previewArtifact')"
      >
        <div class="flex items-center justify-between gap-2 border-b border-light-border/20 p-3">
          <h2 class="text-sm font-semibold txt-primary">{{ t('chatMessage.previewArtifact') }}</h2>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-artifact-copy"
              @click="copy"
            >
              {{ t('chatMessage.artifactCopy') }}
            </button>
            <button
              type="button"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-artifact-download"
              @click="download"
            >
              <ArrowDownTrayIcon class="h-4 w-4" aria-hidden="true" />
              {{ t('chatMessage.artifactDownload') }}
            </button>
            <button
              type="button"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-artifact-fullscreen"
              @click="fullscreen = !fullscreen"
            >
              {{ t('chatMessage.artifactFullscreen') }}
            </button>
            <button
              type="button"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-artifact-close"
              @click="emit('close')"
            >
              <XMarkIcon class="h-4 w-4" aria-hidden="true" />
              {{ t('chatMessage.artifactClose') }}
            </button>
          </div>
        </div>
        <iframe
          class="min-h-0 w-full flex-1 bg-white"
          sandbox="allow-scripts"
          :srcdoc="srcDoc"
          :title="t('chatMessage.previewArtifact')"
          data-testid="artifact-frame"
        />
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownTrayIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { artifactSrcDoc, type ChatArtifact } from '@/utils/chatArtifacts'

const props = defineProps<{
  artifact: ChatArtifact
  filename: string
}>()

const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const fullscreen = ref(false)
const srcDoc = computed(() => artifactSrcDoc(props.artifact))

const onKey = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
  }
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

async function copy(): Promise<void> {
  await navigator.clipboard.writeText(props.artifact.code)
}

function download(): void {
  const blob = new Blob([props.artifact.code], {
    type: props.artifact.language === 'svg' ? 'image/svg+xml' : 'text/html',
  })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${props.filename || 'preview'}.${props.artifact.language === 'svg' ? 'svg' : 'html'}`
  link.click()
  URL.revokeObjectURL(url)
}
</script>
