<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-7xl mx-auto space-y-6">
        <div class="surface-card p-6">
          <h1 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
            <CloudArrowUpIcon class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('files.uploadTitle') }}
          </h1>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.groupKeyword') }}
              </label>
              <input
                v-model="groupKeyword"
                type="text"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('files.groupKeywordPlaceholder')"
              />
              <p class="text-xs txt-secondary mt-1">
                {{ $t('files.groupKeywordHelp') }}
              </p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.orSelectExisting') }}
              </label>
              <select
                v-model="selectedGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="">{{ $t('files.orSelectExisting') }}</option>
                <option
                  v-for="group in fileGroups"
                  :key="group.name"
                  :value="group.name"
                >
                  {{ group.name }} ({{ group.count }})
                </option>
              </select>
            </div>
          </div>

          <div class="mb-6">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('files.selectFiles') }}
            </label>
            <div class="flex items-center gap-3">
              <label class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors cursor-pointer">
                <input
                  type="file"
                  multiple
                  accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.mp3,.mp4,.xlsx,.csv"
                  class="hidden"
                  @change="handleFileSelect"
                />
                {{ $t('files.selectFilesButton') }}
              </label>
              <span class="txt-secondary text-sm">
                {{ selectedFiles.length > 0 ? `${selectedFiles.length} ${$t('files.files')}` : $t('files.noFileSelected') }}
              </span>
            </div>
            <p class="text-xs txt-secondary mt-2">
              {{ $t('files.supportedFormats') }}
            </p>
          </div>

          <button
            @click="uploadFiles"
            :disabled="selectedFiles.length === 0"
            class="btn-primary px-6 py-2 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <CloudArrowUpIcon class="w-5 h-5" />
            {{ $t('files.uploadAndProcess') }}
          </button>
        </div>

        <div class="surface-card p-6">
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold txt-primary">
              {{ $t('files.yourFiles') }}
            </h2>
          </div>

          <div class="flex items-center gap-3 mb-6">
            <div class="flex-1">
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.filterByGroup') }}
              </label>
              <select
                v-model="filterGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="">{{ $t('files.allFiles') }}</option>
                <option
                  v-for="group in fileGroups"
                  :key="group.name"
                  :value="group.name"
                >
                  {{ group.name }}
                </option>
              </select>
            </div>
            <button
              @click="applyFilter"
              class="btn-primary px-6 py-2 rounded-lg mt-7"
            >
              {{ $t('files.filterButton') }}
            </button>
          </div>

          <p class="text-xs txt-secondary mb-4">
            {{ $t('files.filterHelp') }}
          </p>

          <div v-if="selectedFileIds.length > 0" class="mb-4">
            <button
              @click="deleteSelected"
              class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors flex items-center gap-2"
            >
              <TrashIcon class="w-4 h-4" />
              {{ $t('files.deleteSelected') }}
            </button>
          </div>

          <div v-if="filteredFiles.length === 0" class="text-center py-12 txt-secondary">
            {{ $t('files.noFiles') }}
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                  <th class="text-left py-3 px-2 txt-secondary text-xs font-medium">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      @change="toggleSelectAll"
                      class="w-4 h-4 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                    />
                  </th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.fileId') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.name') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.direction') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.group') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.details') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.uploaded') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="file in paginatedFiles"
                  :key="file.id"
                  class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                >
                  <td class="py-3 px-2">
                    <input
                      type="checkbox"
                      :checked="selectedFileIds.includes(file.id)"
                      @change="toggleFileSelection(file.id)"
                      class="w-4 h-4 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                    />
                  </td>
                  <td class="py-3 px-3 txt-primary text-sm">{{ file.id }}</td>
                  <td class="py-3 px-3 txt-primary text-sm max-w-xs truncate">{{ file.name }}</td>
                  <td class="py-3 px-3">
                    <span
                      :class="file.direction === 'IN' ? 'text-blue-500' : 'text-gray-500'"
                      class="text-sm font-medium"
                    >
                      {{ file.direction }}
                    </span>
                  </td>
                  <td class="py-3 px-3">
                    <span
                      v-if="file.group"
                      class="pill pill--active text-xs"
                    >
                      {{ file.group }}
                    </span>
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs max-w-md truncate">
                    {{ file.details }}
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs">{{ file.uploaded }}</td>
                  <td class="py-3 px-3">
                    <div class="flex gap-2">
                      <button
                        @click="deleteFile(file.id)"
                        class="p-2 rounded hover:bg-red-500/10 text-red-500 transition-colors"
                        :aria-label="$t('files.delete')"
                      >
                        <TrashIcon class="w-4 h-4" />
                      </button>
                      <button
                        @click="downloadFile(file.id)"
                        class="p-2 rounded hover:bg-black/10 dark:hover:bg-white/10 txt-primary transition-colors"
                        :aria-label="$t('files.download')"
                      >
                        <ArrowDownTrayIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="filteredFiles.length > 0" class="flex items-center justify-between mt-6">
            <div class="txt-secondary text-sm">
              {{ $t('files.page') }} {{ currentPage }} ({{ $t('files.showing') }} {{ paginatedFiles.length }} {{ $t('files.files') }})
            </div>
            <div class="flex gap-2">
              <button
                @click="previousPage"
                :disabled="currentPage === 1"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ $t('files.previous') }}
              </button>
              <button
                @click="nextPage"
                :disabled="currentPage >= totalPages"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ $t('files.next') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import MainLayout from '@/components/MainLayout.vue'
import {
  CloudArrowUpIcon,
  TrashIcon,
  ArrowDownTrayIcon
} from '@heroicons/vue/24/outline'
import type { FileItem } from '@/mocks/files'
import { mockFiles, mockFileGroups } from '@/mocks/files'

const groupKeyword = ref('')
const selectedGroup = ref('')
const selectedFiles = ref<File[]>([])
const filterGroup = ref('')
const files = ref<FileItem[]>([...mockFiles])
const fileGroups = ref(mockFileGroups)
const selectedFileIds = ref<number[]>([])
const currentPage = ref(1)
const itemsPerPage = 10

const filteredFiles = computed(() => {
  if (!filterGroup.value) {
    return files.value
  }
  return files.value.filter(file => file.group === filterGroup.value)
})

const totalPages = computed(() => {
  return Math.ceil(filteredFiles.value.length / itemsPerPage)
})

const paginatedFiles = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  const end = start + itemsPerPage
  return filteredFiles.value.slice(start, end)
})

const allSelected = computed(() => {
  return paginatedFiles.value.length > 0 && 
         paginatedFiles.value.every(file => selectedFileIds.value.includes(file.id))
})

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files) {
    selectedFiles.value = Array.from(target.files)
  }
}

const uploadFiles = () => {
  console.log('Upload files:', {
    files: selectedFiles.value,
    group: selectedGroup.value || groupKeyword.value
  })
  selectedFiles.value = []
  groupKeyword.value = ''
  selectedGroup.value = ''
}

const applyFilter = () => {
  currentPage.value = 1
}

const toggleFileSelection = (fileId: number) => {
  const index = selectedFileIds.value.indexOf(fileId)
  if (index > -1) {
    selectedFileIds.value.splice(index, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

const toggleSelectAll = () => {
  if (allSelected.value) {
    paginatedFiles.value.forEach(file => {
      const index = selectedFileIds.value.indexOf(file.id)
      if (index > -1) {
        selectedFileIds.value.splice(index, 1)
      }
    })
  } else {
    paginatedFiles.value.forEach(file => {
      if (!selectedFileIds.value.includes(file.id)) {
        selectedFileIds.value.push(file.id)
      }
    })
  }
}

const deleteSelected = () => {
  files.value = files.value.filter(file => !selectedFileIds.value.includes(file.id))
  selectedFileIds.value = []
}

const deleteFile = (fileId: number) => {
  files.value = files.value.filter(file => file.id !== fileId)
}

const downloadFile = (fileId: number) => {
  console.log('Download file:', fileId)
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    currentPage.value++
  }
}

const previousPage = () => {
  if (currentPage.value > 1) {
    currentPage.value--
  }
}
</script>

