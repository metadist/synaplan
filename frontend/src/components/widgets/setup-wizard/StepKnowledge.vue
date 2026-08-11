<template>
  <div class="space-y-4">
    <p class="text-sm txt-secondary">
      {{ $t('widgets.createWizard.knowledge.intro') }}
    </p>

    <!-- Actions -->
    <div class="flex flex-col sm:flex-row gap-3">
      <label
        class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed rounded-lg cursor-pointer border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5 transition-colors"
      >
        <Icon icon="heroicons:cloud-arrow-up" class="w-5 h-5 txt-secondary" />
        <span class="text-sm font-medium txt-brand">
          {{ $t('widgets.createWizard.knowledge.uploadLabel') }}
        </span>
        <input
          type="file"
          class="hidden"
          accept=".pdf,.doc,.docx,.txt,.md,.csv,.json"
          multiple
          data-testid="input-knowledge-files"
          @change="handleFilesSelected"
        />
      </label>

      <button
        type="button"
        class="flex-1 flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed rounded-lg border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50 hover:bg-[var(--brand)]/5 transition-colors"
        data-testid="btn-pick-existing-files"
        @click="showFilePicker = true"
      >
        <Icon icon="heroicons:folder-open" class="w-5 h-5 txt-secondary" />
        <span class="text-sm font-medium txt-brand">
          {{ $t('widgets.createWizard.knowledge.pickLabel') }}
        </span>
      </button>
    </div>
    <p class="text-xs txt-secondary">
      {{ $t('widgets.createWizard.knowledge.uploadHint') }}
    </p>

    <!-- Selected sources -->
    <div v-if="uploads.length > 0 || linkedFiles.length > 0" class="space-y-2">
      <div
        v-for="(file, index) in uploads"
        :key="'upload-' + index"
        class="flex items-center gap-3 p-2.5 rounded-lg surface-chip"
      >
        <Icon icon="heroicons:document-arrow-up" class="w-5 h-5 txt-secondary flex-shrink-0" />
        <span class="flex-1 text-sm txt-primary truncate">{{ file.name }}</span>
        <button
          type="button"
          class="p-1.5 rounded-lg hover:bg-red-500/10 transition-colors"
          :aria-label="$t('common.delete')"
          @click="removeUpload(index)"
        >
          <Icon icon="heroicons:x-mark" class="w-4 h-4 text-red-500" />
        </button>
      </div>
      <div
        v-for="(file, index) in linkedFiles"
        :key="'linked-' + file.messageId"
        class="flex items-center gap-3 p-2.5 rounded-lg surface-chip"
      >
        <Icon icon="heroicons:document" class="w-5 h-5 txt-secondary flex-shrink-0" />
        <span class="flex-1 text-sm txt-primary truncate">{{ file.fileName }}</span>
        <button
          type="button"
          class="p-1.5 rounded-lg hover:bg-red-500/10 transition-colors"
          :aria-label="$t('common.delete')"
          @click="removeLinkedFile(index)"
        >
          <Icon icon="heroicons:x-mark" class="w-4 h-4 text-red-500" />
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="text-center py-5 surface-chip rounded-lg">
      <Icon
        icon="heroicons:document-text"
        class="w-8 h-8 txt-secondary mx-auto mb-1.5 opacity-50"
      />
      <p class="text-sm txt-secondary">
        {{ $t('widgets.createWizard.knowledge.empty') }}
      </p>
    </div>

    <FilePicker
      :is-open="showFilePicker"
      :exclude-message-ids="linkedFiles.map((f) => f.messageId)"
      @close="showFilePicker = false"
      @select="handlePickerSelect"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import FilePicker from '@/components/widgets/FilePicker.vue'
import type { AvailableFile } from '@/services/api/promptsApi'

export interface LinkedFileRef {
  messageId: number
  fileName: string
}

const uploads = defineModel<File[]>('uploads', { required: true })
const linkedFiles = defineModel<LinkedFileRef[]>('linkedFiles', { required: true })

const showFilePicker = ref(false)

const handleFilesSelected = (event: Event) => {
  const input = event.target as HTMLInputElement
  if (!input.files || input.files.length === 0) return
  uploads.value = [...uploads.value, ...Array.from(input.files)]
  input.value = ''
}

const removeUpload = (index: number) => {
  uploads.value = uploads.value.filter((_, i) => i !== index)
}

const removeLinkedFile = (index: number) => {
  linkedFiles.value = linkedFiles.value.filter((_, i) => i !== index)
}

const handlePickerSelect = (files: AvailableFile[]) => {
  const additions = files
    .filter((f) => !linkedFiles.value.some((existing) => existing.messageId === f.messageId))
    .map((f) => ({ messageId: f.messageId, fileName: f.fileName }))
  linkedFiles.value = [...linkedFiles.value, ...additions]
}
</script>
