<template>
  <Transition
    enter-active-class="transition-transform duration-[250ms] ease-out"
    leave-active-class="transition-transform duration-[250ms] ease-in"
    enter-from-class="-translate-y-full"
    leave-to-class="-translate-y-full"
  >
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
