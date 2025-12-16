<template>
  <div class="space-y-6" data-testid="page-summary-configuration">
    <div class="mb-8" data-testid="section-header">
      <h1 class="text-2xl font-semibold txt-primary mb-2 flex items-center gap-2">
        <Cog6ToothIcon class="w-6 h-6" />
        {{ $t('summary.title') }}
      </h1>
      <p class="txt-secondary text-sm">
        {{ $t('summary.description') }}
      </p>
    </div>

    <!-- Presets -->
    <div class="surface-card p-6" data-help="presets" data-testid="section-presets">
      <h3 class="text-lg font-semibold txt-primary mb-3">{{ $t('summary.quickPresets') }}</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <button
          v-for="preset in presets"
          :key="preset.id"
          class="p-4 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)] transition-colors text-left group"
          data-testid="btn-preset"
          @click="applyPreset(preset.id)"
        >
          <div class="flex items-start gap-3">
            <component
              :is="preset.icon"
              class="w-6 h-6 txt-secondary group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
            />
            <div>
              <h4
                class="font-semibold txt-primary group-hover:text-[var(--brand)] transition-colors"
              >
                {{ $t(`summary.presets.${preset.id}.title`) }}
              </h4>
              <p class="text-xs txt-secondary mt-1">
                {{ $t(`summary.presets.${preset.id}.desc`) }}
              </p>
            </div>
          </div>
        </button>
      </div>
    </div>

    <div class="surface-card p-6 space-y-6" data-testid="section-configuration">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('summary.summaryType') }}
          </label>
          <select
            v-model="config.summaryType"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-summary-type"
          >
            <option v-for="type in summaryTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('summary.summaryTypeHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('summary.length') }}
          </label>
          <select
            v-model="config.length"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-length"
          >
            <option v-for="length in summaryLengths" :key="length.value" :value="length.value">
              {{ length.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('summary.lengthHelp') }}
          </p>
        </div>

        <div v-if="config.length === 'custom'">
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('summary.customLength') }}
          </label>
          <input
            v-model.number="config.customLength"
            type="number"
            min="50"
            max="2000"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            placeholder="300"
            data-testid="input-custom-length"
          />
          <p class="text-xs txt-secondary mt-1">
            {{ $t('summary.customLengthHelp') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('summary.outputLanguage') }}
          </label>
          <select
            v-model="config.outputLanguage"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="input-language"
          >
            <option v-for="lang in outputLanguages" :key="lang.value" :value="lang.value">
              {{ lang.label }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('summary.outputLanguageHelp') }}
          </p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium txt-primary mb-3">
          {{ $t('summary.focusAreas') }}
        </label>
        <div class="flex flex-wrap gap-4">
          <label
            v-for="area in focusAreaOptions"
            :key="area.value"
            class="flex items-center gap-2 cursor-pointer"
          >
            <input
              v-model="config.focusAreas"
              type="checkbox"
              :value="area.value"
              class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-focus-area"
            />
            <span class="text-sm txt-primary">{{ area.label }}</span>
          </label>
        </div>
        <p class="text-xs txt-secondary mt-2">
          {{ $t('summary.focusAreasHelp') }}
        </p>
      </div>
    </div>

    <div class="surface-card p-6" data-testid="section-document">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <DocumentTextIcon class="w-5 h-5" />
        {{ $t('summary.documentInput') }}
      </h3>

      <!-- Drag & Drop Zone -->
      <div
        data-help="drag-drop"
        :class="[
          'border-2 border-dashed rounded-lg p-8 transition-colors text-center mb-4',
          isUploadingFile
            ? 'border-[var(--brand)] bg-[var(--brand)]/5 cursor-wait'
            : isDragging
              ? 'border-[var(--brand)] bg-[var(--brand)]/5 cursor-pointer'
              : 'border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50 cursor-pointer',
        ]"
        data-testid="section-upload"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop"
        @click="!isUploadingFile && triggerFileInput()"
      >
        <input
          ref="fileInput"
          type="file"
          accept=".pdf,.docx,.txt,.doc"
          class="hidden"
          data-testid="input-file"
          :disabled="isUploadingFile"
          @change="handleFileSelect"
        />
        <div v-if="isUploadingFile" class="flex flex-col items-center">
          <div
            class="animate-spin h-12 w-12 border-4 border-[var(--brand)] border-t-transparent rounded-full mb-3"
          ></div>
          <p class="txt-primary font-medium mb-1">{{ $t('summary.uploadingFile') }}</p>
          <p class="text-sm txt-secondary">{{ $t('summary.uploadingFileDesc') }}</p>
        </div>
        <div v-else>
          <CloudArrowUpIcon class="w-12 h-12 mx-auto mb-3 txt-secondary" />
          <p class="txt-primary font-medium mb-1">{{ $t('summary.dragDropTitle') }}</p>
          <p class="text-sm txt-secondary mb-3">{{ $t('summary.dragDropDesc') }}</p>
          <p class="text-xs txt-secondary">
            {{ $t('summary.supportedFormats') }}: PDF, DOCX, TXT • {{ $t('summary.maxSize') }}: 10MB
          </p>
        </div>
      </div>

      <!-- Select from Existing Files -->
      <div class="mb-4">
        <button
          :disabled="isUploadingFile"
          class="w-full px-4 py-3 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50 transition-colors txt-primary hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          @click="openFileSelection"
        >
          <DocumentTextIcon class="w-5 h-5" />
          {{ $t('summary.selectFromExisting') }}
        </button>
      </div>

      <div class="relative mb-4">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-light-border/30 dark:border-dark-border/20"></div>
        </div>
        <div class="relative flex justify-center text-xs txt-secondary">
          <span class="px-4 py-1 bg-[var(--bg-card)]">{{ $t('summary.orPasteText') }}</span>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('summary.documentText') }}
        </label>
        <textarea
          v-model="documentText"
          data-help="textarea"
          rows="10"
          class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
          :placeholder="$t('summary.documentTextPlaceholder')"
          data-testid="input-document-text"
        />
        <p class="text-xs txt-secondary mt-2">
          {{ characterCount }} characters | {{ wordCount }} words | {{ tokenCount }} estimated
          tokens
        </p>
      </div>
    </div>

    <!-- Actions Row -->
    <div
      class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3"
      data-testid="section-actions"
    >
      <!-- Current Chat Model Display -->
      <router-link
        to="/config/ai-models?highlight=CHAT"
        class="flex items-center gap-2 px-3 py-2 rounded-lg surface-elevated border border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)] transition-all group w-full sm:w-auto"
      >
        <svg
          class="w-4 h-4 txt-secondary group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
          />
        </svg>
        <div class="flex flex-col min-w-0">
          <span
            class="text-xs txt-secondary group-hover:text-[var(--brand)] transition-colors leading-tight"
            >{{ $t('summary.usingModel') }}</span
          >
          <span
            class="text-sm font-medium txt-primary group-hover:text-[var(--brand)] transition-colors truncate"
          >
            {{ props.currentModel || $t('summary.loadingModel') }}
          </span>
        </div>
        <svg
          class="w-4 h-4 txt-secondary group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
          />
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
          />
        </svg>
      </router-link>

      <!-- Action Buttons -->
      <div class="flex gap-2 w-full sm:w-auto">
        <button
          class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center gap-2"
          data-testid="btn-clear"
          @click="clearForm"
        >
          <XMarkIcon class="w-4 h-4" />
          {{ $t('summary.clearForm') }}
        </button>

        <!-- Generate/Show Summary Button Group -->
        <div class="flex">
          <button
            data-help="generate-btn"
            :disabled="!documentText.trim() || props.isGenerating"
            :class="[
              'btn-primary px-6 py-2 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all',
              hasGeneratedSummary && !hasTextChanged ? 'rounded-l-lg' : 'rounded-lg',
            ]"
            data-testid="btn-generate"
            @click="handleButtonClick"
          >
            <div
              v-if="props.isGenerating"
              class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"
            ></div>
            <SparklesIcon v-else class="w-4 h-4" />
            {{ $t(buttonText) }}
          </button>

          <!-- Dropdown Button (only show when summary exists) -->
          <div v-if="hasGeneratedSummary && !hasTextChanged" class="relative group">
            <button
              class="btn-primary px-3 py-2 rounded-r-lg border-l border-white/20 hover:bg-[var(--brand)]/90 disabled:opacity-50 disabled:cursor-not-allowed h-full flex items-center justify-center"
              :disabled="props.isGenerating"
            >
              <ChevronDownIcon class="w-4 h-4" />
            </button>

            <!-- Dropdown Menu -->
            <div
              class="absolute right-0 mt-2 w-48 surface-card rounded-lg shadow-xl border border-light-border/20 dark:border-dark-border/20 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-10"
            >
              <button
                :disabled="props.isGenerating"
                class="w-full px-4 py-2 text-left txt-primary hover:bg-black/5 dark:hover:bg-white/5 rounded-lg flex items-center gap-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                @click="regenerateSummary"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
                {{ $t('summary.regenerateSummary') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <p class="text-xs txt-secondary text-center">
      {{ $t('summary.processingNote') }}
    </p>

    <!-- File Selection Modal -->
    <FileSelectionModal
      :visible="fileSelectionModalVisible"
      @close="fileSelectionModalVisible = false"
      @select="handleFilesSelected"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import {
  Cog6ToothIcon,
  DocumentTextIcon,
  SparklesIcon,
  XMarkIcon,
  CloudArrowUpIcon,
  DocumentChartBarIcon,
  DocumentCheckIcon,
  DocumentDuplicateIcon,
  ChevronDownIcon,
} from '@heroicons/vue/24/outline'
import type { SummaryConfig, FocusArea } from '@/mocks/summaries'
import { summaryTypes, summaryLengths, outputLanguages, focusAreaOptions } from '@/mocks/summaries'
import { useNotification } from '@/composables/useNotification'
import { uploadFiles, getFileContent, type FileItem } from '@/services/filesService'
import FileSelectionModal from '@/components/FileSelectionModal.vue'

interface Props {
  isGenerating?: boolean
  currentModel?: string | null
}

const props = withDefaults(defineProps<Props>(), {
  isGenerating: false,
  currentModel: null,
})

const emit = defineEmits<{
  generate: [text: string, config: SummaryConfig]
  regenerate: [text: string, config: SummaryConfig]
  show: []
}>()

const presets = [
  { id: 'invoice', icon: DocumentChartBarIcon },
  { id: 'contract', icon: DocumentCheckIcon },
  { id: 'generic', icon: DocumentDuplicateIcon },
]

const config = ref<SummaryConfig>({
  summaryType: 'abstractive',
  length: 'medium',
  customLength: 300,
  outputLanguage: 'en',
  focusAreas: ['main-ideas', 'key-facts'] as FocusArea[],
})

const documentText = ref('')
const originalDocumentText = ref('') // Track original text for change detection
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const hasGeneratedSummary = ref(false)
const isUploadingFile = ref(false)
const fileSelectionModalVisible = ref(false)

const { warning, error, success } = useNotification()

const characterCount = computed(() => documentText.value.length)
const wordCount = computed(() => {
  return documentText.value
    .trim()
    .split(/\s+/)
    .filter((w) => w.length > 0).length
})
const tokenCount = computed(() => {
  return Math.ceil(characterCount.value / 4)
})

// Check if document text has changed since last generation
const hasTextChanged = computed(() => {
  return hasGeneratedSummary.value && documentText.value !== originalDocumentText.value
})

// Button text based on state
const buttonText = computed(() => {
  if (props.isGenerating) return 'summary.generating'
  if (hasTextChanged.value) return 'summary.generateSummary'
  if (hasGeneratedSummary.value) return 'summary.showSummary'
  return 'summary.generateSummary'
})

const applyPreset = (presetId: string) => {
  switch (presetId) {
    case 'invoice':
      config.value.summaryType = 'extractive'
      config.value.length = 'short'
      config.value.focusAreas = ['key-facts', 'numbers-dates'] as FocusArea[]
      break
    case 'contract':
      config.value.summaryType = 'abstractive'
      config.value.length = 'medium'
      config.value.focusAreas = ['main-ideas', 'conclusions'] as FocusArea[]
      break
    case 'generic':
      config.value.summaryType = 'abstractive'
      config.value.length = 'medium'
      config.value.focusAreas = ['main-ideas', 'key-facts'] as FocusArea[]
      break
  }
}

const triggerFileInput = () => {
  fileInput.value?.click()
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files && target.files[0]) {
    handleFile(target.files[0])
  }
}

const handleDrop = (event: DragEvent) => {
  isDragging.value = false
  if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
    handleFile(event.dataTransfer.files[0])
  }
}

const handleFile = async (file: File) => {
  // Check file size (10MB max)
  if (file.size > 10 * 1024 * 1024) {
    warning('File size exceeds 10MB limit')
    return
  }

  // Validate file type
  const allowedTypes = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/msword', // .doc
    'text/plain',
  ]

  if (!allowedTypes.includes(file.type) && !file.name.match(/\.(pdf|docx|doc|txt)$/i)) {
    warning('Unsupported file type. Please upload PDF, DOCX, DOC, or TXT files.')
    return
  }

  try {
    isUploadingFile.value = true

    // Upload file using the Files API with extraction
    const uploadResult = await uploadFiles({
      files: [file],
      groupKey: 'DOC_SUMMARY',
      processLevel: 'extract', // Only extract text, no vectorization needed for summary
    })

    if (uploadResult.success && uploadResult.files.length > 0) {
      const uploadedFile = uploadResult.files[0]

      // Get file content to retrieve extracted text
      const fileContent = await getFileContent(uploadedFile.id)

      if (fileContent.extracted_text) {
        documentText.value = fileContent.extracted_text
        success(`File "${file.name}" uploaded and text extracted successfully!`)

        // Reset the generated summary flag since we have new text
        hasGeneratedSummary.value = false
        originalDocumentText.value = ''
      } else {
        warning('File uploaded but no text could be extracted.')
      }
    } else {
      error('Failed to extract text from file. Please try again.')
    }
  } catch (err: any) {
    console.error('File upload error:', err)
    error(err?.message || 'Failed to upload file. Please try again.')
  } finally {
    isUploadingFile.value = false
    // Reset file input
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  }
}

const clearForm = () => {
  documentText.value = ''
  originalDocumentText.value = ''
  hasGeneratedSummary.value = false
}

const generateSummary = () => {
  if (documentText.value.trim()) {
    originalDocumentText.value = documentText.value
    hasGeneratedSummary.value = true
    emit('generate', documentText.value, config.value)
  }
}

const showSummary = () => {
  emit('show')
}

const regenerateSummary = () => {
  if (documentText.value.trim()) {
    originalDocumentText.value = documentText.value
    emit('regenerate', documentText.value, config.value)
  }
}

const handleButtonClick = () => {
  if (hasTextChanged.value || !hasGeneratedSummary.value) {
    generateSummary()
  } else {
    showSummary()
  }
}

const openFileSelection = () => {
  fileSelectionModalVisible.value = true
}

const handleFilesSelected = async (selectedFiles: FileItem[]) => {
  if (selectedFiles.length === 0) return

  try {
    isUploadingFile.value = true

    // Get extracted text from all selected files
    const textPromises = selectedFiles.map((file) => getFileContent(file.id))
    const fileContents = await Promise.all(textPromises)

    // Combine extracted text from all files
    const combinedText = fileContents
      .map((content) => {
        if (content.extracted_text) {
          return `=== ${content.filename} ===\n\n${content.extracted_text}`
        }
        return ''
      })
      .filter((text) => text.length > 0)
      .join('\n\n---\n\n')

    if (combinedText) {
      documentText.value = combinedText
      success(`${selectedFiles.length} file(s) loaded successfully!`)

      // Reset the generated summary flag since we have new text
      hasGeneratedSummary.value = false
      originalDocumentText.value = ''
    } else {
      warning('No text could be extracted from selected files.')
    }
  } catch (err: any) {
    console.error('Failed to load file content:', err)
    error(err?.message || 'Failed to load files. Please try again.')
  } finally {
    isUploadingFile.value = false
    fileSelectionModalVisible.value = false
  }
}
</script>
