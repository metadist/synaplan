<template>
  <form class="space-y-4" @submit.prevent="emit('submit')">
    <p class="text-sm txt-secondary">{{ t('channels.telegram.empty') }}</p>
    <a
      href="https://t.me/BotFather"
      target="_blank"
      rel="noopener noreferrer"
      class="inline-flex text-sm font-medium text-[var(--channel-telegram)]"
    >
      {{ t('channels.telegram.botFather') }}
    </a>
    <div>
      <label for="telegram-bot-token" class="block text-sm font-medium txt-primary">
        {{ t('channels.telegram.tokenLabel') }}
      </label>
      <input
        id="telegram-bot-token"
        v-model="token"
        type="password"
        autocomplete="off"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :placeholder="t('channels.telegram.tokenPlaceholder')"
        :disabled="busy"
        data-testid="input-telegram-token"
      />
    </div>
    <p
      v-if="error"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="text-telegram-error"
    >
      {{ error }}
    </p>
    <button
      type="submit"
      class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
      data-testid="btn-telegram-connect"
      :disabled="busy || token.trim() === ''"
    >
      {{ busy ? t('channels.telegram.connecting') : t('channels.telegram.connect') }}
    </button>
  </form>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ busy: boolean; error: string }>()
const emit = defineEmits<{ submit: [] }>()
const token = defineModel<string>({ required: true })

const { t } = useI18n()
</script>
