<template>
  <button
    v-if="canMakeSearchable(file)"
    type="button"
    class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:text-[var(--brand)] transition-colors disabled:opacity-50"
    :title="t('files.describeSortAction')"
    :aria-label="t('files.describeSortAction')"
    :disabled="busy"
    :data-testid="file.source === 'generated' ? 'btn-index-prompt' : 'btn-describe'"
    @click="emit('activate')"
  >
    <Icon
      :icon="busy ? 'mdi:loading' : 'mdi:text-box-search-outline'"
      class="w-4 h-4"
      :class="busy && 'animate-spin'"
    />
  </button>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { FileItem } from '@/services/filesService'
import { extensionOf, skipsExtraction } from '@/services/filePreview'
import { vectorStateOf } from '@/utils/fileDisplayName'

defineProps<{
  file: FileItem
  busy?: boolean
}>()

const emit = defineEmits<{
  activate: []
}>()

const { t } = useI18n()

const canMakeSearchable = (file: FileItem): boolean =>
  vectorStateOf(file) !== 'vectorized' &&
  !skipsExtraction(extensionOf(file.filename) || file.file_type)
</script>
