<template>
  <Transition name="slide-down">
    <div v-if="visible" class="flex justify-center">
      <div
        data-testid="pending-purchase-banner"
        class="inline-flex w-full justify-center items-center gap-1.5 px-2.5 py-1.5 rounded-t-2xl rounded-b-none border border-b-0 border-brand/20 bg-brand/5 dark:bg-brand/10 backdrop-blur-sm sm:w-auto sm:gap-2.5 sm:px-3"
      >
        <Icon
          icon="mdi:crown-outline"
          class="w-4 h-4 text-brand flex-shrink-0"
          aria-hidden="true"
        />

        <span class="text-xs font-medium txt-primary">
          {{ $t('iap.pendingBanner.text') }}
        </span>

        <router-link
          to="/register"
          data-testid="pending-purchase-banner-cta"
          class="text-xs font-semibold text-white whitespace-nowrap px-2.5 py-1 rounded-full bg-brand shadow-sm transition-shadow duration-300 hover:shadow-md hover:shadow-brand/40 sm:px-3"
        >
          {{ $t('iap.pendingBanner.cta') }}
        </router-link>

        <button
          data-testid="pending-purchase-banner-dismiss"
          class="p-0.5 -mr-0.5 rounded-full hover:bg-black/5 dark:hover:bg-white/10 transition-colors txt-secondary"
          :aria-label="$t('common.close')"
          @click="$emit('dismiss')"
        >
          <Icon icon="mdi:close" class="w-3.5 h-3.5" aria-hidden="true" />
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
/**
 * MOBILE-APP SEAM (native onboarding): reminder shown to a signed-out guest
 * whose store purchase is still waiting to be linked to an account
 * (`hasPendingIapRedemption()`). The auth-first flow buys only after sign-in,
 * so this covers the remaining signed-out cases: a restore that surfaces an
 * existing subscription, and purchases from an earlier app version. Without
 * an account the entitlement cannot be used — and an unacknowledged Android
 * purchase is auto-refunded after ~3 days — so the banner keeps a quiet,
 * dismissible path back into account creation. Redemption itself runs
 * automatically after the next sign-in.
 */
import { Icon } from '@iconify/vue'

defineProps<{
  visible: boolean
}>()

defineEmits<{
  dismiss: []
}>()
</script>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
