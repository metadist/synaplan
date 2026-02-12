<template>
  <div v-if="feedbacks.length > 0" class="mt-3">
    <button
      class="flex items-center gap-2 px-3 py-2 rounded-lg surface-chip hover:bg-black/5 dark:hover:bg-white/5 transition-all w-full text-left"
      @click="toggleExpand"
    >
      <Icon icon="mdi:alert-circle-check-outline" class="w-4 h-4 flex-shrink-0 txt-brand" />
      <span class="text-xs font-medium txt-secondary flex-1">
        {{ $t('feedback.usedInResponse', { count: feedbacks.length }) }}
      </span>
      <Icon
        :icon="isExpanded ? 'mdi:chevron-up' : 'mdi:chevron-down'"
        class="w-4 h-4 txt-secondary transition-transform flex-shrink-0"
      />
    </button>

    <!-- Expandable Content -->
    <Transition name="expand">
      <div v-if="isExpanded" class="mt-2 space-y-2">
        <div
          v-for="(feedback, index) in feedbacks"
          :key="feedback.id"
          :ref="(el) => (feedbackRefs[index] = el as HTMLElement)"
          :class="[
            'surface-chip rounded-lg p-2.5 transition-all cursor-pointer',
            highlightedFeedback === index
              ? 'ring-2 ring-brand bg-brand-alpha-light'
              : 'hover:bg-black/5 dark:hover:bg-white/5',
          ]"
          @click="navigateToFeedback(feedback)"
        >
          <div class="flex items-start gap-2">
            <div
              class="flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center"
              :class="feedback.type === 'false_positive' ? 'bg-red-500/10' : 'bg-green-500/10'"
            >
              <Icon
                :icon="feedback.type === 'false_positive' ? 'mdi:close' : 'mdi:check'"
                :class="[
                  'w-3 h-3',
                  feedback.type === 'false_positive' ? 'text-red-500' : 'text-green-500',
                ]"
              />
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-1.5 mb-1">
                <span
                  class="text-[10px] px-1.5 py-0.5 rounded-full font-medium"
                  :class="
                    feedback.type === 'false_positive'
                      ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                      : 'bg-green-500/10 text-green-600 dark:text-green-400'
                  "
                >
                  {{
                    feedback.type === 'false_positive'
                      ? $t('feedback.list.typeFalsePositive')
                      : $t('feedback.list.typePositive')
                  }}
                </span>
              </div>
              <div class="text-xs txt-primary">{{ feedback.value }}</div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'

interface FeedbackItem {
  id: number
  type: 'false_positive' | 'positive'
  value: string
}

interface Props {
  feedbacks: FeedbackItem[]
}

const props = defineProps<Props>()
const router = useRouter()

const isExpanded = ref(false)
const highlightedFeedback = ref<number | null>(null)
const feedbackRefs = ref<HTMLElement[]>([])

const toggleExpand = () => {
  isExpanded.value = !isExpanded.value
}

const navigateToFeedback = (feedback: FeedbackItem) => {
  router.push({
    name: 'feedbacks',
    query: { highlight: String(feedback.id), edit: String(feedback.id) },
  })
}

// Listen for feedback reference clicks from the message text
const handleFeedbackRefClick = (event: CustomEvent) => {
  const { feedbackId } = event.detail as { feedbackId: number }
  const feedbackIndex = props.feedbacks.findIndex((f) => f.id === feedbackId)
  if (feedbackIndex >= 0) {
    highlightedFeedback.value = feedbackIndex

    // Auto-expand if collapsed
    if (!isExpanded.value) {
      isExpanded.value = true
    }

    // Scroll to the feedback after expansion
    setTimeout(() => {
      const feedbackEl = feedbackRefs.value[feedbackIndex]
      if (feedbackEl) {
        feedbackEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      }
    }, 300)

    // Remove highlight after 2 seconds
    setTimeout(() => {
      highlightedFeedback.value = null
    }, 2000)
  }
}

onMounted(() => {
  window.addEventListener('open-feedback-dialog', handleFeedbackRefClick as EventListener)
})

onUnmounted(() => {
  window.removeEventListener('open-feedback-dialog', handleFeedbackRefClick as EventListener)
})
</script>

<style scoped>
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 400px;
  opacity: 1;
}
</style>
