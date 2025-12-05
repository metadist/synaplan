<template>
  <div class="surface-card p-6 space-y-6" data-testid="section-phone-verification">
    <!-- Header -->
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between" data-testid="section-header">
      <div>
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <DevicePhoneMobileIcon class="w-5 h-5 text-green-500" />
          {{ $t('config.phoneVerification.title') }}
        </h3>
        <p class="text-sm txt-secondary mt-1">
          {{ $t('config.phoneVerification.description') }}
        </p>
      </div>

      <span
        class="pill text-xs w-fit"
        :class="status?.verified ? 'pill--active' : 'pill--warning'"
      >
        {{ status?.verified ? $t('config.phoneVerification.statusVerified') : $t('config.phoneVerification.statusNotVerified') }}
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-8 rounded-lg surface-chip" data-testid="section-loading">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-brand"></div>
    </div>

    <!-- Error -->
    <div v-if="error" class="surface-card p-4 border border-red-500/50 rounded-lg bg-red-500/5" data-testid="alert-error">
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <!-- Not Verified State -->
    <div v-if="!loading && !status?.verified" data-testid="section-not-verified">
      <!-- Phone Input -->
      <div v-if="!verificationPending" class="space-y-4" data-testid="section-phone-input">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('config.phoneVerification.phoneNumber') }}
          </label>
          <input
            v-model="phoneNumber"
            type="tel"
            :placeholder="$t('config.phoneVerification.phoneNumberPlaceholder')"
            class="w-full px-4 py-3 rounded-lg surface-chip txt-primary border border-light-border focus:border-brand focus:ring-2 focus:ring-brand/20 transition-colors"
            data-testid="input-phone"
          />
          <p class="mt-2 text-xs txt-secondary">
            {{ $t('config.phoneVerification.phoneNumberHint') }}
          </p>
        </div>

        <button
          @click="requestVerification"
          :disabled="!phoneNumber.trim() || requesting"
          class="btn-primary px-6 py-3 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
          data-testid="btn-send"
        >
          <svg v-if="requesting" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ requesting ? $t('common.sending') : $t('config.phoneVerification.sendCode') }}
        </button>
      </div>

      <!-- Code Verification -->
      <div v-else class="space-y-4" data-testid="section-code-input">
        <div class="surface-card p-4 border-l-4 border-brand" data-testid="alert-code-sent">
          <p class="text-sm txt-primary">
            {{ $t('config.phoneVerification.codeSent', { phone: phoneNumber }) }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('config.phoneVerification.verificationCode') }}
          </label>
          <input
            v-model="verificationCode"
            type="text"
            maxlength="6"
            :placeholder="$t('config.phoneVerification.codePlaceholder')"
            class="w-full px-4 py-3 rounded-lg surface-chip txt-primary border border-light-border focus:border-brand focus:ring-2 focus:ring-brand/20 transition-colors text-center text-2xl font-mono tracking-widest"
            @input="formatCode"
            data-testid="input-code"
          />
        </div>

        <div class="flex gap-3">
          <button
            @click="confirmVerification"
            :disabled="verificationCode.length !== 6 || confirming"
            class="flex-1 btn-primary px-6 py-3 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            data-testid="btn-verify"
          >
            <svg v-if="confirming" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ $t('config.phoneVerification.verify') }}
          </button>

          <button
            @click="cancelVerification"
            class="surface-chip px-6 py-3 rounded-lg font-medium txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
            data-testid="btn-cancel"
          >
            {{ $t('common.cancel') }}
          </button>
        </div>

        <button
          @click="requestVerification"
          :disabled="requesting"
          class="w-full text-sm txt-secondary hover:txt-primary transition-colors"
          data-testid="btn-resend"
        >
          {{ $t('config.phoneVerification.resendCode') }}
        </button>
      </div>
    </div>

    <!-- Verified State -->
    <div v-else-if="status?.verified" class="space-y-4" data-testid="section-verified">
      <div class="surface-card p-4 border-l-4 border-green-500">
        <div class="flex items-start gap-3">
          <svg class="w-6 h-6 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
          <div class="flex-1">
            <h3 class="text-sm font-semibold txt-primary mb-1">
              {{ $t('config.phoneVerification.verified') }}
            </h3>
            <p class="text-sm txt-secondary">
              {{ status.phone_number }}
            </p>
            <p class="text-xs txt-secondary mt-2">
              {{ $t('config.phoneVerification.verifiedAt', { date: formatDate(status.verified_at) }) }}
            </p>
          </div>
        </div>
      </div>

      <button
        @click="removeVerification"
        class="w-full surface-chip px-4 py-3 rounded-lg font-medium text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors"
        data-testid="btn-remove"
      >
        {{ $t('config.phoneVerification.removePhone') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { useI18n } from 'vue-i18n'
import { DevicePhoneMobileIcon } from '@heroicons/vue/24/outline'

const { success, error: showError } = useNotification()
const dialog = useDialog()
const { t } = useI18n()

const loading = ref(false)
const error = ref<string | null>(null)
const status = ref<any>(null)
const phoneNumber = ref('')
const verificationCode = ref('')
const verificationPending = ref(false)
const requesting = ref(false)
const confirming = ref(false)

const AUTH_TOKEN_KEY = import.meta.env.VITE_AUTH_TOKEN_KEY || 'auth_token'
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || ''
const API_BASE = API_BASE_URL.replace(/\/$/, '')

const buildHeaders = (withJson = false) => {
  const headers: Record<string, string> = {}
  if (withJson) {
    headers['Content-Type'] = 'application/json'
  }

  const token = localStorage.getItem(AUTH_TOKEN_KEY)
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  return headers
}

const requestWithFallback = async (path: string, options: RequestInit = {}) => {
  const url = `${API_BASE}${path}`
  const response = await fetch(url, options)

  const contentType = response.headers.get('content-type') ?? ''
  const isJson = contentType.includes('application/json')
  const payload = isJson ? await response.json() : await response.text()

  return { response, payload }
}

const getErrorMessage = (payload: unknown, fallback: string) => {
  if (typeof payload === 'string' && payload.trim() !== '') {
    return payload
  }
  if (payload && typeof payload === 'object' && 'error' in payload) {
    return String(payload.error)
  }
  return fallback
}

const isNetworkError = (err: any) => {
  if (!err) return false
  const message = err.message || ''
  return message === 'Failed to fetch' || message.includes('NetworkError')
}

const loadStatus = async () => {
  try {
    loading.value = true
    error.value = null

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone/status', {
      headers: buildHeaders()
    })

    if (!response.ok) {
      throw new Error(getErrorMessage(payload, 'Failed to load status'))
    }

    const data = payload as any
    status.value = data
    verificationPending.value = Boolean(data?.pending_verification)

    if (data?.phone_number) {
      phoneNumber.value = data.phone_number
    }

  } catch (err: any) {
    console.error('Failed to load phone verification status:', err)
    error.value = isNetworkError(err)
      ? t('config.phoneVerification.errorLoading')
      : err?.message || t('config.phoneVerification.errorLoading')
  } finally {
    loading.value = false
  }
}

const requestVerification = async () => {
  if (!phoneNumber.value.trim()) return

  try {
    requesting.value = true
    error.value = null

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone/request', {
      method: 'POST',
      headers: buildHeaders(true),
      body: JSON.stringify({ phone_number: phoneNumber.value })
    })

    if (!response.ok) {
      throw new Error(getErrorMessage(payload, 'Failed to send verification code'))
    }

    verificationPending.value = true
    success(t('config.phoneVerification.codeSentSuccess'))

  } catch (err: any) {
    console.error('Failed to request verification:', err)
    const message = isNetworkError(err)
      ? t('config.phoneVerification.errorLoading')
      : err?.message || t('config.phoneVerification.errorSending')
    error.value = message
    showError(message)
  } finally {
    requesting.value = false
  }
}

const confirmVerification = async () => {
  if (verificationCode.value.length !== 6) return

  try {
    confirming.value = true
    error.value = null

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone/confirm', {
      method: 'POST',
      headers: buildHeaders(true),
      body: JSON.stringify({ code: verificationCode.value })
    })

    if (!response.ok) {
      throw new Error(getErrorMessage(payload, 'Invalid verification code'))
    }

    success(t('config.phoneVerification.verifiedSuccess'))
    verificationPending.value = false
    verificationCode.value = ''
    await loadStatus()

  } catch (err: any) {
    console.error('Failed to confirm verification:', err)
    const message = isNetworkError(err)
      ? t('config.phoneVerification.errorLoading')
      : err?.message || t('config.phoneVerification.errorVerifying')
    error.value = message
    showError(message)
  } finally {
    confirming.value = false
  }
}

const cancelVerification = () => {
  verificationPending.value = false
  verificationCode.value = ''
  error.value = null
}

const removeVerification = async () => {
  const confirmed = await dialog.confirm({
    title: t('config.phoneVerification.confirmRemoveTitle'),
    message: t('config.phoneVerification.confirmRemove'),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    danger: true
  })

  if (!confirmed) return

  try {
    loading.value = true
    error.value = null

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone', {
      method: 'DELETE',
      headers: buildHeaders()
    })

    if (!response.ok) {
      throw new Error(getErrorMessage(payload, 'Failed to remove phone'))
    }

    success(t('config.phoneVerification.removedSuccess'))
    await loadStatus()

  } catch (err: any) {
    console.error('Failed to remove phone verification:', err)
    const message = isNetworkError(err)
      ? t('config.phoneVerification.errorLoading')
      : err?.message || t('config.phoneVerification.errorRemoving')
    error.value = message
    showError(message)
  } finally {
    loading.value = false
  }
}

const formatCode = () => {
  verificationCode.value = verificationCode.value.replace(/\D/g, '').slice(0, 6)
}

const formatDate = (timestamp?: number) => {
  if (!timestamp) return '—'
  return new Date(timestamp * 1000).toLocaleDateString()
}

onMounted(() => {
  loadStatus()
})
</script>
