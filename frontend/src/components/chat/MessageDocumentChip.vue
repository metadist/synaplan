<template>
  <div
    :class="[
      'flex min-w-0 max-w-full cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm',
      tone === 'plain'
        ? 'surface-chip'
        : 'bg-black/10 transition-colors hover:bg-black/20 dark:bg-white/10 dark:hover:bg-white/20',
    ]"
    @click="emit('open', file, $event)"
  >
    <Icon :icon="getFileIcon(file.fileType)" class="h-4 w-4 flex-shrink-0" />
    <span class="min-w-0 flex-1 truncate font-medium">{{ file.filename }}</span>
    <span v-if="file.fileSize" class="flex-shrink-0 whitespace-nowrap text-xs opacity-60">
      {{ formatFileSize(file.fileSize) }}
    </span>
    <button
      type="button"
      class="icon-ghost inline-flex h-7 w-7 items-center justify-center rounded-lg"
      :aria-label="t('files.download')"
      data-testid="btn-message-file-download"
      @click.stop="emit('download', file)"
    >
      <ArrowDownTrayIcon class="h-4 w-4" />
    </button>
    <button
      v-if="canReattach"
      type="button"
      class="icon-ghost inline-flex h-7 w-7 items-center justify-center rounded-lg"
      :aria-label="t('chatMessage.fileReattach')"
      data-testid="btn-message-file-reattach"
      @click.stop="emit('reattach', file)"
    >
      <ArrowPathIcon class="h-4 w-4" />
    </button>
    <FileOfficeActions
      v-if="file.id && showOfficeActions(file)"
      :file-id="file.id"
      :filename="file.filename"
      :guest-session-id="guestSessionId"
      :sibling-file-ids="siblingFileIds"
      @click.stop
      @preview="emit('preview', file)"
    />
  </div>
</template>

<script setup lang="ts">
import { ArrowDownTrayIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import FileOfficeActions from '@/components/files/FileOfficeActions.vue'
import { showOfficeActions } from '@/components/chat/messageAttachmentFiles'
import { formatFileSize, getFileIcon } from '@/utils/messageFileDisplay'
import type { MessageFile } from '@/stores/history'

defineProps<{
  file: MessageFile
  tone: 'plain' | 'bubble'
  guestSessionId: string | null
  siblingFileIds: number[]
  canReattach: boolean
}>()

const emit = defineEmits<{
  download: [file: MessageFile]
  reattach: [file: MessageFile]
  open: [file: MessageFile, event: MouseEvent]
  preview: [file: MessageFile]
}>()

const { t } = useI18n()
</script>
