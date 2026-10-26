<template>
  <div class="space-y-2">
    <div class="flex justify-end">
      <router-link
        to="/forgot-password"
        class="text-xs font-medium text-brand hover:underline underline-offset-2"
        data-testid="link-forgot"
      >
        {{ $t('auth.forgotPassword') }}
      </router-link>
    </div>
    <template v-if="payFirst">
      <p v-if="sent" class="text-sm txt-secondary" role="status" data-testid="text-email-link-sent">
        {{ $t('auth.emailLinkSent', { email }) }}
      </p>
      <p v-else-if="errorText" class="text-sm text-red-600 dark:text-red-400" role="alert">
        {{ errorText }}
      </p>
      <button
        type="button"
        class="text-xs font-medium text-brand hover:underline underline-offset-2"
        data-testid="btn-email-link"
        :disabled="sending"
        @click="send"
      >
        {{ $t('auth.emailLink') }}
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { requestEmailSignInLink } from '@/services/api/emailLinkApi'
import { getConfigSync } from '@/services/api/httpClient'
import { validateEmail } from '@/composables/usePasswordValidation'

const props = defineProps<{ email: string }>()
const { t } = useI18n()
const payFirst = computed(() => getConfigSync().auth?.payFirst === true)
const sent = ref(false)
const sending = ref(false)
const errorText = ref('')

async function send(): Promise<void> {
  errorText.value = ''
  if (!validateEmail(props.email)) {
    errorText.value = t('auth.emailLinkNeedEmail')
    return
  }
  sending.value = true
  try {
    await requestEmailSignInLink(props.email.trim())
    sent.value = true
  } catch {
    errorText.value = t('auth.emailLinkFailed')
  } finally {
    sending.value = false
  }
}
</script>
