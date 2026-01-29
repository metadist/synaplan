<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        data-testid="modal-file-picker"
        @click.self="close"
      >
        <div
          class="surface-elevated w-full max-w-xl p-6 m-4 max-h-[80vh] flex flex-col"
          data-testid="file-picker-content"
        >
          <!-- Header -->
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold txt-primary">{{ $t('widgets.filePicker.title') }}</h2>
            <button
              class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary"
              data-testid="btn-file-picker-close"
              @click="close"
            >
              <Icon icon="heroicons:x-mark" class="w-5 h-5" />
            </button>
          </div>

          <!-- Search -->
          <div class="mb-4">
            <div class="relative">
              <Icon
                icon="heroicons:magnifying-glass"
                class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary"
              />
              <input
                v-model="searchQuery"
                type="text"
                :placeholder="$t('widgets.filePicker.searchPlaceholder')"
                class="w-full pl-10 pr-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-file-search"
              />
            </div>
          </div>

          <!-- Loading -->
          <div v-if="loading" class="flex-1 flex items-center justify-center py-8">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
          </div>

          <!-- Empty State -->
          <div
            v-else-if="filteredFiles.length === 0"
            class="flex-1 flex flex-col items-center justify-center py-8 text-center"
          >
            <Icon icon="heroicons:document-magnifying-glass" class="w-12 h-12 txt-secondary mb-3" />
            <p class="txt-secondary">
              {{
                searchQuery ? $t('widgets.filePicker.noResults') : $t('widgets.filePicker.noFiles')
              }}
            </p>
          </div>

          <!-- File List -->
          <div v-else class="flex-1 overflow-y-auto scroll-thin space-y-2 min-h-0">
            <label
              v-for="file in filteredFiles"
              :key="file.messageId"
              class="flex items-center gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:opacity-80 transition-opacity"
              :class="{ 'ring-2 ring-[var(--brand)]': selectedFiles.has(file.messageId) }"
            >
              <input
                type="checkbox"
                :checked="selectedFiles.has(file.messageId)"
                class="w-4 h-4 rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
                @change="toggleFile(file)"
              />
              <Icon icon="heroicons:document" class="w-5 h-5 txt-secondary flex-shrink-0" />
              <div class="flex-1 min-w-0">
                <p class="text-sm txt-primary truncate">{{ file.fileName }}</p>
                <p class="text-xs txt-secondary">{{ file.chunks }} chunks</p>
              </div>
            </label>
          </div>

          <!-- Footer -->
          <div
            class="flex items-center justify-between mt-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20"
          >
            <span class="text-sm txt-secondary">
              {{ $t('widgets.filePicker.selected', { count: selectedFiles.size }) }}
            </span>
            <div class="flex gap-3">
              <button
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                @click="close"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                :disabled="selectedFiles.size === 0"
                class="px-4 py-2 rounded-lg bg-[var(--brand)] text-white font-medium hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
                @click="confirmSelection"
              >
                {{ $t('widgets.filePicker.addFiles') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { promptsApi, type AvailableFile } from '@/services/api/promptsApi'

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  isOpen: boolean
  excludeMessageIds?: number[]
}>()

const emit = defineEmits<{
  close: []
  select: [files: AvailableFile[]]
}>()

useI18n()

const loading = ref(false)
const files = ref<AvailableFile[]>([])
const searchQuery = ref('')
const selectedFiles = ref<Set<number>>(new Set())

const filteredFiles = computed(() => {
  let result = files.value

  // Exclude already added files
  if (props.excludeMessageIds?.length) {
    result = result.filter((f) => !props.excludeMessageIds!.includes(f.messageId))
  }

  // Apply search filter
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter((f) => f.fileName.toLowerCase().includes(query))
  }

  return result
})

const loadFiles = async () => {
  loading.value = true
  try {
    files.value = await promptsApi.getAvailableFiles()
  } catch (err) {
    console.error('Failed to load available files:', err)
    files.value = []
  } finally {
    loading.value = false
  }
}

const toggleFile = (file: AvailableFile) => {
  if (selectedFiles.value.has(file.messageId)) {
    selectedFiles.value.delete(file.messageId)
  } else {
    selectedFiles.value.add(file.messageId)
  }
  // Trigger reactivity
  selectedFiles.value = new Set(selectedFiles.value)
}

const close = () => {
  emit('close')
}

const confirmSelection = () => {
  const selected = files.value.filter((f) => selectedFiles.value.has(f.messageId))
  emit('select', selected)
  close()
}

// Load files when modal opens
watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      selectedFiles.value = new Set()
      searchQuery.value = ''
      loadFiles()
    }
  }
)
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
