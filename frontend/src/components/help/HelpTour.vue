<template>
  <Teleport to="#app">
    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="show && currentStepData" class="fixed inset-0 z-50" data-testid="modal-help-tour">
        <!-- Backdrop with spotlight -->
        <div
          class="absolute inset-0 bg-black transition-opacity duration-300 pointer-events-auto"
          :style="{ opacity: 0.7 }"
          data-testid="modal-backdrop"
          @click="onBackdropClick"
        ></div>

        <!-- Spotlight cutout -->
        <div
          v-if="highlightedElement"
          class="absolute border-4 border-[var(--brand)] rounded-lg transition-all duration-300 pointer-events-none shadow-2xl"
          :style="spotlightStyle"
          data-testid="spotlight"
        ></div>

        <!-- Tooltip/Step content -->
        <div
          v-if="tooltipStyle"
          class="absolute surface-card rounded-xl shadow-2xl p-5 max-w-md transition-all duration-300"
          :style="tooltipStyle"
          data-testid="section-step"
        >
          <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2">
              <div
                class="w-8 h-8 rounded-full bg-[var(--brand)] text-white flex items-center justify-center font-semibold text-sm"
              >
                {{ currentStepIndex + 1 }}
              </div>
              <h3 class="text-lg font-semibold txt-primary">{{ currentStepData.title }}</h3>
            </div>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary"
              :aria-label="$t('help.close')"
              data-testid="btn-close"
              @click="$emit('close')"
            >
              <XMarkIcon class="w-5 h-5 mx-auto" />
            </button>
          </div>

          <p class="txt-secondary mb-4 text-sm leading-relaxed">{{ currentStepData.content }}</p>

          <div class="flex items-center justify-between">
            <div class="text-xs txt-secondary">{{ currentStepIndex + 1 }} / {{ steps.length }}</div>
            <div class="flex gap-2">
              <button
                v-if="currentStepIndex > 0"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors text-sm"
                data-testid="btn-prev"
                @click="prevStep"
              >
                {{ $t('mail.previous') }}
              </button>
              <button
                v-if="currentStepIndex < steps.length - 1"
                class="btn-primary px-5 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-next"
                @click="nextStep"
              >
                {{ $t('mail.next') }}
              </button>
              <button
                v-else
                class="btn-primary px-5 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-finish"
                @click="finish"
              >
                {{ $t('help.gotIt') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import type { HelpStep } from '@/data/helpContent'

interface Props {
  show: boolean
  steps: HelpStep[]
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const currentStepIndex = ref(0)
const highlightedElement = ref<HTMLElement | null>(null)

const currentStepData = computed(() => props.steps[currentStepIndex.value])

const spotlightStyle = computed(() => {
  if (!highlightedElement.value) return null
  const rect = highlightedElement.value.getBoundingClientRect()
  return {
    top: `${rect.top - 8}px`,
    left: `${rect.left - 8}px`,
    width: `${rect.width + 16}px`,
    height: `${rect.height + 16}px`,
  }
})

const tooltipStyle = computed(() => {
  const tooltipHeight = 200 // Estimated
  const tooltipWidth = 400

  if (!highlightedElement.value) {
    // Center on screen if no element is highlighted
    return {
      top: '50%',
      left: '50%',
      transform: 'translate(-50%, -50%)',
    }
  }

  const rect = highlightedElement.value.getBoundingClientRect()

  // Position below element by default
  let top = rect.bottom + 16
  let left = rect.left

  // If too close to bottom, position above
  if (top + tooltipHeight > window.innerHeight - 20) {
    top = rect.top - tooltipHeight - 16
  }

  // If too close to right edge
  if (left + tooltipWidth > window.innerWidth - 20) {
    left = window.innerWidth - tooltipWidth - 20
  }

  // If too close to left edge
  if (left < 20) {
    left = 20
  }

  return {
    top: `${Math.max(20, top)}px`,
    left: `${left}px`,
  }
})

const updateHighlight = async () => {
  await nextTick()
  const step = currentStepData.value
  if (!step?.selector) {
    highlightedElement.value = null
    return
  }

  const element = document.querySelector(step.selector) as HTMLElement
  if (element) {
    highlightedElement.value = element
    // Scroll into view
    element.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
}

const nextStep = () => {
  if (currentStepIndex.value < props.steps.length - 1) {
    currentStepIndex.value++
    updateHighlight()
  }
}

const prevStep = () => {
  if (currentStepIndex.value > 0) {
    currentStepIndex.value--
    updateHighlight()
  }
}

const onBackdropClick = () => {
  // Don't close on backdrop click to prevent accidental closes
}

const finish = () => {
  emit('close')
}

watch(
  () => props.show,
  (newVal) => {
    if (newVal) {
      currentStepIndex.value = 0
      updateHighlight()
    } else {
      highlightedElement.value = null
    }
  }
)

watch(
  () => props.steps,
  () => {
    if (props.show) {
      currentStepIndex.value = 0
      updateHighlight()
    }
  }
)

onMounted(() => {
  if (props.show) {
    updateHighlight()
  }
})
</script>
