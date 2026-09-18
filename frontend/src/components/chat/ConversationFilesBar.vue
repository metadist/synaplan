<template>
  <div v-if="files.length > 0" class="mx-4 mb-2" data-testid="conversation-files-bar">
    <div class="flex items-baseline justify-between gap-3 mb-1.5">
      <p class="text-xs font-medium txt-primary">{{ $t('chat.conversationFiles.title') }}</p>
      <p class="text-xs txt-secondary min-w-0 truncate">
        {{ $t('chat.conversationFiles.hint') }}
      </p>
    </div>
    <div class="flex flex-wrap gap-2" role="list">
      <button
        v-for="file in files"
        :key="file.reference"
        type="button"
        class="btn-secondary px-3 py-1.5 rounded-lg text-xs font-medium inline-flex items-center gap-1.5 max-w-full"
        :disabled="!canAttach || file.id === null"
        :title="attachTitle(file)"
        :aria-label="attachTitle(file)"
        data-testid="conversation-file-chip"
        role="listitem"
        @click="onAttach(file)"
      >
        <Icon :icon="fileIcon(file.fileType || file.category)" class="w-3.5 h-3.5 flex-shrink-0" />
        <span class="truncate">{{ file.name }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'

import type { ConversationFileRow } from '@/composables/useConversationFiles'

const props = defineProps<{
  files: ConversationFileRow[]
  canAttach?: boolean
}>()

const emit = defineEmits<{
  attach: [file: ConversationFileRow]
}>()

const { t } = useI18n()

const canAttach = computed(() => props.canAttach !== false)

const attachTitle = (file: ConversationFileRow): string => {
  if (!canAttach.value || file.id === null) {
    return file.name
  }

  return t('chat.conversationFiles.attach', { name: file.name })
}

const onAttach = (file: ConversationFileRow) => {
  if (!canAttach.value || file.id === null) {
    return
  }
  emit('attach', file)
}

const fileIcon = (fileType: string): string => {
  const ext = fileType.toLowerCase()
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'image'].includes(ext)) return 'mdi:image'
  if (['mp4', 'webm', 'mov', 'avi', 'video'].includes(ext)) return 'mdi:video'
  if (['mp3', 'wav', 'ogg', 'm4a', 'flac', 'opus', 'audio'].includes(ext)) return 'mdi:microphone'
  if (ext === 'pdf') return 'mdi:file-pdf-box'
  if (['doc', 'docx'].includes(ext)) return 'mdi:file-word-box'
  if (['xls', 'xlsx'].includes(ext)) return 'mdi:file-excel-box'
  if (['ppt', 'pptx'].includes(ext)) return 'mdi:file-powerpoint-box'
  return 'mdi:file-document-outline'
}
</script>
