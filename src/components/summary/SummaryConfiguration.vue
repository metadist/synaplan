<template>
  <div class="space-y-6">
    <div class="mb-8">
      <h1 class="text-2xl font-semibold txt-primary mb-2 flex items-center gap-2">
        <Cog6ToothIcon class="w-6 h-6" />
        {{ $t('summary.title') }}
      </h1>
      <p class="txt-secondary text-sm">
        {{ $t('summary.description') }}
      </p>
    </div>

    <!-- Presets -->
    <div class="surface-card p-6" data-help="presets">
      <h3 class="text-lg font-semibold txt-primary mb-3">{{ $t('summary.quickPresets') }}</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <button
          v-for="preset in presets"
          :key="preset.id"
          @click="applyPreset(preset.id)"
          class="p-4 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)] transition-colors text-left group"
        >
          <div class="flex items-start gap-3">
            <component :is="preset.icon" class="w-6 h-6 txt-secondary group-hover:text-[var(--brand)] transition-colors flex-shrink-0" />
            <div>
              <h4 class="font-semibold txt-primary group-hover:text-[var(--brand)] transition-colors">{{ $t(`summary.presets.${preset.id}.title`) }}</h4>
              <p class="text-xs txt-secondary mt-1">{{ $t(`summary.presets.${preset.id}.desc`) }}</p>
            </div>
          </div>
        </button>
      </div>
    </div>

    <div class="surface-card p-6 space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('summary.summaryType') }}
          </label>
          <select
            v-model="config.summaryType"
            class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option
              v-for="type in summaryTypes"
              :key="type.value"
              :value="type.value"
            >
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
          >
            <option
              v-for="length in summaryLengths"
              :key="length.value"
              :value="length.value"
            >
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
          >
            <option
              v-for="lang in outputLanguages"
              :key="lang.value"
              :value="lang.value"
            >
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
            />
            <span class="text-sm txt-primary">{{ area.label }}</span>
          </label>
        </div>
        <p class="text-xs txt-secondary mt-2">
          {{ $t('summary.focusAreasHelp') }}
        </p>
      </div>
    </div>

    <div class="surface-card p-6">
      <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
        <DocumentTextIcon class="w-5 h-5" />
        {{ $t('summary.documentInput') }}
      </h3>

      <!-- Drag & Drop Zone -->
      <div
        data-help="drag-drop"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop"
        :class="[
          'border-2 border-dashed rounded-lg p-8 transition-colors text-center cursor-pointer mb-4',
          isDragging ? 'border-[var(--brand)] bg-[var(--brand)]/5' : 'border-light-border/50 dark:border-dark-border/30 hover:border-[var(--brand)]/50'
        ]"
        @click="triggerFileInput"
      >
        <input
          ref="fileInput"
          type="file"
          accept=".pdf,.docx,.txt,.doc"
          @change="handleFileSelect"
          class="hidden"
        />
        <CloudArrowUpIcon class="w-12 h-12 mx-auto mb-3 txt-secondary" />
        <p class="txt-primary font-medium mb-1">{{ $t('summary.dragDropTitle') }}</p>
        <p class="text-sm txt-secondary mb-3">{{ $t('summary.dragDropDesc') }}</p>
        <p class="text-xs txt-secondary">
          {{ $t('summary.supportedFormats') }}: PDF, DOCX, TXT • {{ $t('summary.maxSize') }}: 10MB
        </p>
      </div>

      <div class="relative mb-4">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-light-border/30 dark:border-dark-border/20"></div>
        </div>
        <div class="relative flex justify-center text-xs txt-secondary">
          <span class="px-3 bg-light-surface dark:bg-dark-surface">{{ $t('summary.orPasteText') }}</span>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium txt-primary mb-2">
          {{ $t('summary.documentText') }}
        </label>
        <textarea
          data-help="textarea"
          v-model="documentText"
          rows="10"
          class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
          :placeholder="$t('summary.documentTextPlaceholder')"
        />
        <p class="text-xs txt-secondary mt-2">
          {{ characterCount }} characters | {{ wordCount }} words | {{ tokenCount }} estimated tokens
        </p>
      </div>
    </div>

    <div class="flex gap-3 justify-end">
      <button
        @click="clearForm"
        class="px-6 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
      >
        <XMarkIcon class="w-4 h-4 inline mr-2" />
        {{ $t('summary.clearForm') }}
      </button>
      <button
        data-help="generate-btn"
        @click="generateSummary"
        :disabled="!documentText.trim()"
        class="btn-primary px-6 py-2 rounded-lg flex items-center gap-2"
      >
        <SparklesIcon class="w-4 h-4" />
        {{ $t('summary.generateSummary') }}
      </button>
    </div>

    <p class="text-xs txt-secondary text-center">
      {{ $t('summary.processingNote') }}
    </p>
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
  DocumentDuplicateIcon
} from '@heroicons/vue/24/outline'
import type { SummaryConfig, FocusArea } from '@/mocks/summaries'
import { 
  summaryTypes, 
  summaryLengths, 
  outputLanguages, 
  focusAreaOptions 
} from '@/mocks/summaries'
import { useNotification } from '@/composables/useNotification'

const emit = defineEmits<{
  generate: [text: string, config: SummaryConfig]
}>()

const presets = [
  { id: 'invoice', icon: DocumentChartBarIcon },
  { id: 'contract', icon: DocumentCheckIcon },
  { id: 'generic', icon: DocumentDuplicateIcon }
]

const config = ref<SummaryConfig>({
  summaryType: 'abstractive',
  length: 'medium',
  customLength: 300,
  outputLanguage: 'en',
  focusAreas: ['main-ideas', 'key-facts'] as FocusArea[]
})

const documentText = ref('')
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const { warning } = useNotification()

const characterCount = computed(() => documentText.value.length)
const wordCount = computed(() => {
  return documentText.value.trim().split(/\s+/).filter(w => w.length > 0).length
})
const tokenCount = computed(() => {
  return Math.ceil(characterCount.value / 4)
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

const handleFile = (file: File) => {
  // Check file size (10MB max)
  if (file.size > 10 * 1024 * 1024) {
    warning('File size exceeds 10MB limit')
    return
  }

  // TODO: Handle file upload/parsing
  console.log('File selected:', file.name)
  // In production, this would upload and extract text
  documentText.value = `[File uploaded: ${file.name}]\n\nPaste the extracted text here or wait for automatic extraction...`
}

const clearForm = () => {
  documentText.value = ''
}

const generateSummary = () => {
  if (documentText.value.trim()) {
    emit('generate', documentText.value, config.value)
  }
}
</script>

