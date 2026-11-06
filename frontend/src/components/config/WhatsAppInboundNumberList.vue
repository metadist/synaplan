<template>
  <div v-if="numbers.length > 0" data-testid="list-inbound-numbers">
    <p class="text-xs txt-secondary uppercase tracking-wider mb-2">
      {{ $t('config.phoneVerification.inboundNumbersTitle') }}
    </p>
    <ul class="space-y-2">
      <li v-for="number in numbers" :key="number.e164">
        <a
          :href="number.wa_me"
          target="_blank"
          rel="noopener noreferrer"
          class="txt-primary text-sm font-medium underline-offset-2 hover:underline"
          data-testid="link-inbound-number"
        >
          {{ number.e164 }}
        </a>
      </li>
    </ul>
  </div>
  <p v-else class="text-sm txt-secondary" data-testid="inbound-numbers-missing">
    {{ $t('config.phoneVerification.inboundNumbersMissing') }}
  </p>
</template>

<script setup lang="ts">
export interface WhatsAppInboundNumber {
  e164: string
  wa_me: string
}

defineProps<{
  numbers: WhatsAppInboundNumber[]
}>()
</script>
