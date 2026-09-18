<template>
  <div
    v-if="files.length > 0"
    ref="root"
    class="relative mx-4 mb-2 inline-block"
    data-testid="conversation-files-bar"
    @mouseenter="openPopover"
    @mouseleave="onMouseLeave"
    @keydown.escape="closePopover"
  >
    <!-- Compact trigger: a circular file icon with a count badge. The full
         list used to sit open above the composer and crowded it; now it only
         appears on hover/click. -->
    <button
      type="button"
      class="relative inline-flex items-center justify-center w-9 h-9 rounded-full surface-chip border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
      :aria-label="$t('chat.conversationFiles.count', files.length)"
      :title="$t('chat.conversationFiles.count', files.length)"
      :aria-expanded="open"
      aria-haspopup="dialog"
      data-testid="conversation-files-toggle"
      @click="toggle"
    >
      <Icon icon="mdi:paperclip" class="w-4 h-4" />
      <span
        class="absolute -top-1 -right-1 min-w-[1rem] h-4 px-1 rounded-full bg-[var(--brand)] text-[color:var(--on-brand)] text-[10px] font-semibold inline-flex items-center justify-center"
      >
        {{ files.length }}
      </span>
    </button>

    <!-- Popover: the actual file list, opening upward so it is never clipped by
         the composer at the bottom of the viewport. The outer wrapper carries
         the vertical offset as padding (not margin) so the hover area bridges
         the gap between the trigger and the panel — a margin gap would drop the
         hover and the panel could never be reached without a click. -->
    <div
      v-show="open"
      class="absolute bottom-full left-0 pb-2 z-40"
      data-testid="conversation-files-popover"
    >
      <div
        class="w-72 max-w-[80vw] surface-card border border-light-border/30 dark:border-dark-border/20 rounded-xl shadow-lg p-3"
        role="dialog"
        :aria-label="$t('chat.conversationFiles.title')"
      >
        <p class="text-xs font-medium txt-primary mb-1">{{ $t('chat.conversationFiles.title') }}</p>
        <p class="text-xs txt-secondary mb-2">{{ $t('chat.conversationFiles.hint') }}</p>
        <div class="flex flex-col gap-1.5 max-h-64 overflow-y-auto" role="list">
          <button
            v-for="file in files"
            :key="file.reference"
            type="button"
            class="btn-secondary w-full px-3 py-1.5 rounded-lg text-xs font-medium inline-flex items-center gap-1.5 text-left disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="!canAttach || file.id === null"
            :title="attachTitle(file)"
            :aria-label="attachTitle(file)"
            data-testid="conversation-file-chip"
            role="listitem"
            @click="onAttach(file)"
          >
            <Icon
              :icon="fileIcon(file.fileType || file.category)"
              class="w-3.5 h-3.5 flex-shrink-0"
            />
            <span class="truncate">{{ file.name }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
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

const root = ref<HTMLElement | null>(null)
const open = ref(false)
// A click pins the popover open so it survives the pointer leaving; hover alone
// closes it again. Touch devices have no hover, so the click path is what makes
// the list reachable there.
const pinned = ref(false)

const canAttach = computed(() => props.canAttach !== false)

const openPopover = () => {
  open.value = true
}

const closePopover = () => {
  open.value = false
  pinned.value = false
}

const onMouseLeave = () => {
  if (!pinned.value) {
    open.value = false
  }
}

const toggle = () => {
  pinned.value = !pinned.value
  open.value = pinned.value
}

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
  closePopover()
}

const onDocumentClick = (event: MouseEvent) => {
  if (!open.value) {
    return
  }
  if (root.value && !root.value.contains(event.target as Node)) {
    closePopover()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
})

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
