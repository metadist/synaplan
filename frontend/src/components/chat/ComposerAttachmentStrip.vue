<template>
  <div class="mb-3 flex flex-wrap items-start gap-2 max-h-56 overflow-y-auto">
    <QuoteChip v-if="quote" :quote="quote" @remove="emit('clearQuote')" />

    <PastedTextCard
      v-for="block in pastedBlocks"
      :key="block.id"
      :content="block.content"
      @open="emit('openPasted', block.id)"
      @remove="emit('removePasted', block.id)"
    />

    <template v-for="(file, index) in files" :key="attachmentKey(file, index)">
      <ComposerMediaCard
        v-if="composerMediaKind(file)"
        :name="file.filename || file.name || ''"
        :kind="composerMediaKind(file) === 'video' ? 'video' : 'image'"
        :src="attachmentPreviewSrc(file)"
        :processing="file.processing"
        @remove="emit('removeFile', index)"
      />
      <div
        v-else
        class="flex items-center gap-2 px-3 py-2 surface-chip rounded-lg"
        data-testid="chip-chat-file"
      >
        <Icon :icon="fileChipIcon(file.file_type || file.name || '')" class="w-4 h-4" />
        <span class="text-sm txt-secondary">{{ file.filename || file.name }}</span>
        <span v-if="file.processing" class="text-xs txt-muted">(processing...)</span>
        <button
          type="button"
          class="icon-ghost p-0 min-w-0 w-auto h-auto"
          :aria-label="t('files.removeFile')"
          :disabled="file.processing"
          data-testid="btn-remove-chat-file"
          @click="emit('removeFile', index)"
        >
          <XMarkIcon class="w-4 h-4" />
        </button>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import QuoteChip from '@/components/QuoteChip.vue'
import PastedTextCard from '@/components/chat/PastedTextCard.vue'
import ComposerMediaCard from '@/components/chat/ComposerMediaCard.vue'
import type { QuotedReference } from '@/composables/useMessageQuoting'
import type { PastedTextBlock } from '@/utils/pastedContent'
import {
  attachmentKey,
  attachmentPreviewSrc,
  composerMediaKind,
  type UploadedFile,
} from '@/components/chat/composerAttachment'

defineProps<{
  files: UploadedFile[]
  quote: QuotedReference | null
  pastedBlocks: PastedTextBlock[]
}>()

const emit = defineEmits<{
  removeFile: [index: number]
  clearQuote: []
  openPasted: [id: string]
  removePasted: [id: string]
}>()

const { t } = useI18n()

function fileChipIcon(fileType: string): string {
  const ext = fileType.toLowerCase()
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'mdi:image'
  if (['mp4', 'webm', 'mov', 'avi'].includes(ext)) return 'mdi:video'
  if (['mp3', 'wav', 'ogg', 'm4a', 'flac', 'opus'].includes(ext)) return 'mdi:microphone'
  if (['pdf'].includes(ext)) return 'mdi:file-pdf'
  if (['doc', 'docx'].includes(ext)) return 'mdi:file-word'
  if (['xls', 'xlsx'].includes(ext)) return 'mdi:file-excel'
  if (['ppt', 'pptx'].includes(ext)) return 'mdi:file-powerpoint'
  if (['txt'].includes(ext)) return 'mdi:file-document'
  return 'mdi:file'
}
</script>
