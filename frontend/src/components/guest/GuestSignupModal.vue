<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="isOpen"
        data-testid="guest-signup-modal"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="$emit('close')" />

        <div
          class="relative w-full max-w-md rounded-2xl overflow-hidden shadow-2xl animate-modal-enter"
        >
          <!-- Gradient header -->
          <div
            class="relative px-8 pt-10 pb-8 text-center text-white"
            style="background: linear-gradient(135deg, var(--brand) 0%, #1a2980 100%)"
          >
            <button
              class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center transition-colors"
              data-testid="guest-signup-close"
              @click="$emit('close')"
            >
              <Icon icon="mdi:close" class="w-4 h-4" />
            </button>
            <div
              class="mx-auto mb-4 w-16 h-16 rounded-2xl bg-white/15 backdrop-blur-sm flex items-center justify-center"
            >
              <Icon icon="mdi:rocket-launch-outline" class="w-8 h-8" />
            </div>
            <h2 class="text-2xl font-bold mb-2">
              {{ $t('guest.limitModal.title') }}
            </h2>
            <p class="text-sm text-white/80">
              {{ $t('guest.limitModal.subtitle') }}
            </p>
          </div>

          <!-- Features list -->
          <div class="bg-white dark:bg-gray-900 px-8 py-6">
            <ul class="space-y-3.5">
              <li v-for="(feature, key) in features" :key="key" class="flex items-center gap-3">
                <div
                  class="flex-shrink-0 w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center"
                >
                  <Icon :icon="feature.icon" class="w-4.5 h-4.5 text-brand" />
                </div>
                <span class="text-sm txt-primary">
                  {{ $t(`guest.limitModal.features.${key}`) }}
                </span>
              </li>
            </ul>
          </div>

          <!-- CTA buttons -->
          <div class="bg-white dark:bg-gray-900 px-8 pb-8 pt-2 space-y-3">
            <router-link
              to="/register"
              data-testid="guest-modal-register"
              class="block w-full py-3 rounded-xl bg-brand text-white text-center font-semibold text-sm hover:bg-brand-hover transition-colors shadow-lg shadow-brand/25"
            >
              {{ $t('guest.limitModal.registerButton') }}
            </router-link>
            <router-link
              to="/login"
              data-testid="guest-modal-login"
              class="block w-full py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-center text-sm font-medium txt-secondary hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            >
              {{ $t('guest.limitModal.loginButton') }}
            </router-link>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

defineProps<{
  isOpen: boolean
}>()

defineEmits<{
  close: []
}>()

const features = {
  memories: { icon: 'mdi:brain' },
  history: { icon: 'mdi:history' },
  files: { icon: 'mdi:file-document-outline' },
  models: { icon: 'mdi:creation-outline' },
} as const
</script>

<style scoped>
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.3s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

@keyframes modal-enter {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
.animate-modal-enter {
  animation: modal-enter 0.35s ease-out;
}
</style>
