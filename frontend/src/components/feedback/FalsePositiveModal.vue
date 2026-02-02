<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'

interface Props {
  isOpen: boolean
  segments: string[]
  fullText: string
  step: 'select' | 'confirm'
  summary: string
  correction: string
  isSubmitting?: boolean
  isPreviewLoading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  isSubmitting: false,
  isPreviewLoading: false,
})

const emit = defineEmits<{
  close: []
  preview: [text: string]
  confirmFalsePositive: [summary: string]
  confirmPositive: [correction: string]
  back: []
}>()

const { t } = useI18n()
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
</script>

<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4"
      @click.self="emit('close')"
    >
      <div class="surface-card w-full max-w-2xl p-6 rounded-2xl">
        <div class="flex items-start justify-between gap-4 mb-4">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full flex items-center justify-center surface-chip">
              <Icon icon="mdi:alert-circle-outline" class="w-5 h-5 txt-brand" />
            </div>
            <div>
              <h2 class="text-xl font-semibold txt-primary">
                {{ t('feedback.falsePositive.title') }}
              </h2>
              <p class="text-sm txt-secondary mt-1">
                {{ t('feedback.falsePositive.subtitle') }}
              </p>
            </div>
          </div>
          <button class="icon-ghost" :aria-label="t('common.close')" @click="emit('close')">
            ✕
          </button>
        </div>

        <template v-if="step === 'select'">
          <div class="flex flex-wrap items-center gap-2 mb-4">
            <button class="surface-chip px-3 py-1 text-xs" @click="selectAll">
              {{ t('feedback.falsePositive.selectAll') }}
            </button>
            <button class="surface-chip px-3 py-1 text-xs" @click="clearAll">
              {{ t('feedback.falsePositive.clear') }}
            </button>
            <label class="flex items-center gap-2 text-xs txt-secondary cursor-pointer">
              <input v-model="useFullText" type="checkbox" class="rounded" />
              {{ t('feedback.falsePositive.useFull') }}
            </label>
            <span class="text-xs txt-secondary ml-auto">
              {{ t('feedback.falsePositive.selectedCount', { count: selectedCount }) }}
            </span>
          </div>

          <div class="space-y-2 max-h-[50vh] overflow-y-auto scroll-thin">
            <div
              v-for="(segment, index) in segments"
              :key="`segment-${index}`"
              :class="[
                'surface-chip p-3 transition-colors',
                selectedIndexes.has(index) && !useFullText ? 'pill--active' : '',
              ]"
            >
              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  :checked="selectedIndexes.has(index)"
                  type="checkbox"
                  class="mt-1 rounded"
                  @change="toggleSegment(index)"
                  :disabled="useFullText"
                />
                <div class="text-sm txt-secondary whitespace-pre-wrap">
                  {{ segment }}
                </div>
              </label>
            </div>
          </div>

          <p v-if="!canSubmit" class="text-xs txt-secondary mt-3">
            {{ t('feedback.falsePositive.emptyHint') }}
          </p>

          <div class="flex justify-end gap-2 mt-5">
            <button class="surface-chip px-4 py-2" @click="emit('close')">
              {{ t('feedback.falsePositive.cancel') }}
            </button>
            <button
              class="btn-primary px-4 py-2"
              :disabled="!canSubmit || isPreviewLoading"
              @click="handleSubmit"
            >
              {{ t('feedback.falsePositive.preview') }}
            </button>
          </div>
        </template>

        <template v-else>
          <div class="space-y-4">
            <div>
              <label class="text-xs txt-secondary block mb-1">
                {{ t('feedback.falsePositive.summaryLabel') }}
              </label>
              <textarea
                v-model="summaryDraft"
                rows="2"
                class="w-full surface-card p-3 rounded-lg text-sm txt-primary"
              />
            </div>
            <div>
              <label class="text-xs txt-secondary block mb-1">
                {{ t('feedback.falsePositive.correctionLabel') }}
              </label>
              <textarea
                v-model="correctionDraft"
                rows="2"
                class="w-full surface-card p-3 rounded-lg text-sm txt-primary"
              />
            </div>
          </div>

          <div class="flex flex-wrap justify-between gap-2 mt-5">
            <button class="surface-chip px-4 py-2" @click="emit('back')">
              {{ t('feedback.falsePositive.back') }}
            </button>
            <div class="flex flex-wrap gap-2">
              <button
                class="surface-chip px-4 py-2"
                :disabled="!canConfirmFalsePositive || isSubmitting"
                @click="emit('confirmFalsePositive', summaryDraft.trim())"
              >
                {{ t('feedback.falsePositive.saveNegative') }}
              </button>
              <button
                class="btn-primary px-4 py-2"
                :disabled="!canConfirmPositive || isSubmitting"
                @click="emit('confirmPositive', correctionDraft.trim())"
              >
                {{ t('feedback.falsePositive.savePositive') }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </Teleport>
</template>
