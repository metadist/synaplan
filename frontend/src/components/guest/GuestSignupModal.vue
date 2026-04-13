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
          <!-- Gradient header with animated particles -->
          <div
            class="relative px-8 pt-10 pb-8 text-center text-white overflow-hidden"
            style="background: linear-gradient(135deg, var(--brand) 0%, #1a2980 100%)"
          >
            <!-- Subtle floating dots -->
            <div class="absolute inset-0 pointer-events-none overflow-hidden">
              <div class="modal-particle modal-particle-1"></div>
              <div class="modal-particle modal-particle-2"></div>
              <div class="modal-particle modal-particle-3"></div>
            </div>

            <button
              class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95"
              data-testid="guest-signup-close"
              @click="$emit('close')"
            >
              <Icon icon="mdi:close" class="w-4 h-4" />
            </button>

            <div class="modal-icon-wrapper mx-auto mb-4">
              <div class="modal-icon-glow"></div>
              <div
                class="relative w-16 h-16 rounded-2xl bg-white/15 backdrop-blur-sm flex items-center justify-center"
              >
                <Icon icon="mdi:rocket-launch-outline" class="w-8 h-8 modal-icon-bounce" />
              </div>
            </div>
            <h2 class="text-2xl font-bold mb-2 modal-text-enter" style="--text-delay: 0.15s">
              {{ $t('guest.limitModal.title') }}
            </h2>
            <p class="text-sm text-white/80 modal-text-enter" style="--text-delay: 0.25s">
              {{ $t('guest.limitModal.subtitle') }}
            </p>
          </div>

          <!-- Features list with staggered entrance -->
          <div class="bg-white dark:bg-gray-900 px-8 py-6">
            <ul class="space-y-3.5">
              <li
                v-for="(feature, key, index) in features"
                :key="key"
                class="flex items-center gap-3 modal-feature-enter"
                :style="{ '--feature-delay': `${0.3 + index * 0.07}s` }"
              >
                <div
                  class="flex-shrink-0 w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center transition-transform duration-200 hover:scale-110"
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
              class="group relative block w-full py-3 rounded-xl bg-brand text-white text-center font-semibold text-sm overflow-hidden transition-all duration-200 hover:shadow-lg hover:shadow-brand/30 active:scale-[0.98]"
            >
              <span class="relative z-10">{{ $t('guest.limitModal.registerButton') }}</span>
              <span class="cta-shimmer" />
            </router-link>
            <router-link
              to="/login"
              data-testid="guest-modal-login"
              class="block w-full py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-center text-sm font-medium txt-secondary hover:bg-gray-50 dark:hover:bg-gray-800 transition-all duration-200 active:scale-[0.98]"
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
  animation: modal-enter 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Icon bounce on enter */
@keyframes iconBounce {
  0% {
    transform: scale(0) rotate(-15deg);
  }
  60% {
    transform: scale(1.15) rotate(3deg);
  }
  80% {
    transform: scale(0.95) rotate(-1deg);
  }
  100% {
    transform: scale(1) rotate(0deg);
  }
}
.modal-icon-bounce {
  animation: iconBounce 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s both;
}

/* Icon glow pulse */
@keyframes iconGlow {
  0%,
  100% {
    opacity: 0.3;
    transform: scale(1);
  }
  50% {
    opacity: 0.6;
    transform: scale(1.2);
  }
}
.modal-icon-wrapper {
  position: relative;
  width: 4rem;
  height: 4rem;
}
.modal-icon-glow {
  position: absolute;
  inset: -4px;
  border-radius: 1rem;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
  animation: iconGlow 3s ease-in-out infinite;
}

/* Text stagger */
@keyframes textEnter {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.modal-text-enter {
  animation: textEnter 0.4s ease-out both;
  animation-delay: var(--text-delay, 0s);
}

/* Feature stagger */
@keyframes featureEnter {
  from {
    opacity: 0;
    transform: translateX(-8px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}
.modal-feature-enter {
  animation: featureEnter 0.35s ease-out both;
  animation-delay: var(--feature-delay, 0s);
}

/* Floating particles in header */
@keyframes particleFloat1 {
  0%,
  100% {
    transform: translate(0, 0);
    opacity: 0.4;
  }
  50% {
    transform: translate(15px, -20px);
    opacity: 0.7;
  }
}
@keyframes particleFloat2 {
  0%,
  100% {
    transform: translate(0, 0);
    opacity: 0.3;
  }
  50% {
    transform: translate(-20px, -15px);
    opacity: 0.5;
  }
}
@keyframes particleFloat3 {
  0%,
  100% {
    transform: translate(0, 0);
    opacity: 0.25;
  }
  50% {
    transform: translate(10px, 15px);
    opacity: 0.4;
  }
}
.modal-particle {
  position: absolute;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.3);
}
.modal-particle-1 {
  top: 20%;
  left: 15%;
  animation: particleFloat1 4s ease-in-out infinite;
}
.modal-particle-2 {
  top: 60%;
  right: 20%;
  animation: particleFloat2 5s ease-in-out infinite 0.5s;
}
.modal-particle-3 {
  bottom: 25%;
  left: 40%;
  animation: particleFloat3 4.5s ease-in-out infinite 1s;
}

/* CTA shimmer */
@keyframes shimmer {
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(100%);
  }
}
.cta-shimmer {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255, 255, 255, 0.2) 50%,
    transparent 100%
  );
  animation: shimmer 2.5s ease-in-out infinite;
}
</style>
