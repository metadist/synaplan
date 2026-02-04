<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useMarkdown } from '@/composables/useMarkdown'

interface Props {
  isOpen: boolean
  segments: string[]
  fullText: string
  step: 'select' | 'confirm'
  summary: string
  correction: string
  userMessage?: string
  isSubmitting?: boolean
  isPreviewLoading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  userMessage: '',
  isSubmitting: false,
  isPreviewLoading: false,
})

const emit = defineEmits<{
  close: []
  preview: [text: string]
  save: [data: { summary: string; correction: string }] // Single save event with both values
  back: []
  regenerate: [correction: string] // Regenerate correction based on false claim
}>()

const { t } = useI18n()
const { render: renderMarkdown } = useMarkdown()
const selectedIndexes = ref<Set<number>>(new Set())
const useFullText = ref(false)

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

const summaryDraft = ref('')
const correctionDraft = ref('')

const canConfirmFalsePositive = computed(() => summaryDraft.value.trim().length > 0)
const canConfirmPositive = computed(() => correctionDraft.value.trim().length > 0)

watch(
  () => [props.isOpen, props.segments],
  () => {
    if (!props.isOpen) {
      return
    }
    selectedIndexes.value = new Set()
    useFullText.value = false
    summaryDraft.value = props.summary
    correctionDraft.value = props.correction
  },
  { deep: true }
)

watch(
  () => props.summary,
  (value) => {
    summaryDraft.value = value
  }
)

watch(
  () => props.correction,
  (value) => {
    correctionDraft.value = value
  }
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

// Handle "Regenerate" - regenerate the CORRECTION based on the false claim (summary)
// Sends the old correction so the AI knows what was wrong and can generate a better one
const handleRegenerate = () => {
  // Need the summary (false claim) to regenerate the correction
  if (!canConfirmFalsePositive.value) {
    return
  }
  // Emit with the current (wrong) correction - parent will regenerate a better one
  emit('regenerate', correctionDraft.value.trim())
}

// Save both false positive and correction as feedback in a SINGLE request
const handleSave = () => {
  if (!canConfirmFalsePositive.value && !canConfirmPositive.value) {
    return
  }
  emit('save', {
    summary: summaryDraft.value.trim(),
    correction: correctionDraft.value.trim(),
  })
}
</script>

<template>
  <Teleport to="body">
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
              <div class="space-y-2 max-h-[40vh] overflow-y-auto scroll-thin">
                <div
                  v-for="(segment, index) in segments"
                  :key="`segment-${index}`"
                  class="rounded-xl transition-all cursor-pointer"
                  :class="[
                    selectedIndexes.has(index) && !useFullText
                      ? 'surface-chip ring-2 ring-brand/50'
                      : 'surface-chip hover:ring-1 hover:ring-brand/20',
                  ]"
                  @click="!useFullText && toggleSegment(index)"
                >
                  <label class="flex items-start gap-3 p-3 cursor-pointer">
                    <input
                      :checked="selectedIndexes.has(index)"
                      type="checkbox"
                      class="mt-1 rounded border-gray-300 text-brand focus:ring-brand"
                      :disabled="useFullText"
                      @change.stop="toggleSegment(index)"
                    />
                    <div
                      class="text-sm txt-primary prose prose-sm dark:prose-invert max-w-none"
                      v-html="renderMarkdown(segment)"
                    />
                  </label>
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
              <!-- Context: User Question (if available) -->
              <div v-if="userMessage" class="surface-chip rounded-xl p-4">
                <div class="flex items-center gap-2 text-xs font-medium txt-secondary mb-2">
                  <Icon icon="mdi:account-circle-outline" class="w-4 h-4" />
                  {{ t('feedback.falsePositive.contextLabel') }}
                </div>
                <p class="text-sm txt-primary">{{ userMessage }}</p>
              </div>

              <!-- What was wrong - Negative Example -->
              <div>
                <label class="flex items-center gap-2 text-sm font-medium txt-primary mb-2">
                  <div class="w-5 h-5 rounded-full bg-red-500/10 flex items-center justify-center">
                    <Icon icon="mdi:close" class="w-3 h-3 text-red-500" />
                  </div>
                  {{ t('feedback.falsePositive.summaryLabel') }}
                </label>
                <textarea
                  v-model="summaryDraft"
                  rows="2"
                  class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500/30 resize-none transition-all text-sm"
                  :placeholder="t('feedback.falsePositive.summaryPlaceholder')"
                />
                <p class="text-xs txt-secondary mt-1 flex items-center gap-1">
                  <Icon icon="mdi:thumb-down-outline" class="w-3 h-3 text-red-500" />
                  {{ t('feedback.falsePositive.savedAsNegative') }}
                </p>
              </div>

              <!-- What is correct - Positive Example -->
              <div>
                <label class="flex items-center gap-2 text-sm font-medium txt-primary mb-2">
                  <div
                    class="w-5 h-5 rounded-full bg-green-500/10 flex items-center justify-center"
                  >
                    <Icon icon="mdi:check" class="w-3 h-3 text-green-500" />
                  </div>
                  {{ t('feedback.falsePositive.correctionLabel') }}
                </label>
                <textarea
                  v-model="correctionDraft"
                  rows="2"
                  class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-green-500/30 resize-none transition-all text-sm"
                  :placeholder="t('feedback.falsePositive.correctionPlaceholder')"
                />
                <p class="text-xs txt-secondary mt-1 flex items-center gap-1">
                  <Icon icon="mdi:thumb-up-outline" class="w-3 h-3 text-green-500" />
                  {{ t('feedback.falsePositive.savedAsPositive') }}
                </p>
              </div>

              <!-- Info Box -->
              <div class="surface-chip rounded-xl p-4 flex items-start gap-3">
                <Icon icon="mdi:lightbulb-outline" class="w-5 h-5 txt-brand shrink-0 mt-0.5" />
                <p class="text-xs txt-secondary leading-relaxed">
                  {{ t('feedback.falsePositive.explanation') }}
                </p>
              </div>
            </div>

            <!-- Footer Step 2 -->
            <div
              class="flex flex-wrap justify-between gap-3 p-4 sm:p-6 border-t border-light-border/10 dark:border-dark-border/10"
            >
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary transition-colors flex items-center gap-2"
                @click="emit('back')"
              >
                <Icon icon="mdi:arrow-left" class="w-4 h-4" />
                {{ t('common.back') }}
              </button>

              <div class="flex flex-wrap gap-2">
                <!-- Regenerate Button - regenerates the CORRECTION based on the false claim -->
                <button
                  type="button"
                  class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary transition-colors flex items-center gap-2"
                  :disabled="!canConfirmFalsePositive || isSubmitting || isPreviewLoading"
                  :title="t('feedback.falsePositive.againTooltip')"
                  @click="handleRegenerate"
                >
                  <Icon
                    :icon="isPreviewLoading ? 'mdi:loading' : 'mdi:refresh'"
                    :class="['w-4 h-4', isPreviewLoading ? 'animate-spin' : '']"
                  />
                  {{ t('feedback.falsePositive.again') }}
                </button>

                <!-- Save Button -->
                <button
                  type="button"
                  class="btn-primary px-4 py-2 rounded-xl text-sm font-medium flex items-center gap-2"
                  :disabled="(!canConfirmFalsePositive && !canConfirmPositive) || isSubmitting"
                  @click="handleSave"
                >
                  <Icon v-if="isSubmitting" icon="mdi:loading" class="w-4 h-4 animate-spin" />
                  <Icon v-else icon="mdi:content-save" class="w-4 h-4" />
                  {{ t('feedback.falsePositive.save') }}
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>
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
