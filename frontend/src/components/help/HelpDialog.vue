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
      <div
        v-if="show"
        class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        data-testid="modal-help"
        @click.self="$emit('close')"
      >
        <Transition
          enter-active-class="transition-all duration-200"
          enter-from-class="scale-95 opacity-0"
          enter-to-class="scale-100 opacity-100"
          leave-active-class="transition-all duration-150"
          leave-from-class="scale-100 opacity-100"
          leave-to-class="scale-95 opacity-0"
        >
          <div
            v-if="show"
            class="surface-card rounded-xl shadow-2xl max-w-2xl w-full max-h-[85vh] overflow-hidden flex flex-col"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="content ? 'help-title' : undefined"
            data-testid="modal-help-body"
          >
            <!-- Header -->
            <div
              class="flex items-center justify-between p-6 border-b border-light-border/30 dark:border-dark-border/20"
            >
              <div class="flex items-center gap-3">
                <div
                  class="w-10 h-10 rounded-full bg-[var(--brand)]/10 flex items-center justify-center"
                >
                  <QuestionMarkCircleIcon class="w-6 h-6 text-[var(--brand)]" />
                </div>
                <h2 id="help-title" class="text-xl font-semibold txt-primary">
                  {{ content?.title || $t('help.title') }}
                </h2>
              </div>
              <button
                ref="closeButton"
                class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary hover:txt-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
                :aria-label="$t('help.close')"
                data-testid="btn-close"
                @click="$emit('close')"
              >
                <XMarkIcon class="w-6 h-6 mx-auto" />
              </button>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto scroll-thin p-6">
              <p v-if="content?.description" class="txt-secondary mb-6">
                {{ content.description }}
              </p>

              <div v-if="content?.steps" class="space-y-4">
                <div
                  v-for="(step, index) in content.steps"
                  :key="index"
                  class="flex gap-4"
                  data-testid="item-step"
                >
                  <div
                    class="flex-shrink-0 w-8 h-8 rounded-full bg-[var(--brand)] text-white flex items-center justify-center font-semibold text-sm"
                  >
                    {{ index + 1 }}
                  </div>
                  <div class="flex-1">
                    <h3 class="font-semibold txt-primary mb-1">{{ step.title }}</h3>
                    <p class="txt-secondary text-sm">{{ step.content }}</p>
                  </div>
                </div>
              </div>

              <div v-else class="text-center txt-secondary py-8">
                {{ $t('help.noContent') }}
              </div>
            </div>

            <!-- Footer -->
            <div class="p-6 border-t border-light-border/30 dark:border-dark-border/20">
              <button
                class="btn-primary w-full py-3 rounded-lg font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--brand)]"
                data-testid="btn-confirm"
                @click="$emit('close')"
              >
                {{ $t('help.gotIt') }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { QuestionMarkCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import type { HelpContent } from '@/data/helpContent'

interface Props {
  show: boolean
  content: HelpContent | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  close: []
}>()

const closeButton = ref<HTMLButtonElement | null>(null)

// Handle escape key
const handleKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && props.show) {
    e.preventDefault()
    emit('close')
  }
}

watch(
  () => props.show,
  (newVal) => {
    if (newVal) {
      document.addEventListener('keydown', handleKeydown)
      setTimeout(() => closeButton.value?.focus(), 100)
    } else {
      document.removeEventListener('keydown', handleKeydown)
    }
  }
)
</script>
