<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="modal-limit-reached">
        <!-- Backdrop -->
        <div 
          class="absolute inset-0 bg-black/40 backdrop-blur-sm"
          @click="handleClose"
          data-testid="modal-backdrop"
        ></div>

        <!-- Modal Content -->
        <div class="relative surface-elevated max-w-md w-full p-6 animate-in" data-testid="modal-body">
          <!-- Icon -->
          <div class="flex justify-center mb-4">
            <div class="relative">
              <div class="absolute inset-0 bg-gradient-to-br from-brand/20 to-brand/5 rounded-full blur-xl"></div>
              <div class="relative w-16 h-16 rounded-full flex items-center justify-center"
                   :class="limitType === 'lifetime' ? 'bg-orange-500/10' : 'bg-brand/10'">
                <svg class="w-8 h-8" :class="limitType === 'lifetime' ? 'text-orange-500' : 'txt-brand'" 
                     fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
            </div>
          </div>

          <!-- Title -->
          <h2 class="text-2xl font-bold txt-primary text-center mb-2">
            {{ $t(`limitReached.${limitType}.title`) }}
          </h2>

          <!-- Description -->
          <p class="text-sm txt-secondary text-center mb-6">
            {{ $t(`limitReached.${limitType}.description`, { 
              action: actionLabel,
              limit: formatLimit(currentLimit)
            }) }}
          </p>

          <!-- Current Usage Stats -->
          <div class="surface-card p-4 mb-6 space-y-3" data-testid="section-usage">
            <div class="flex items-center justify-between text-sm">
              <span class="txt-secondary">{{ $t('limitReached.currentUsage') }}</span>
              <span class="font-semibold txt-primary">{{ used }} / {{ formatLimit(currentLimit) }}</span>
            </div>
            
            <!-- Progress Bar -->
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
              <div class="h-2 rounded-full transition-all bg-red-500"
                   style="width: 100%">
              </div>
            </div>

            <div class="flex items-center justify-between text-xs txt-secondary">
              <span>{{ $t('limitReached.remaining') }}: 0</span>
              <span v-if="resetTime">
                {{ $t('limitReached.resetsIn') }}: {{ formatResetTime(resetTime) }}
              </span>
              <span v-else class="text-orange-500 dark:text-orange-400 font-medium">
                {{ $t('limitReached.noReset') }}
              </span>
            </div>
          </div>

          <!-- Benefits List -->
          <div v-if="limitType === 'lifetime'" class="mb-6 space-y-2" data-testid="section-benefits">
            <p class="text-xs font-semibold txt-primary mb-3">
              {{ $t('limitReached.upgradesBenefits') }}
            </p>
            <div v-for="(benefit, idx) in benefits" :key="idx" 
                 class="flex items-start gap-2 text-sm">
              <svg class="w-5 h-5 txt-brand flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
              <span class="txt-secondary">{{ benefit }}</span>
            </div>
          </div>

          <!-- Buttons -->
          <div class="flex flex-col gap-3">
            <!-- Upgrade Button -->
            <button
              @click="handleUpgrade"
              class="btn-primary w-full px-6 py-3 rounded-lg font-semibold text-base flex items-center justify-center gap-2 group"
              data-testid="btn-upgrade"
            >
              <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
              {{ $t('limitReached.upgradeNow') }}
            </button>

            <!-- Phone Verification (for ANONYMOUS) -->
            <button
              v-if="userLevel === 'ANONYMOUS' && !phoneVerified"
              @click="handleVerifyPhone"
              class="w-full px-6 py-3 rounded-lg font-medium surface-chip txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors flex items-center justify-center gap-2"
              data-testid="btn-verify-phone"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              {{ $t('limitReached.verifyPhone') }}
            </button>

            <!-- Close Button -->
            <button
              @click="handleClose"
              class="w-full px-6 py-2 text-sm txt-secondary hover:txt-primary transition-colors"
              data-testid="btn-close"
            >
              {{ $t('common.close') }}
            </button>
          </div>

          <!-- Footer Note -->
          <p class="text-xs txt-tertiary text-center mt-4">
            {{ $t('limitReached.footerNote') }}
          </p>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

const { t } = useI18n()
const router = useRouter()

interface Props {
  isOpen: boolean
  limitType: 'lifetime' | 'hourly' | 'monthly'
  actionType: string
  used: number
  currentLimit: number
  resetTime?: number | null
  userLevel?: string
  phoneVerified?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  limitType: 'lifetime',
  actionType: 'MESSAGES',
  used: 0,
  currentLimit: 0,
  resetTime: null,
  userLevel: 'NEW',
  phoneVerified: false
})

const emit = defineEmits<{
  close: []
  upgrade: []
  verifyPhone: []
}>()

const actionLabel = computed(() => {
  return t(`config.usage.actions.${props.actionType.toLowerCase()}`, props.actionType)
})

const benefits = computed(() => [
  t('limitReached.benefits.unlimited'),
  t('limitReached.benefits.allFeatures'),
  t('limitReached.benefits.priority'),
  t('limitReached.benefits.support')
])

const formatLimit = (limit: number) => {
  if (limit >= 1000000) return '∞'
  return limit.toLocaleString()
}

const formatResetTime = (timestamp: number) => {
  const now = Date.now()
  const diff = timestamp * 1000 - now
  
  if (diff < 0) return t('common.now')
  
  const hours = Math.floor(diff / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
  
  if (hours > 0) {
    return `${hours}h ${minutes}m`
  }
  
  return `${minutes}m`
}

const handleClose = () => {
  emit('close')
}

const handleUpgrade = () => {
  emit('upgrade')
  router.push('/subscription')
}

const handleVerifyPhone = () => {
  emit('verifyPhone')
  router.push('/settings/phone-verification')
}
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
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.modal-enter-from .surface-elevated,
.modal-leave-to .surface-elevated {
  transform: scale(0.95) translateY(-10px);
  opacity: 0;
}

.animate-in {
  animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(20px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>
