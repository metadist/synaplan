<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useMarkdown } from '@/composables/useMarkdown'
import { useNotification } from '@/composables/useNotification'
import { useExternalLink } from '@/composables/useExternalLink'
import ExternalLinkWarning from '@/components/common/ExternalLinkWarning.vue'
import { chatApi } from '@/services/api/chatApi'
import {
  researchKbSources,
  researchWebSources,
  type KbSource,
  type WebSource,
} from '@/services/api/feedbackApi'

interface Props {
  isOpen: boolean
  segments: string[]
  fullText: string
  step: 'select' | 'confirm'
  classification: 'memory' | 'feedback'
  summaryOptions: string[]
  correctionOptions: string[]
  userMessage?: string
  isSubmitting?: boolean
  isPreviewLoading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  classification: 'feedback',
  userMessage: '',
  isSubmitting: false,
  isPreviewLoading: false,
})

const emit = defineEmits<{
  close: []
  preview: [text: string]
  save: [data: { summary: string; correction: string }]
  back: []
  regenerate: [data: { summary: string; correction: string }]
}>()

const { t } = useI18n()
const { render: renderMarkdown } = useMarkdown()
const { error: showErrorToast } = useNotification()
const { pendingUrl, warningOpen, openExternalLink, closeWarning } = useExternalLink()
const selectedIndexes = ref<Set<number>>(new Set())
const useFullText = ref(false)

// Enhance state
const enhancingSummary = ref(false)
const enhancingCorrection = ref(false)
const summaryEnhanceHintShown = ref(false)
const correctionEnhanceHintShown = ref(false)

// Research state
type ResearchTab = 'kb' | 'web'
const researchTab = ref<ResearchTab>('web')
const researchLoading = ref(false)

// KB sources
const kbSources = ref<KbSource[]>([])
const kbDone = ref(false)

// Web sources
const webSources = ref<WebSource[]>([])
const webDone = ref(false)

// Unified selection — key format: "kb-{id}" or "web-{id}"
const selectedSourceKeys = ref<Set<string>>(new Set())

// Source type display helpers
function sourceTypeDisplay(sourceType: string) {
  const map: Record<
    string,
    { icon: string; label: string; iconColor: string; badgeColor: string }
  > = {
    file: {
      icon: 'mdi:file-document-outline',
      label: t('feedback.falsePositive.sourceTypeFile'),
      iconColor: 'text-blue-500 dark:text-blue-400',
      badgeColor: 'text-blue-500 dark:text-blue-400 bg-blue-500/10',
    },
    feedback_false: {
      icon: 'mdi:close-circle-outline',
      label: t('feedback.falsePositive.sourceTypeFeedbackFalse'),
      iconColor: 'text-red-500 dark:text-red-400',
      badgeColor: 'text-red-500 dark:text-red-400 bg-red-500/10',
    },
    feedback_correct: {
      icon: 'mdi:check-circle-outline',
      label: t('feedback.falsePositive.sourceTypeFeedbackCorrect'),
      iconColor: 'text-green-500 dark:text-green-400',
      badgeColor: 'text-green-500 dark:text-green-400 bg-green-500/10',
    },
    memory: {
      icon: 'mdi:head-lightbulb-outline',
      label: t('feedback.falsePositive.sourceTypeMemory'),
      iconColor: 'text-purple-500 dark:text-purple-400',
      badgeColor: 'text-purple-500 dark:text-purple-400 bg-purple-500/10',
    },
  }
  return map[sourceType] ?? map.file
}

const selectedText = computed(() => {
  if (useFullText.value) {
    return props.fullText
  }

  const parts = props.segments
    .map((segment, index) => (selectedIndexes.value.has(index) ? segment : ''))
    .filter(Boolean)

  return parts.join('\n\n')
})

const canSubmit = computed(() => selectedText.value.trim().length > 0)
const selectedCount = computed(() =>
  useFullText.value ? props.segments.length : selectedIndexes.value.size
)

// Step 2: Option selection state
// 'custom' means user typed their own; 'delete' means delete memory without replacement; 'research' means AI sources
const selectedSummaryIdx = ref<number | 'custom'>(0)
const selectedCorrectionIdx = ref<number | 'custom' | 'delete' | 'research'>(0)
const customSummary = ref('')
const customCorrection = ref('')

// Active summary and correction values based on selection
const activeSummary = computed(() => {
  if (selectedSummaryIdx.value === 'custom') return customSummary.value.trim()
  return props.summaryOptions[selectedSummaryIdx.value] ?? ''
})

// Build correction text from selected research sources (both KB + web)
const researchCorrection = computed(() => {
  if (selectedSourceKeys.value.size === 0) return ''
  const parts: string[] = []
  for (const key of selectedSourceKeys.value) {
    const dashIdx = key.indexOf('-')
    if (dashIdx === -1) continue
    const type = key.slice(0, dashIdx)
    const id = parseInt(key.slice(dashIdx + 1), 10)
    if (!Number.isFinite(id)) continue
    if (type === 'kb') {
      const src = kbSources.value.find((s: KbSource) => s.id === id)
      if (src) parts.push(src.summary)
    } else if (type === 'web') {
      const src = webSources.value.find((s: WebSource) => s.id === id)
      if (src) parts.push(src.summary)
    }
  }
  return parts.join(' ')
})

const activeCorrection = computed(() => {
  if (selectedCorrectionIdx.value === 'delete') return ''
  if (selectedCorrectionIdx.value === 'research') return researchCorrection.value
  if (selectedCorrectionIdx.value === 'custom') return customCorrection.value.trim()
  return props.correctionOptions[selectedCorrectionIdx.value as number] ?? ''
})

const canSave = computed(() => {
  // "Delete only" mode: summary is sufficient (correction intentionally empty)
  if (selectedCorrectionIdx.value === 'delete') return activeSummary.value.length > 0
  // Research mode: need at least one source selected
  if (selectedCorrectionIdx.value === 'research') {
    return activeSummary.value.length > 0 && selectedSourceKeys.value.size > 0
  }
  return activeSummary.value.length > 0 || activeCorrection.value.length > 0
})

watch(
  () => [props.isOpen, props.segments],
  () => {
    if (!props.isOpen) {
      return
    }
    selectedIndexes.value = new Set()
    useFullText.value = false
  },
  { deep: true }
)

// Reset option selection when new options arrive
watch(
  () => [props.summaryOptions, props.correctionOptions],
  () => {
    selectedSummaryIdx.value = 0
    selectedCorrectionIdx.value = 0
    customSummary.value = ''
    customCorrection.value = ''
    summaryEnhanceHintShown.value = false
    correctionEnhanceHintShown.value = false
    kbSources.value = []
    kbDone.value = false
    webSources.value = []
    webDone.value = false
    researchLoading.value = false
    researchTab.value = 'web'
    selectedSourceKeys.value = new Set()
  },
  { deep: true }
)

const toggleSegment = (index: number) => {
  const next = new Set(selectedIndexes.value)
  if (next.has(index)) {
    next.delete(index)
  } else {
    next.add(index)
  }
  selectedIndexes.value = next
}

const selectAll = () => {
  const next = new Set<number>()
  props.segments.forEach((_, index) => next.add(index))
  selectedIndexes.value = next
  useFullText.value = false
}

const clearAll = () => {
  selectedIndexes.value = new Set()
  useFullText.value = false
}

const handleSubmit = () => {
  if (!canSubmit.value) {
    return
  }
  emit('preview', selectedText.value.trim())
}

const handleRegenerate = () => {
  if (activeSummary.value.length === 0) {
    return
  }
  emit('regenerate', { summary: activeSummary.value, correction: activeCorrection.value })
}

const handleSave = () => {
  if (!canSave.value) {
    return
  }
  emit('save', {
    summary: activeSummary.value,
    correction: activeCorrection.value,
  })
}

// Enhance custom text via AI (shared logic)
const ENHANCE_HINT_THRESHOLD = 15

async function enhanceText(
  textRef: typeof customSummary,
  loadingRef: typeof enhancingSummary,
  hintRef: typeof summaryEnhanceHintShown
) {
  if (!textRef.value.trim() || loadingRef.value) return
  loadingRef.value = true
  try {
    const result = await chatApi.enhanceMessage(textRef.value.trim())
    textRef.value = result.enhanced
    hintRef.value = false
  } catch {
    showErrorToast(t('feedback.falsePositive.enhanceFailed'))
  } finally {
    loadingRef.value = false
  }
}

const enhanceSummary = () => enhanceText(customSummary, enhancingSummary, summaryEnhanceHintShown)
const enhanceCorrection = () =>
  enhanceText(customCorrection, enhancingCorrection, correctionEnhanceHintShown)

// Show enhance hint after typing threshold
function onCustomSummaryInput() {
  if (
    customSummary.value.length > ENHANCE_HINT_THRESHOLD &&
    !summaryEnhanceHintShown.value &&
    !enhancingSummary.value
  ) {
    summaryEnhanceHintShown.value = true
  }
}

function onCustomCorrectionInput() {
  if (
    customCorrection.value.length > ENHANCE_HINT_THRESHOLD &&
    !correctionEnhanceHintShown.value &&
    !enhancingCorrection.value
  ) {
    correctionEnhanceHintShown.value = true
  }
}

// Research: search knowledge base (Qdrant) or web (Brave)
const AUTO_SELECT_SCORE = 0.5

async function startKbSearch() {
  if (researchLoading.value || !activeSummary.value.trim()) return
  researchLoading.value = true
  kbDone.value = false
  try {
    const result = await researchKbSources(activeSummary.value.trim())
    kbSources.value = result.sources
    kbDone.value = true
    // Auto-select sources with high relevance
    const autoKeys = new Set(selectedSourceKeys.value)
    for (const src of result.sources) {
      if (src.score >= AUTO_SELECT_SCORE) autoKeys.add(`kb-${src.id}`)
    }
    selectedSourceKeys.value = autoKeys
  } catch {
    showErrorToast(t('feedback.falsePositive.researchNoResultsKb'))
  } finally {
    researchLoading.value = false
  }
}

async function startWebSearch() {
  if (researchLoading.value || !activeSummary.value.trim()) return
  researchLoading.value = true
  webDone.value = false
  try {
    const result = await researchWebSources(activeSummary.value.trim())
    webSources.value = result.sources
    webDone.value = true
    // Auto-select first web source (most relevant)
    if (result.sources.length > 0) {
      const autoKeys = new Set(selectedSourceKeys.value)
      autoKeys.add(`web-${result.sources[0].id}`)
      selectedSourceKeys.value = autoKeys
    }
  } catch {
    showErrorToast(t('feedback.falsePositive.researchNoResultsWeb'))
  } finally {
    researchLoading.value = false
  }
}

function toggleSourceKey(key: string) {
  const next = new Set(selectedSourceKeys.value)
  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }
  selectedSourceKeys.value = next
}

const selectedSourceCount = computed(() => selectedSourceKeys.value.size)

const isMemory = computed(() => props.classification === 'memory')
</script>

<template>
  <Teleport to="#app">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="emit('close')"
      >
        <div
          class="surface-card rounded-2xl shadow-2xl max-w-2xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-y-auto scroll-thin"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand/20 to-orange-500/20 flex items-center justify-center"
              >
                <Icon icon="mdi:alert-circle-check-outline" class="w-5 h-5 text-brand" />
              </div>
              <div>
                <h3 class="text-base sm:text-lg font-semibold txt-primary">
                  {{ t('feedback.falsePositive.title') }}
                </h3>
                <p class="text-xs txt-secondary">
                  {{ t('feedback.falsePositive.subtitle') }}
                </p>
              </div>
            </div>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
              @click="emit('close')"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Step 1: Select segments -->
          <template v-if="step === 'select'">
            <div class="p-4 sm:p-6 space-y-4">
              <!-- Quick Actions -->
              <div class="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg text-xs font-medium surface-chip txt-secondary hover:txt-primary transition-colors"
                  @click="selectAll"
                >
                  {{ t('feedback.falsePositive.selectAll') }}
                </button>
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg text-xs font-medium surface-chip txt-secondary hover:txt-primary transition-colors"
                  @click="clearAll"
                >
                  {{ t('feedback.falsePositive.clear') }}
                </button>
                <label
                  class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium surface-chip txt-secondary cursor-pointer hover:txt-primary transition-colors"
                >
                  <input
                    v-model="useFullText"
                    type="checkbox"
                    class="rounded border-gray-300 text-brand focus:ring-brand"
                  />
                  {{ t('feedback.falsePositive.useFull') }}
                </label>
                <span class="text-xs txt-secondary ml-auto">
                  {{ t('feedback.falsePositive.selectedCount', { count: selectedCount }) }}
                </span>
              </div>

              <!-- Segments List -->
              <div class="space-y-2 max-h-[40vh] overflow-y-auto scroll-thin px-0.5">
                <div
                  v-for="(segment, index) in segments"
                  :key="`segment-${index}`"
                  class="relative rounded-xl border-2 transition-all duration-200 select-none"
                  :class="[
                    useFullText
                      ? 'border-transparent bg-black/[0.03] dark:bg-white/[0.03] opacity-50 cursor-default'
                      : selectedIndexes.has(index)
                        ? 'border-brand/50 bg-brand/[0.04] dark:bg-brand/[0.06] cursor-pointer shadow-sm'
                        : 'border-transparent bg-black/[0.03] dark:bg-white/[0.04] cursor-pointer hover:border-brand/20 hover:bg-black/[0.05] dark:hover:bg-white/[0.06]',
                  ]"
                  role="checkbox"
                  :aria-checked="selectedIndexes.has(index)"
                  :tabindex="useFullText ? -1 : 0"
                  @click="!useFullText && toggleSegment(index)"
                  @keydown.space.prevent="!useFullText && toggleSegment(index)"
                >
                  <!-- Left accent bar for selected segments -->
                  <div
                    class="absolute left-0 top-2 bottom-2 w-[3px] rounded-full transition-all duration-200"
                    :class="
                      selectedIndexes.has(index) && !useFullText
                        ? 'bg-brand opacity-100'
                        : 'bg-transparent opacity-0'
                    "
                  />
                  <div class="flex items-start gap-3 p-3 pl-4">
                    <input
                      :checked="selectedIndexes.has(index) && !useFullText"
                      type="checkbox"
                      class="mt-1 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-brand focus:ring-brand/30 cursor-pointer flex-shrink-0 pointer-events-none"
                      :disabled="useFullText"
                      tabindex="-1"
                    />
                    <div
                      class="text-sm txt-primary prose prose-sm dark:prose-invert max-w-none pointer-events-none flex-1 min-w-0"
                      v-html="renderMarkdown(segment)"
                    />
                  </div>
                </div>
              </div>

              <!-- Hint -->
              <p v-if="!canSubmit" class="text-xs txt-secondary flex items-center gap-1">
                <Icon icon="mdi:information-outline" class="w-4 h-4" />
                {{ t('feedback.falsePositive.emptyHint') }}
              </p>
            </div>

            <!-- Footer Step 1 -->
            <div
              class="flex justify-end gap-2 p-4 sm:p-6 border-t border-light-border/10 dark:border-dark-border/10"
            >
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary transition-colors"
                @click="emit('close')"
              >
                {{ t('common.cancel') }}
              </button>
              <button
                type="button"
                class="btn-primary px-4 py-2 rounded-xl text-sm font-medium flex items-center gap-2"
                :disabled="!canSubmit || isPreviewLoading"
                @click="handleSubmit"
              >
                <Icon v-if="isPreviewLoading" icon="mdi:loading" class="w-4 h-4 animate-spin" />
                <span>{{ t('common.continue') }}</span>
                <Icon icon="mdi:arrow-right" class="w-4 h-4" />
              </button>
            </div>
          </template>

          <!-- Step 2: Confirm -->
          <template v-else>
            <div class="p-4 sm:p-6 space-y-5">
              <!-- Step indicator -->
              <div class="flex items-center gap-2 text-xs font-medium txt-secondary">
                <div class="flex items-center gap-1.5">
                  <div
                    class="w-5 h-5 rounded-full bg-brand/10 text-brand flex items-center justify-center text-[10px] font-bold"
                  >
                    1
                  </div>
                  <span class="txt-secondary">{{ t('feedback.falsePositive.stepSelect') }}</span>
                </div>
                <Icon icon="mdi:chevron-right" class="w-3.5 h-3.5 txt-secondary/50" />
                <div class="flex items-center gap-1.5">
                  <div
                    class="w-5 h-5 rounded-full bg-brand text-white flex items-center justify-center text-[10px] font-bold"
                  >
                    2
                  </div>
                  <span class="font-semibold txt-primary">{{
                    t('feedback.falsePositive.stepConfirm')
                  }}</span>
                </div>
              </div>

              <!-- Context: User Question (if available) -->
              <div v-if="userMessage" class="surface-chip rounded-xl p-3.5">
                <div class="flex items-center gap-2 text-xs font-medium txt-secondary mb-1.5">
                  <Icon icon="mdi:account-circle-outline" class="w-4 h-4" />
                  {{ t('feedback.falsePositive.contextLabel') }}
                </div>
                <p class="text-sm txt-primary">{{ userMessage }}</p>
              </div>

              <!-- What was wrong - Summary options -->
              <div class="rounded-xl border border-red-500/15 overflow-hidden">
                <div class="px-4 py-2.5 bg-red-500/5 flex items-center gap-2">
                  <div class="w-6 h-6 rounded-full bg-red-500/10 flex items-center justify-center">
                    <Icon icon="mdi:close" class="w-3.5 h-3.5 text-red-500" />
                  </div>
                  <span class="text-sm font-semibold txt-primary">
                    {{ t('feedback.falsePositive.summaryLabel') }}
                  </span>
                </div>
                <div class="p-3 space-y-1.5">
                  <p class="text-[11px] txt-secondary px-1 mb-1">
                    {{ t('feedback.falsePositive.summaryOptionsHint') }}
                  </p>
                  <!-- AI options -->
                  <label
                    v-for="(option, idx) in summaryOptions"
                    :key="`sum-${idx}`"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedSummaryIdx === idx
                        ? 'bg-red-500/5 ring-1 ring-red-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="summary-option"
                      :checked="selectedSummaryIdx === idx"
                      class="mt-0.5 text-red-500 focus:ring-red-500/30"
                      @change="selectedSummaryIdx = idx"
                    />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm txt-primary leading-relaxed">{{ option }}</p>
                      <span
                        v-if="idx === 0"
                        class="text-[10px] font-medium text-red-500/70 mt-0.5 inline-block"
                      >
                        {{ t('feedback.falsePositive.recommended') }}
                      </span>
                    </div>
                  </label>
                  <!-- Custom option -->
                  <label
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedSummaryIdx === 'custom'
                        ? 'bg-red-500/5 ring-1 ring-red-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="summary-option"
                      :checked="selectedSummaryIdx === 'custom'"
                      class="mt-0.5 text-red-500 focus:ring-red-500/30"
                      @change="selectedSummaryIdx = 'custom'"
                    />
                    <div class="flex-1 min-w-0">
                      <span class="text-sm txt-secondary">{{
                        t('feedback.falsePositive.customOption')
                      }}</span>
                      <div v-if="selectedSummaryIdx === 'custom'" class="mt-1.5">
                        <div class="relative">
                          <textarea
                            v-model="customSummary"
                            rows="2"
                            class="w-full px-3 py-2 pr-24 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500/30 resize-none text-sm"
                            :placeholder="t('feedback.falsePositive.summaryPlaceholder')"
                            @input="onCustomSummaryInput"
                          />
                          <button
                            type="button"
                            class="absolute right-1.5 bottom-1.5 flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-medium transition-all"
                            :class="
                              enhancingSummary
                                ? 'bg-brand/20 text-brand cursor-wait'
                                : customSummary.trim()
                                  ? 'bg-brand/10 text-brand hover:bg-brand/20'
                                  : 'bg-gray-500/5 txt-secondary/40 cursor-not-allowed'
                            "
                            :disabled="!customSummary.trim() || enhancingSummary"
                            @click.stop="enhanceSummary"
                          >
                            <Icon
                              :icon="enhancingSummary ? 'mdi:loading' : 'mdi:auto-fix'"
                              :class="['w-3.5 h-3.5', enhancingSummary ? 'animate-spin' : '']"
                            />
                            {{ t('feedback.falsePositive.enhance') }}
                          </button>
                        </div>
                        <Transition name="fade">
                          <p
                            v-if="summaryEnhanceHintShown && !enhancingSummary"
                            class="text-[10px] txt-secondary/60 mt-1 pl-1 flex items-center gap-1"
                          >
                            <Icon icon="mdi:auto-fix" class="w-3 h-3 text-brand/50" />
                            {{ t('feedback.falsePositive.enhanceHint') }}
                          </p>
                        </Transition>
                      </div>
                    </div>
                  </label>
                  <p class="text-[11px] txt-secondary px-1 flex items-center gap-1.5">
                    <Icon icon="mdi:thumb-down-outline" class="w-3 h-3 text-red-400" />
                    {{ t('feedback.falsePositive.savedAsNegative') }}
                  </p>
                </div>
              </div>

              <!-- What is correct - Correction options -->
              <div class="rounded-xl border border-green-500/15 overflow-hidden">
                <div class="px-4 py-2.5 bg-green-500/5 flex items-center gap-2">
                  <div
                    class="w-6 h-6 rounded-full bg-green-500/10 flex items-center justify-center"
                  >
                    <Icon icon="mdi:check" class="w-3.5 h-3.5 text-green-500" />
                  </div>
                  <span class="text-sm font-semibold txt-primary">
                    {{ t('feedback.falsePositive.correctionLabel') }}
                  </span>
                  <span
                    class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-500/10 text-green-600 dark:text-green-400 font-medium ml-auto"
                  >
                    {{ t('feedback.falsePositive.optional') }}
                  </span>
                </div>
                <div class="p-3 space-y-1.5">
                  <p class="text-[11px] txt-secondary px-1 mb-1">
                    {{ t('feedback.falsePositive.correctionOptionsHint') }}
                  </p>
                  <!-- AI options -->
                  <label
                    v-for="(option, idx) in correctionOptions"
                    :key="`cor-${idx}`"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedCorrectionIdx === idx
                        ? 'bg-green-500/5 ring-1 ring-green-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="correction-option"
                      :checked="selectedCorrectionIdx === idx"
                      class="mt-0.5 text-green-500 focus:ring-green-500/30"
                      @change="selectedCorrectionIdx = idx"
                    />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm txt-primary leading-relaxed">{{ option }}</p>
                      <span
                        v-if="idx === 0"
                        class="text-[10px] font-medium text-green-500/70 mt-0.5 inline-block"
                      >
                        {{ t('feedback.falsePositive.recommended') }}
                      </span>
                    </div>
                  </label>
                  <!-- Delete only option (memory classification) -->
                  <label
                    v-if="isMemory"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedCorrectionIdx === 'delete'
                        ? 'bg-red-500/5 ring-1 ring-red-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="correction-option"
                      :checked="selectedCorrectionIdx === 'delete'"
                      class="mt-0.5 text-red-500 focus:ring-red-500/30"
                      @change="selectedCorrectionIdx = 'delete'"
                    />
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <Icon icon="mdi:delete-outline" class="w-4 h-4 text-red-500" />
                        <span class="text-sm font-medium txt-primary">{{
                          t('feedback.falsePositive.deleteMemoryOption')
                        }}</span>
                      </div>
                      <p class="text-[11px] txt-secondary mt-1 leading-relaxed">
                        {{ t('feedback.falsePositive.deleteMemoryOptionHint') }}
                      </p>
                    </div>
                  </label>
                  <!-- Research option -->
                  <label
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedCorrectionIdx === 'research'
                        ? 'bg-blue-500/5 ring-1 ring-blue-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="correction-option"
                      :checked="selectedCorrectionIdx === 'research'"
                      class="mt-0.5 text-blue-500 focus:ring-blue-500/30"
                      @change="selectedCorrectionIdx = 'research'"
                    />
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <Icon icon="mdi:book-search-outline" class="w-4 h-4 text-blue-500" />
                        <span class="text-sm font-medium txt-primary">{{
                          t('feedback.falsePositive.researchOption')
                        }}</span>
                      </div>
                      <p class="text-[11px] txt-secondary mt-1 leading-relaxed">
                        {{ t('feedback.falsePositive.researchOptionHint') }}
                      </p>

                      <!-- Research panel (visible when selected) -->
                      <div
                        v-if="selectedCorrectionIdx === 'research'"
                        class="mt-3 space-y-3"
                        @click.stop
                      >
                        <!-- Tabs: Your files / Web search -->
                        <div class="flex rounded-lg surface-chip overflow-hidden">
                          <button
                            type="button"
                            class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors"
                            :class="
                              researchTab === 'kb'
                                ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400'
                                : 'txt-secondary hover:txt-primary'
                            "
                            @click="researchTab = 'kb'"
                          >
                            <Icon icon="mdi:database-search-outline" class="w-3.5 h-3.5" />
                            {{ t('feedback.falsePositive.researchTabKb') }}
                          </button>
                          <button
                            type="button"
                            class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium transition-colors"
                            :class="
                              researchTab === 'web'
                                ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400'
                                : 'txt-secondary hover:txt-primary'
                            "
                            @click="researchTab = 'web'"
                          >
                            <Icon icon="mdi:web" class="w-3.5 h-3.5" />
                            {{ t('feedback.falsePositive.researchTabWeb') }}
                          </button>
                        </div>

                        <!-- KB tab content -->
                        <template v-if="researchTab === 'kb'">
                          <button
                            v-if="!kbDone"
                            type="button"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium text-blue-600 dark:text-blue-400 bg-blue-500/10 hover:bg-blue-500/15 transition-colors"
                            :disabled="researchLoading || !activeSummary.trim()"
                            @click="startKbSearch"
                          >
                            <Icon
                              :icon="
                                researchLoading ? 'mdi:loading' : 'mdi:database-search-outline'
                              "
                              :class="['w-4 h-4', researchLoading ? 'animate-spin' : '']"
                            />
                            {{
                              researchLoading
                                ? t('feedback.falsePositive.researchLoadingKb')
                                : t('feedback.falsePositive.researchButtonKb')
                            }}
                          </button>
                          <template v-if="kbDone">
                            <div v-if="kbSources.length === 0" class="text-center py-4">
                              <Icon
                                icon="mdi:file-search-outline"
                                class="w-8 h-8 txt-secondary/40 mx-auto mb-2"
                              />
                              <p class="text-sm txt-secondary">
                                {{ t('feedback.falsePositive.researchNoResultsKb') }}
                              </p>
                              <p class="text-[11px] txt-secondary/70 mt-1">
                                {{ t('feedback.falsePositive.researchNoResultsHintKb') }}
                              </p>
                            </div>
                            <template v-else>
                              <p class="text-[11px] txt-secondary font-medium">
                                {{ t('feedback.falsePositive.researchSelectHint') }}
                              </p>
                              <div class="space-y-2 max-h-[30vh] overflow-y-auto scroll-thin">
                                <div
                                  v-for="source in kbSources"
                                  :key="`kb-${source.id}`"
                                  class="rounded-lg border transition-all cursor-pointer"
                                  :class="
                                    selectedSourceKeys.has(`kb-${source.id}`)
                                      ? 'border-green-500/30 bg-green-500/5'
                                      : 'border-light-border/10 dark:border-dark-border/10 hover:border-blue-500/20'
                                  "
                                  @click="toggleSourceKey(`kb-${source.id}`)"
                                >
                                  <div class="p-3 space-y-1.5">
                                    <div class="flex items-center gap-2">
                                      <input
                                        type="checkbox"
                                        :checked="selectedSourceKeys.has(`kb-${source.id}`)"
                                        class="rounded text-green-500 focus:ring-green-500/30"
                                        @change.stop="toggleSourceKey(`kb-${source.id}`)"
                                      />
                                      <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                        <Icon
                                          :icon="sourceTypeDisplay(source.sourceType).icon"
                                          class="w-3.5 h-3.5 shrink-0"
                                          :class="sourceTypeDisplay(source.sourceType).iconColor"
                                        />
                                        <span
                                          v-if="source.sourceType === 'file' && source.fileName"
                                          class="text-xs font-medium txt-primary truncate"
                                          >{{ source.fileName }}</span
                                        >
                                        <span
                                          class="text-[10px] px-1.5 py-0.5 rounded-full font-medium shrink-0"
                                          :class="sourceTypeDisplay(source.sourceType).badgeColor"
                                        >
                                          {{ sourceTypeDisplay(source.sourceType).label }}
                                        </span>
                                      </div>
                                      <span
                                        class="text-[10px] px-1.5 py-0.5 rounded-full font-medium shrink-0"
                                        :class="
                                          source.score >= 0.7
                                            ? 'bg-green-500/10 text-green-600 dark:text-green-400'
                                            : source.score >= 0.5
                                              ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                              : 'bg-gray-500/10 txt-secondary'
                                        "
                                      >
                                        {{ Math.round(source.score * 100) }}%
                                      </span>
                                    </div>
                                    <p class="text-sm txt-primary leading-relaxed pl-6">
                                      {{ source.summary }}
                                    </p>
                                    <p
                                      v-if="source.excerpt !== source.summary"
                                      class="text-[11px] txt-secondary/70 leading-relaxed pl-6 line-clamp-2"
                                    >
                                      {{ source.excerpt }}
                                    </p>
                                  </div>
                                </div>
                              </div>
                              <button
                                type="button"
                                class="flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-medium text-blue-500 hover:bg-blue-500/10 transition-colors"
                                :disabled="researchLoading"
                                @click="kbDone = false"
                              >
                                <Icon icon="mdi:refresh" class="w-3 h-3" />
                                {{ t('feedback.falsePositive.researchButtonKb') }}
                              </button>
                            </template>
                          </template>
                        </template>

                        <!-- Web tab content -->
                        <template v-if="researchTab === 'web'">
                          <button
                            v-if="!webDone"
                            type="button"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium text-blue-600 dark:text-blue-400 bg-blue-500/10 hover:bg-blue-500/15 transition-colors"
                            :disabled="researchLoading || !activeSummary.trim()"
                            @click="startWebSearch"
                          >
                            <Icon
                              :icon="researchLoading ? 'mdi:loading' : 'mdi:web'"
                              :class="['w-4 h-4', researchLoading ? 'animate-spin' : '']"
                            />
                            {{
                              researchLoading
                                ? t('feedback.falsePositive.researchLoadingWeb')
                                : t('feedback.falsePositive.researchButtonWeb')
                            }}
                          </button>
                          <template v-if="webDone">
                            <div v-if="webSources.length === 0" class="text-center py-4">
                              <Icon
                                icon="mdi:web-off"
                                class="w-8 h-8 txt-secondary/40 mx-auto mb-2"
                              />
                              <p class="text-sm txt-secondary">
                                {{ t('feedback.falsePositive.researchNoResultsWeb') }}
                              </p>
                              <p class="text-[11px] txt-secondary/70 mt-1">
                                {{ t('feedback.falsePositive.researchNoResultsHintWeb') }}
                              </p>
                            </div>
                            <template v-else>
                              <p class="text-[11px] txt-secondary font-medium">
                                {{ t('feedback.falsePositive.researchSelectHint') }}
                              </p>
                              <div class="space-y-2 max-h-[30vh] overflow-y-auto scroll-thin">
                                <div
                                  v-for="source in webSources"
                                  :key="`web-${source.id}`"
                                  class="rounded-lg border transition-all cursor-pointer"
                                  :class="
                                    selectedSourceKeys.has(`web-${source.id}`)
                                      ? 'border-green-500/30 bg-green-500/5'
                                      : 'border-light-border/10 dark:border-dark-border/10 hover:border-blue-500/20'
                                  "
                                  @click="toggleSourceKey(`web-${source.id}`)"
                                >
                                  <div class="p-3 space-y-1.5">
                                    <div class="flex items-center gap-2">
                                      <input
                                        type="checkbox"
                                        :checked="selectedSourceKeys.has(`web-${source.id}`)"
                                        class="rounded text-green-500 focus:ring-green-500/30"
                                        @change.stop="toggleSourceKey(`web-${source.id}`)"
                                      />
                                      <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                        <Icon
                                          icon="mdi:web"
                                          class="w-3.5 h-3.5 text-blue-400 shrink-0"
                                        />
                                        <span class="text-xs font-medium txt-primary truncate">{{
                                          source.title
                                        }}</span>
                                      </div>
                                    </div>
                                    <p class="text-sm txt-primary leading-relaxed pl-6">
                                      {{ source.summary }}
                                    </p>
                                    <button
                                      type="button"
                                      class="text-[11px] text-blue-500 hover:underline pl-6 inline-flex items-center gap-1"
                                      @click.stop="openExternalLink(source.url)"
                                    >
                                      <Icon icon="mdi:open-in-new" class="w-3 h-3" />
                                      {{ source.url.replace(/^https?:\/\//, '').split('/')[0] }}
                                    </button>
                                  </div>
                                </div>
                              </div>
                              <button
                                type="button"
                                class="flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-medium text-blue-500 hover:bg-blue-500/10 transition-colors"
                                :disabled="researchLoading"
                                @click="webDone = false"
                              >
                                <Icon icon="mdi:refresh" class="w-3 h-3" />
                                {{ t('feedback.falsePositive.researchButtonWeb') }}
                              </button>
                            </template>
                          </template>
                        </template>

                        <!-- Global selected count (across both tabs) -->
                        <div
                          v-if="selectedSourceCount > 0"
                          class="flex items-center gap-2 text-[11px] text-green-600 dark:text-green-400 font-medium"
                        >
                          <Icon icon="mdi:check-circle" class="w-3.5 h-3.5" />
                          {{
                            t('feedback.falsePositive.researchSelectedCount', selectedSourceCount)
                          }}
                        </div>
                      </div>
                    </div>
                  </label>
                  <!-- Custom option -->
                  <label
                    class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                    :class="
                      selectedCorrectionIdx === 'custom'
                        ? 'bg-green-500/5 ring-1 ring-green-500/25'
                        : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                    "
                  >
                    <input
                      type="radio"
                      name="correction-option"
                      :checked="selectedCorrectionIdx === 'custom'"
                      class="mt-0.5 text-green-500 focus:ring-green-500/30"
                      @change="selectedCorrectionIdx = 'custom'"
                    />
                    <div class="flex-1 min-w-0">
                      <span class="text-sm txt-secondary">{{
                        t('feedback.falsePositive.customOption')
                      }}</span>
                      <div v-if="selectedCorrectionIdx === 'custom'" class="mt-1.5">
                        <div class="relative">
                          <textarea
                            v-model="customCorrection"
                            rows="2"
                            class="w-full px-3 py-2 pr-24 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-green-500/30 resize-none text-sm"
                            :placeholder="t('feedback.falsePositive.correctionPlaceholder')"
                            @input="onCustomCorrectionInput"
                          />
                          <button
                            type="button"
                            class="absolute right-1.5 bottom-1.5 flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-medium transition-all"
                            :class="
                              enhancingCorrection
                                ? 'bg-brand/20 text-brand cursor-wait'
                                : customCorrection.trim()
                                  ? 'bg-brand/10 text-brand hover:bg-brand/20'
                                  : 'bg-gray-500/5 txt-secondary/40 cursor-not-allowed'
                            "
                            :disabled="!customCorrection.trim() || enhancingCorrection"
                            @click.stop="enhanceCorrection"
                          >
                            <Icon
                              :icon="enhancingCorrection ? 'mdi:loading' : 'mdi:auto-fix'"
                              :class="['w-3.5 h-3.5', enhancingCorrection ? 'animate-spin' : '']"
                            />
                            {{ t('feedback.falsePositive.enhance') }}
                          </button>
                        </div>
                        <Transition name="fade">
                          <p
                            v-if="correctionEnhanceHintShown && !enhancingCorrection"
                            class="text-[10px] txt-secondary/60 mt-1 pl-1 flex items-center gap-1"
                          >
                            <Icon icon="mdi:auto-fix" class="w-3 h-3 text-brand/50" />
                            {{ t('feedback.falsePositive.enhanceHint') }}
                          </p>
                        </Transition>
                      </div>
                    </div>
                  </label>
                  <p class="text-[11px] txt-secondary px-1 flex items-center gap-1.5">
                    <template v-if="selectedCorrectionIdx === 'delete'">
                      <Icon icon="mdi:delete-outline" class="w-3 h-3 text-red-400" />
                      {{ t('feedback.falsePositive.willDeleteMemory') }}
                    </template>
                    <template v-else-if="selectedCorrectionIdx === 'research'">
                      <Icon icon="mdi:book-check" class="w-3 h-3 text-blue-400" />
                      {{ t('feedback.falsePositive.researchUseSelected') }}
                    </template>
                    <template v-else-if="isMemory">
                      <Icon icon="mdi:brain" class="w-3 h-3 text-blue-400" />
                      {{ t('feedback.falsePositive.savedAsMemory') }}
                    </template>
                    <template v-else>
                      <Icon icon="mdi:thumb-up-outline" class="w-3 h-3 text-green-400" />
                      {{ t('feedback.falsePositive.savedAsPositive') }}
                    </template>
                  </p>
                </div>
              </div>

              <!-- Info Box - different explanation based on classification -->
              <div
                class="rounded-xl p-3.5 flex items-start gap-3"
                :class="
                  isMemory
                    ? 'bg-blue-500/5 border border-blue-500/10'
                    : 'bg-brand/5 border border-brand/10'
                "
              >
                <Icon
                  :icon="isMemory ? 'mdi:brain' : 'mdi:lightbulb-outline'"
                  :class="['w-5 h-5 shrink-0 mt-0.5', isMemory ? 'text-blue-500' : 'text-brand']"
                />
                <p class="text-xs txt-secondary leading-relaxed">
                  {{
                    isMemory
                      ? t('feedback.falsePositive.memoryExplanation')
                      : t('feedback.falsePositive.explanation')
                  }}
                </p>
              </div>
            </div>

            <!-- Footer Step 2 -->
            <div
              class="border-t border-light-border/10 dark:border-dark-border/10 p-4 sm:p-6 space-y-2.5"
            >
              <!-- Primary: Save -->
              <button
                type="button"
                class="w-full btn-primary px-4 py-3 rounded-xl text-sm font-medium flex items-center justify-center gap-2.5"
                :disabled="!canSave || isSubmitting"
                @click="handleSave"
              >
                <Icon v-if="isSubmitting" icon="mdi:loading" class="w-4.5 h-4.5 animate-spin" />
                <Icon v-else icon="mdi:content-save-check" class="w-4.5 h-4.5" />
                {{ t('feedback.falsePositive.save') }}
              </button>

              <!-- Secondary row: Back + Regenerate -->
              <div class="flex items-center justify-between gap-2">
                <button
                  type="button"
                  class="px-3 py-2 rounded-xl text-xs font-medium txt-secondary hover:txt-primary transition-colors flex items-center gap-1.5"
                  @click="emit('back')"
                >
                  <Icon icon="mdi:arrow-left" class="w-3.5 h-3.5" />
                  {{ t('common.back') }}
                </button>

                <button
                  type="button"
                  class="px-3 py-2 rounded-xl text-xs font-medium surface-chip txt-secondary hover:txt-primary transition-colors flex items-center gap-1.5"
                  :disabled="activeSummary.length === 0 || isSubmitting || isPreviewLoading"
                  :title="t('feedback.falsePositive.againTooltip')"
                  @click="handleRegenerate"
                >
                  <Icon
                    :icon="isPreviewLoading ? 'mdi:loading' : 'mdi:refresh'"
                    :class="['w-3.5 h-3.5', isPreviewLoading ? 'animate-spin' : '']"
                  />
                  {{ t('feedback.falsePositive.again') }}
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>

  <ExternalLinkWarning :url="pendingUrl" :is-open="warningOpen" @close="closeWarning" />
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
