<template>
  <!-- Mobile Backdrop -->
  <Transition
    enter-active-class="transition-opacity duration-200"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-opacity duration-150"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="visible"
      class="fixed inset-0 bg-black/30 z-[59] md:hidden"
      data-testid="backdrop-file-mention"
      @click="emit('close')"
    />
  </Transition>

  <!-- File Mention Palette -->
  <Transition
    enter-active-class="transition-all duration-200 ease-out"
    enter-from-class="md:opacity-0 md:scale-95 translate-y-full md:translate-y-0"
    enter-to-class="md:opacity-100 md:scale-100 translate-y-0"
    leave-active-class="transition-all duration-150 ease-in"
    leave-from-class="md:opacity-100 md:scale-100 translate-y-0"
    leave-to-class="md:opacity-0 md:scale-95 translate-y-full md:translate-y-0"
  >
    <div
      v-if="visible"
      class="fixed bottom-0 left-0 right-0 md:absolute md:bottom-full md:left-0 md:right-0 md:mb-2 bg-white dark:bg-gray-900 border-t md:border border-gray-200 dark:border-gray-700 md:rounded-xl md:shadow-xl z-[60] max-h-[50vh] md:max-h-[300px] flex flex-col"
      role="listbox"
      data-testid="comp-file-mention-palette"
      @click.stop
    >
      <!-- Header -->
      <div
        class="flex-shrink-0 px-4 py-2.5 border-b border-gray-100 dark:border-gray-700/50 bg-gray-50/60 dark:bg-white/[0.03] md:rounded-t-xl"
      >
        <p class="text-xs txt-secondary flex items-center gap-2">
          <Icon icon="heroicons:at-symbol" class="w-3.5 h-3.5" />
          <span>{{ $t('fileMention.hint') }}</span>
        </p>
      </div>

      <!-- Content -->
      <div
        v-if="isLoading"
        class="flex items-center justify-center px-4 py-6"
      >
        <Icon icon="mdi:loading" class="w-5 h-5 animate-spin txt-secondary" />
      </div>
      <div
        v-else-if="filteredFiles.length === 0"
        class="flex-1 flex items-center justify-center px-4 py-6 text-sm txt-secondary"
      >
        {{ $t('fileMention.noResults') }}
      </div>
      <div v-else class="flex-1 overflow-y-auto scroll-thin py-1">
        <button
          v-for="(file, index) in filteredFiles"
          :key="file.id"
          :ref="(el) => setItemRef(el, index)"
          :class="[
            'w-full text-left px-3 py-2 flex items-center gap-3 min-w-0 transition-colors cursor-pointer',
            selectedIndex === index
              ? 'bg-[var(--brand)]/[0.08] text-[var(--brand)]'
              : 'hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-900 dark:text-gray-100',
          ]"
          role="option"
          type="button"
          :aria-selected="selectedIndex === index"
          @click="selectFile(file)"
          @mouseenter="selectedIndex = index"
        >
          <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
            <Icon :icon="getFileIcon(file.file_type)" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium truncate" :class="selectedIndex === index ? 'text-[var(--brand)]' : 'text-gray-900 dark:text-gray-100'">
              {{ file.filename }}
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
              <span>{{ formatFileSize(file.file_size) }}</span>
              <span>·</span>
              <span
                :class="{
                  'text-green-600 dark:text-green-400': file.status === 'vectorized',
                  'text-yellow-600 dark:text-yellow-400': file.status === 'extracted',
                  'text-gray-500 dark:text-gray-400': file.status === 'uploaded',
                }"
              >
                {{ file.status }}
              </span>
            </div>
          </div>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, type ComponentPublicInstance } from 'vue'
import { Icon } from '@iconify/vue'
import filesService, { type FileItem } from '@/services/filesService'

interface Props {
  visible: boolean
  query: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  select: [file: FileItem]
  close: []
}>()

const selectedIndex = ref(0)
const itemRefs = ref<Array<HTMLElement | null>>([])
const isLoading = ref(false)
const allFiles = ref<FileItem[]>([])

const setItemRef = (el: Element | ComponentPublicInstance | null, index: number) => {
  if (el) {
    itemRefs.value[index] = (el as HTMLElement)
  }
}

const filteredFiles = computed(() => {
  const q = props.query.toLowerCase().trim()
  if (!q) return allFiles.value
  return allFiles.value.filter((f) => f.filename.toLowerCase().includes(q))
})

const loadFiles = async () => {
  isLoading.value = true
  try {
    const response = await filesService.listFiles(undefined, 1, 100)
    allFiles.value = response.files
  } catch (err) {
    console.error('Failed to load files for mentions:', err)
  } finally {
    isLoading.value = false
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      selectedIndex.value = 0
      itemRefs.value = []
      loadFiles()
    }
  },
)

watch(filteredFiles, () => {
  selectedIndex.value = 0
})

const selectFile = (file: FileItem) => {
  emit('select', file)
}

const handleKeyDown = (e: KeyboardEvent) => {
  if (!props.visible || filteredFiles.value.length === 0) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    e.stopPropagation()
    selectedIndex.value = Math.min(selectedIndex.value + 1, filteredFiles.value.length - 1)
    scrollToSelected()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    e.stopPropagation()
    selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    scrollToSelected()
  } else if (e.key === 'Enter' && filteredFiles.value.length > 0) {
    e.preventDefault()
    e.stopPropagation()
    if (filteredFiles.value[selectedIndex.value]) {
      selectFile(filteredFiles.value[selectedIndex.value])
    }
  } else if (e.key === 'Escape') {
    e.preventDefault()
    e.stopPropagation()
    emit('close')
  } else if (e.key === 'Tab' && filteredFiles.value.length > 0) {
    e.preventDefault()
    e.stopPropagation()
    if (filteredFiles.value[selectedIndex.value]) {
      selectFile(filteredFiles.value[selectedIndex.value])
    }
  }
}

const scrollToSelected = () => {
  nextTick(() => {
    const item = itemRefs.value[selectedIndex.value]
    if (item) {
      item.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }
  })
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (type.includes('pdf')) return 'mdi:file-pdf-box'
  if (type.includes('doc')) return 'mdi:file-word'
  if (type.includes('xls') || type.includes('csv')) return 'mdi:file-excel'
  if (type.includes('ppt')) return 'mdi:file-powerpoint'
  if (type.includes('txt')) return 'mdi:file-document'
  if (type.includes('image') || type.match(/jpg|jpeg|png|gif|webp/)) return 'mdi:file-image'
  if (type.includes('audio') || type.match(/mp3|wav|ogg/)) return 'mdi:file-music'
  if (type.includes('video') || type.match(/mp4|avi|mov/)) return 'mdi:file-video'
  return 'mdi:file'
}

defineExpose({ handleKeyDown })
</script>
