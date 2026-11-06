<template>
  <div
    class="surface-card p-6 border-l-4 border-brand rounded-lg"
    data-testid="alert-code-instructions"
  >
    <div class="space-y-4">
      <div>
        <h4 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
          <InformationCircleIcon class="w-5 h-5 text-brand" />
          {{ $t('config.phoneVerification.instructionsTitle') }}
        </h4>
        <p class="text-sm txt-secondary">
          {{
            inboundNumbers.length > 0
              ? $t('config.phoneVerification.instructionsLead')
              : $t('config.phoneVerification.instructionsLeadMissing')
          }}
        </p>
      </div>

      <div class="bg-gradient-to-br from-brand/5 to-brand/10 rounded-xl p-6 text-center">
        <p class="text-xs txt-secondary uppercase tracking-wider mb-2">
          {{ $t('config.phoneVerification.yourCode') }}
        </p>
        <div class="text-5xl font-bold txt-primary tracking-[0.5em] font-mono select-all">
          {{ code }}
        </div>
        <p class="text-xs txt-secondary mt-4">
          {{ $t('config.phoneVerification.codeExpires') }}
        </p>
        <div v-if="timeRemaining !== null" class="mt-2">
          <span
            class="text-xs font-medium"
            :class="timeRemaining < 60 ? 'text-red-600 dark:text-red-400' : 'txt-secondary'"
          >
            {{ formatTimeRemaining(timeRemaining) }}
          </span>
        </div>
      </div>

      <WhatsAppInboundNumberList :numbers="inboundNumbers" />

      <div class="space-y-2 text-sm txt-secondary">
        <p class="flex items-start gap-2">
          <span class="font-semibold txt-primary">1.</span>
          {{ $t('config.phoneVerification.stepOpenWhatsApp') }}
        </p>
        <p class="flex items-start gap-2">
          <span class="font-semibold txt-primary">2.</span>
          <i18n-t
            :keypath="
              inboundNumbers.length > 0
                ? 'config.phoneVerification.stepSendCode'
                : 'config.phoneVerification.stepSendCodeMissing'
            "
            tag="span"
            scope="global"
          >
            <template #code>
              <strong class="font-mono txt-primary">{{ code }}</strong>
            </template>
          </i18n-t>
        </p>
        <p class="flex items-start gap-2">
          <span class="font-semibold txt-primary">3.</span>
          {{ $t('config.phoneVerification.stepConfirmation') }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { InformationCircleIcon } from '@heroicons/vue/24/outline'
import WhatsAppInboundNumberList, {
  type WhatsAppInboundNumber,
} from '@/components/config/WhatsAppInboundNumberList.vue'

defineProps<{
  code: string
  timeRemaining: number | null
  inboundNumbers: WhatsAppInboundNumber[]
  formatTimeRemaining: (seconds: number) => string
}>()
</script>
