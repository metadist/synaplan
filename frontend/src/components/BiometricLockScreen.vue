<template>
  <Transition name="lock-fade">
    <!--
      Privacy lock: while the app is locked it must cover EVERY other overlay
      (dialogs are z-[10000], cookie consent z-[9999]) so no sensitive content
      stays visible/interactable behind it. Kept just below ForceUpdateScreen
      (z-[10050]), which is the ultimate gate.
    -->
    <div
      v-if="locked"
      class="fixed inset-0 z-[10040] flex flex-col items-center justify-center gap-8 bg-app px-6"
      data-testid="biometric-lock"
    >
      <div class="flex flex-col items-center gap-3 text-center">
        <div
          class="w-20 h-20 rounded-full bg-[var(--brand)]/15 flex items-center justify-center"
          aria-hidden="true"
        >
          <Icon icon="mdi:fingerprint" class="w-11 h-11 text-[var(--brand)]" />
        </div>
        <h1 class="text-2xl font-bold txt-primary">{{ $t('biometricLock.title') }}</h1>
        <p class="txt-secondary max-w-xs">{{ $t('biometricLock.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="btn-primary px-8 py-3 rounded-lg font-medium"
        data-testid="btn-biometric-unlock"
        @click="unlock"
      >
        {{ $t('biometricLock.unlock') }}
      </button>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useBiometricLock } from '@/composables/useBiometricLock'

const { locked, unlock } = useBiometricLock()
</script>

<style scoped>
.lock-fade-enter-active,
.lock-fade-leave-active {
  transition: opacity 0.2s ease;
}
.lock-fade-enter-from,
.lock-fade-leave-to {
  opacity: 0;
}
</style>
