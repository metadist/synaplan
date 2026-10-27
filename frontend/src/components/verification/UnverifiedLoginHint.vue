<template>
  <div
    class="p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200/60 dark:border-red-800/40 space-y-2"
    data-testid="alert-login-unverified"
  >
    <p class="text-sm text-red-600 dark:text-red-300">
      {{ mailerConfigured ? $t('auth.loginUnverified') : $t('auth.loginUnverifiedNoMail') }}
    </p>
    <router-link
      v-if="mailerConfigured"
      :to="{ path: '/verify-email', query: { email: email.trim() } }"
      class="inline-block text-sm font-medium text-brand hover:underline underline-offset-2"
      data-testid="link-login-resend-verification"
    >
      {{ $t('auth.loginUnverifiedAction') }}
    </router-link>
  </div>
</template>

<script setup lang="ts">
import { isMailerConfigured } from '@/utils/mailerConfigured'

defineProps<{ email: string }>()

const mailerConfigured = isMailerConfigured()
</script>
