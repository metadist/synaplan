<template>
  <div class="relative">
    <button
      type="button"
      :disabled="disabled || submitted"
      :class="[
        'pill text-xs relative',
        disabled || submitted ? 'opacity-50 cursor-not-allowed' : '',
      ]"
      :aria-label="t('routingFeedback.button')"
      data-testid="btn-routing-feedback"
      @click.stop="toggleDropdown"
    >
      <Icon icon="mdi:swap-horizontal" class="w-4 h-4" />
      <span v-if="submitted" class="font-medium hidden sm:inline">{{
        t('routingFeedback.submitted')
      }}</span>
      <span v-else class="font-medium hidden sm:inline">{{ t('routingFeedback.button') }}</span>
    </button>

    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="transform opacity-0 scale-95 -translate-y-1"
      enter-to-class="transform opacity-100 scale-100 translate-y-0"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="transform opacity-100 scale-100 translate-y-0"
      leave-to-class="transform opacity-0 scale-95 -translate-y-1"
    >
      <div
        v-if="dropdownOpen"
        v-click-outside="closeDropdown"
        class="absolute bottom-full right-0 mb-2 min-w-[12rem] max-w-[16rem] surface-elevated shadow-xl z-[100] rounded-lg overflow-hidden"
        @keydown.escape="closeDropdown"
      >
        <div class="px-3 py-2 border-b border-light-border/30 dark:border-dark-border/20">
          <span class="text-xs font-medium txt-secondary">{{
            t('routingFeedback.selectCorrect')
          }}</span>
        </div>
        <div class="max-h-48 overflow-y-auto">
          <button
            v-for="useCase in useCaseOptions"
            :key="useCase"
            type="button"
            class="w-full text-left px-3 py-1.5 text-xs txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
            @click="submitFeedback(useCase)"
          >
            {{ formatUseCase(useCase) }}
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { submitRoutingFeedback, getRoutingUseCases } from '@/services/api/routingApi'
import { useNotification } from '@/composables/useNotification'

const { t } = useI18n()
const { success, error } = useNotification()

const props = defineProps<{
  messageId: number
  disabled?: boolean
}>()

const dropdownOpen = ref(false)
const submitted = ref(false)
const useCaseOptions = ref<string[]>([])

onMounted(async () => {
  try {
    useCaseOptions.value = await getRoutingUseCases()
  } catch {
    useCaseOptions.value = [
      'text_chat',
      'coding',
      'image_generation',
      'video_generation',
      'audio_generation',
      'file_generation',
      'file_analysis',
      'email_send',
      'web_search',
      'summarize',
    ]
  }
})

const toggleDropdown = () => {
  if (!props.disabled && !submitted.value) {
    dropdownOpen.value = !dropdownOpen.value
  }
}

const closeDropdown = () => {
  dropdownOpen.value = false
}

const submitFeedback = async (correctUseCase: string) => {
  closeDropdown()
  try {
    await submitRoutingFeedback(props.messageId, correctUseCase)
    submitted.value = true
    success(t('routingFeedback.success'))
  } catch {
    error(t('routingFeedback.error'))
  }
}

const formatUseCase = (useCase: string): string => {
  return useCase.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}
</script>
