<template>
  <div
    v-if="files.length > 0"
    class="flex max-h-72 max-w-full flex-wrap gap-2 overflow-y-auto overscroll-contain"
    :class="[
      files.length > 1 ? 'w-[min(100%,17.5rem)] sm:w-[min(100%,22.5rem)]' : '',
      align === 'end' ? 'justify-end' : '',
    ]"
    data-testid="section-message-media"
  >
    <ComposerMediaCard
      v-for="file in files"
      :key="file.id"
      :name="file.filename"
      :kind="mediaKind(file)"
      :src="messageFileSrc(file, guestSessionId)"
      :removable="false"
      :show-caption="false"
    >
      <template #lightbox>
        <div class="flex items-center justify-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :aria-label="t('files.download')"
            data-testid="btn-message-file-download"
            @click.stop="emit('download', file)"
          >
            <ArrowDownTrayIcon class="h-4 w-4" />
          </button>
          <button
            v-if="canReattach"
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :aria-label="t('chatMessage.fileReattach')"
            data-testid="btn-message-file-reattach"
            @click.stop="emit('reattach', file)"
          >
            <ArrowPathIcon class="h-4 w-4" />
          </button>
        </div>
      </template>
    </ComposerMediaCard>
  </div>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import ComposerMediaCard from '@/components/chat/ComposerMediaCard.vue'
import { attachmentMediaKind } from '@/components/chat/composerAttachment'
import { messageFileSrc } from '@/components/chat/messageAttachmentFiles'
import type { MessageFile } from '@/stores/history'

defineProps<{
  files: MessageFile[]
  align: 'end' | 'start'
  guestSessionId: string | null
  canReattach: boolean
}>()

const emit = defineEmits<{
  download: [file: MessageFile]
  reattach: [file: MessageFile]
}>()

const { t } = useI18n()

const mediaKind = (file: MessageFile): 'image' | 'video' =>
  attachmentMediaKind(file.fileType || '', file.fileMime, file.filename) === 'video'
    ? 'video'
    : 'image'
</script>
