<template>
  <div class="flex max-w-full flex-col items-end gap-2" data-testid="section-user-attachments">
    <MessageMediaStrip
      :files="mediaFiles"
      align="end"
      :guest-session-id="guestSessionId"
      :can-reattach="canReattach"
      @download="emit('download', $event)"
      @reattach="emit('reattach', $event)"
    />
    <div v-if="documentFiles.length > 0" class="flex max-w-full flex-wrap justify-end gap-2">
      <MessageDocumentChip
        v-for="file in visibleDocuments"
        :key="file.id"
        :file="file"
        tone="plain"
        :guest-session-id="guestSessionId"
        :sibling-file-ids="siblingFileIds"
        :can-reattach="canReattach"
        @download="emit('download', $event)"
        @reattach="emit('reattach', $event)"
        @open="(file, event) => emit('open', file, event)"
        @preview="emit('preview', $event)"
      />
      <button
        v-if="documentFiles.length > 3"
        type="button"
        class="flex items-center gap-1 rounded-xl bg-black/5 px-3 py-2 text-sm font-medium txt-secondary hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10"
        data-testid="btn-message-badges-toggle"
        @click="showAll = !showAll"
      >
        <span v-if="!showAll">+{{ documentFiles.length - 2 }}</span>
        <Icon :icon="showAll ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import MessageDocumentChip from '@/components/chat/MessageDocumentChip.vue'
import MessageMediaStrip from '@/components/chat/MessageMediaStrip.vue'
import { isMessageMedia, officeSiblingIds } from '@/components/chat/messageAttachmentFiles'
import type { MessageFile } from '@/stores/history'

const props = defineProps<{
  files: MessageFile[]
  guestSessionId: string | null
  canReattach: boolean
}>()

const emit = defineEmits<{
  download: [file: MessageFile]
  reattach: [file: MessageFile]
  open: [file: MessageFile, event: MouseEvent]
  preview: [file: MessageFile]
}>()

const showAll = ref(false)

const mediaFiles = computed(() => props.files.filter((file) => isMessageMedia(file)))
const documentFiles = computed(() => props.files.filter((file) => !isMessageMedia(file)))
const siblingFileIds = computed(() => officeSiblingIds(props.files))
const visibleDocuments = computed(() =>
  showAll.value || documentFiles.value.length <= 3
    ? documentFiles.value
    : documentFiles.value.slice(0, 2)
)
</script>
