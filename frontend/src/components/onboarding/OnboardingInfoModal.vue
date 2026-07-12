<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="modal-onboarding-info"
      >
        <div
          class="absolute inset-0 bg-black/40 backdrop-blur-sm"
          data-testid="modal-onboarding-info-backdrop"
          @click="emit('close')"
        ></div>

        <div
          class="relative surface-elevated max-w-md w-full p-6 rounded-2xl"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
        >
          <div class="flex items-start gap-4">
            <div
              class="w-11 h-11 rounded-xl bg-brand/10 dark:bg-brand/20 flex items-center justify-center flex-shrink-0"
            >
              <Icon :icon="icon" class="w-6 h-6 text-brand" />
            </div>
            <div class="min-w-0 flex-1">
              <h2 class="text-lg font-bold txt-primary">{{ title }}</h2>
              <p class="text-sm txt-secondary mt-1 leading-relaxed">{{ description }}</p>
            </div>
          </div>

          <ul v-if="points.length > 0" class="mt-4 space-y-2.5">
            <li v-for="(point, idx) in points" :key="idx" class="flex items-start gap-2.5">
              <Icon
                icon="mdi:check-circle"
                class="w-4 h-4 text-green-500 flex-shrink-0 mt-0.5"
                aria-hidden="true"
              />
              <span class="text-sm txt-secondary leading-snug">{{ point }}</span>
            </li>
          </ul>

          <a
            v-if="linkUrl"
            :href="linkUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:underline underline-offset-2"
            data-testid="link-onboarding-info"
          >
            <Icon icon="mdi:open-in-new" class="w-4 h-4" aria-hidden="true" />
            {{ linkLabel }}
          </a>

          <button
            class="mt-6 w-full py-2.5 rounded-xl btn-primary font-semibold text-sm transition-all duration-200 active:scale-[0.98]"
            data-testid="btn-onboarding-info-close"
            @click="emit('close')"
          >
            {{ $t('common.close') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
/**
 * Lightweight info modal used in the onboarding server step to explain the
 * standard server and the own-server option in plain language. Content is
 * passed in already-translated so this stays a dumb presentational component.
 */
import { Icon } from '@iconify/vue'

interface Props {
  isOpen: boolean
  icon: string
  title: string
  description: string
  points?: string[]
  linkLabel?: string
  linkUrl?: string
}

withDefaults(defineProps<Props>(), {
  points: () => [],
  linkLabel: '',
  linkUrl: '',
})

const emit = defineEmits<{ close: [] }>()
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-active .surface-elevated,
.modal-leave-active .surface-elevated {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}
.modal-enter-from .surface-elevated,
.modal-leave-to .surface-elevated {
  transform: scale(0.95) translateY(-10px);
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .modal-enter-active,
  .modal-leave-active,
  .modal-enter-active .surface-elevated,
  .modal-leave-active .surface-elevated {
    transition: none;
  }
}
</style>
