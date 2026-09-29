<template>
  <div class="space-y-4" data-testid="text-telegram-pairing">
    <p v-if="expired" class="text-sm txt-secondary" data-testid="text-telegram-pairing-expired">
      {{ t('channels.telegram.pairingExpired') }}
    </p>
    <template v-else>
      <p class="text-sm txt-secondary">{{ t('channels.telegram.pairingHint') }}</p>
      <p v-if="validUntil" class="text-sm txt-secondary">
        {{ t('channels.telegram.pairingValidUntil', { time: validUntil }) }}
      </p>
    </template>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="expired"
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-telegram-renew"
        :disabled="busy"
        @click="emit('renew')"
      >
        {{ t('channels.telegram.renewPairing') }}
      </button>
      <a
        v-else-if="link"
        :href="link"
        target="_blank"
        rel="noopener noreferrer"
        class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="link-telegram-open"
      >
        {{ t('channels.telegram.openTelegram') }}
      </a>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-telegram-cancel"
        :disabled="busy"
        @click="emit('cancel')"
      >
        {{ t('channels.telegram.cancelPairing') }}
      </button>
    </div>
    <p class="text-sm txt-secondary">{{ t('channels.telegram.cancelPairingHint') }}</p>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  expired: boolean
  link: string | null | undefined
  validUntil: string
  busy: boolean
}>()
const emit = defineEmits<{ renew: []; cancel: [] }>()

const { t } = useI18n()
</script>
