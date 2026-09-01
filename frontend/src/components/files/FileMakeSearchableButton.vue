<template>
  <button
    v-if="vectorStateOf(file) !== 'vectorized'"
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
import { vectorStateOf } from '@/utils/fileDisplayName'

defineProps<{
  file: FileItem
  busy?: boolean
}>()

const emit = defineEmits<{
  activate: []
}>()

const { t } = useI18n()
</script>
