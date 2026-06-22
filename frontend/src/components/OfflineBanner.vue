<template>
  <Transition name="offline-slide">
    <div
      v-if="!isOnline"
      class="fixed inset-x-0 top-0 z-[1000] flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-[var(--status-warning,#b45309)] shadow-md"
      role="status"
      aria-live="polite"
      data-testid="offline-banner"
    >
      <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M18.364 5.636L5.636 18.364m12.728 0L5.636 5.636M12 2a10 10 0 100 20 10 10 0 000-20z"
        />
      </svg>
      <span>{{ $t('network.offline') }}</span>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { useNetworkStatus } from '@/composables/useNetworkStatus'

const { isOnline } = useNetworkStatus()
</script>

<style scoped>
.offline-slide-enter-active,
.offline-slide-leave-active {
  transition: transform 0.25s ease;
}
.offline-slide-enter-from,
.offline-slide-leave-to {
  transform: translateY(-100%);
}
</style>
