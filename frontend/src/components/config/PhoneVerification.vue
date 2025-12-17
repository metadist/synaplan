<template>
  <div class="surface-card p-6 space-y-6" data-testid="section-phone-verification">
    <!-- Header -->
    <div
      class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
      data-testid="section-header"
    >
      <div>
        <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
          <DevicePhoneMobileIcon class="w-5 h-5 text-green-500" />
          {{ $t('config.phoneVerification.title') }}
        </h3>
        <p class="text-sm txt-secondary mt-1">
          {{ $t('config.phoneVerification.description') }}
        </p>
      </div>

      <span class="pill text-xs w-fit" :class="status?.verified ? 'pill--active' : 'pill--warning'">
        {{
          status?.verified
            ? $t('config.phoneVerification.statusVerified')
            : $t('config.phoneVerification.statusNotVerified')
        }}
      </span>
    </div>

    <!-- Loading -->
    <div
      v-if="loading"
      class="flex items-center justify-center py-8 rounded-lg surface-chip"
      data-testid="section-loading"
    >
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-brand"></div>
    </div>

    <!-- Error -->
    <div
      v-if="error"
      class="surface-card p-4 border border-red-500/50 rounded-lg bg-red-500/5"
      data-testid="alert-error"
    >
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <!-- Not Verified State -->
    <div v-if="!loading && !status?.verified" data-testid="section-not-verified">
      <!-- Phone Input - Always shown -->
      <div class="space-y-4" data-testid="section-phone-input">
        <div>
          <label class="block text-sm font-medium txt-primary mb-2">
            {{ $t('config.phoneVerification.phoneNumber') }}
          </label>
          <input
            v-model="phoneNumber"
            type="tel"
            :placeholder="$t('config.phoneVerification.phoneNumberPlaceholder')"
            class="w-full px-4 py-3 rounded-lg surface-chip txt-primary border border-light-border focus:border-brand focus:ring-2 focus:ring-brand/20 transition-colors"
            :disabled="verificationPending"
            data-testid="input-phone"
          />
          <p class="mt-2 text-xs txt-secondary">
            {{ $t('config.phoneVerification.phoneNumberHint') }}
          </p>
        </div>

        <!-- Warning: Send WhatsApp Message First -->
        <div
          v-if="requiresWhatsAppMessage"
          class="surface-card p-4 border-l-4 border-yellow-500 bg-yellow-500/5"
          data-testid="alert-whatsapp-required"
        >
          <div class="flex items-start gap-3">
            <svg
              class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fill-rule="evenodd"
                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                clip-rule="evenodd"
              />
            </svg>
            <div class="flex-1">
              <p class="text-sm font-medium txt-primary">Send a WhatsApp Message First</p>
              <p class="text-xs txt-secondary mt-1">
                Please send a WhatsApp message to one of our numbers first. After that, try
                requesting the verification code again.
              </p>
            </div>
          </div>
        </div>

        <button
          v-if="!verificationPending"
          :disabled="!phoneNumber.trim() || requesting"
          class="btn-primary px-6 py-3 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
          data-testid="btn-send"
          @click="requestVerification"
        >
          <svg v-if="requesting" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            ></circle>
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            ></path>
          </svg>
          {{ requesting ? $t('common.sending') : $t('config.phoneVerification.sendCode') }}
        </button>
      </div>

      <!-- Verification Code Display - Show generated code -->
      <div v-if="verificationPending" class="space-y-4 mt-6" data-testid="section-code-display">
        <!-- Instructions -->
        <div
          class="surface-card p-6 border-l-4 border-brand rounded-lg"
          data-testid="alert-code-instructions"
        >
          <div class="space-y-4">
            <div>
              <h4 class="text-lg font-semibold txt-primary mb-2 flex items-center gap-2">
                <svg
                  class="w-5 h-5 text-brand"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                Verification Instructions
              </h4>
              <p class="text-sm txt-secondary">
                Send this code to one of our WhatsApp numbers to verify your phone.
              </p>
            </div>

            <!-- Code Display -->
            <div class="bg-gradient-to-br from-brand/5 to-brand/10 rounded-xl p-6 text-center">
              <p class="text-xs txt-secondary uppercase tracking-wider mb-2">
                Your Verification Code
              </p>
              <div class="text-5xl font-bold txt-primary tracking-[0.5em] font-mono select-all">
                {{ displayCode }}
              </div>
              <p class="text-xs txt-secondary mt-4">Code expires in 5 minutes</p>
              <div v-if="timeRemaining" class="mt-2">
                <span
                  class="text-xs font-medium"
                  :class="timeRemaining < 60 ? 'text-red-600' : 'txt-secondary'"
                >
                  ⏱️ {{ formatTimeRemaining(timeRemaining) }}
                </span>
              </div>
            </div>

            <!-- Steps -->
            <div class="space-y-2 text-sm txt-secondary">
              <p class="flex items-start gap-2">
                <span class="font-semibold txt-primary">1.</span>
                Open WhatsApp on your phone
              </p>
              <p class="flex items-start gap-2">
                <span class="font-semibold txt-primary">2.</span>
                Send the code <strong class="font-mono txt-primary">{{ displayCode }}</strong> to
                any of our numbers
              </p>
              <p class="flex items-start gap-2">
                <span class="font-semibold txt-primary">3.</span>
                You will receive a confirmation message automatically
              </p>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3">
          <button
            :disabled="requesting"
            class="flex-1 btn-primary-outlined px-6 py-3 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            data-testid="btn-regenerate"
            @click="regenerateCode"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
              />
            </svg>
            {{ requesting ? 'Generating...' : 'Generate New Code' }}
          </button>

          <button
            class="surface-chip px-6 py-3 rounded-lg font-medium txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
            data-testid="btn-cancel"
            @click="cancelVerification"
          >
            {{ $t('common.cancel') }}
          </button>
        </div>

        <button
          :disabled="requesting"
          class="w-full text-sm txt-secondary hover:txt-primary transition-colors"
          data-testid="btn-resend"
          @click="requestVerification"
        >
          {{ $t('config.phoneVerification.resendCode') }}
        </button>
      </div>
    </div>

    <!-- Verified State -->
    <div v-else-if="status?.verified" class="space-y-4" data-testid="section-verified">
      <div class="surface-card p-4 border-l-4 border-green-500">
        <div class="flex items-start gap-3">
          <svg
            class="w-6 h-6 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5"
            fill="currentColor"
            viewBox="0 0 20 20"
          >
            <path
              fill-rule="evenodd"
              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
              clip-rule="evenodd"
            />
          </svg>
          <div class="flex-1">
            <h3 class="text-sm font-semibold txt-primary mb-1">
              {{ $t('config.phoneVerification.verified') }}
            </h3>
            <p class="text-sm txt-secondary">
              {{ status.phone_number }}
            </p>
            <p class="text-xs txt-secondary mt-2">
              {{
                $t('config.phoneVerification.verifiedAt', { date: formatDate(status.verified_at) })
              }}
            </p>
          </div>
        </div>
      </div>

      <button
        class="w-full surface-chip px-4 py-3 rounded-lg font-medium text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors"
        data-testid="btn-remove"
        @click="removePhone"
      >
        {{ $t('config.phoneVerification.removePhone') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { useI18n } from 'vue-i18n'
import { DevicePhoneMobileIcon } from '@heroicons/vue/24/outline'
import { useConfigStore } from '@/stores/config'

const { success, error: showError } = useNotification()
const dialog = useDialog()
const { t } = useI18n()

const loading = ref(false)
const error = ref<string | null>(null)
const status = ref<any>(null)
const phoneNumber = ref('')
const verificationCode = ref('')
const verificationPending = ref(false)
const requiresWhatsAppMessage = ref(false)
const requesting = ref(false)
const currentTime = ref(Math.floor(Date.now() / 1000)) // Unix timestamp in seconds

const config = useConfigStore()
const API_BASE = config.appBaseUrl

// Computed properties
const displayCode = computed(() => {
  return status.value?.verification_code || '-----'
})

const timeRemaining = computed(() => {
  const expiresAt = status.value?.expires_at
  if (!expiresAt) return null
  const remaining = expiresAt - currentTime.value
  return remaining > 0 ? remaining : 0
})

// Timer to update currentTime every second
let timer: ReturnType<typeof setInterval> | null = null

const startTimer = () => {
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    currentTime.value = Math.floor(Date.now() / 1000)
  }, 1000)
}

const stopTimer = () => {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

onMounted(() => {
  startTimer()
})

onUnmounted(() => {
  stopTimer()
})

// Helper functions
const formatTimeRemaining = (seconds: number): string => {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const buildHeaders = (withJson = false) => {
  const headers: Record<string, string> = {}
  if (withJson) {
    headers['Content-Type'] = 'application/json'
  }
  // No Authorization header needed - using HttpOnly cookies
  return headers
}

const requestWithFallback = async (path: string, options: RequestInit = {}) => {
  const url = `${API_BASE}${path}`
  const response = await fetch(url, { ...options, credentials: 'include' })

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
      headers: buildHeaders(),
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

    // Check if verification is complete (user was verified via WhatsApp)
    if (data?.verified && verificationPending.value) {
      // User was verified! Refresh to show verified state
      verificationPending.value = false
      success('Phone number verified successfully!')
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
    requiresWhatsAppMessage.value = false

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone/request', {
      method: 'POST',
      headers: buildHeaders(true),
      body: JSON.stringify({ phone_number: phoneNumber.value }),
    })

    const data = payload as any

    if (!response.ok) {
      throw new Error(getErrorMessage(payload, 'Failed to generate verification code'))
    }

    // Update status with the new code and expiration
    if (data?.verification_code && data?.expires_at) {
      status.value = {
        ...status.value,
        verification_code: data.verification_code,
        expires_at: data.expires_at,
        pending_verification: true,
        phone_number: data.phone_number,
      }
      verificationPending.value = true
      requiresWhatsAppMessage.value = false
      success('Verification code generated! Send it to our WhatsApp number.')
    } else {
      throw new Error('Invalid response from server')
    }
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

const regenerateCode = async () => {
  // Regenerate by calling requestVerification again
  await requestVerification()
}

const cancelVerification = async () => {
  try {
    // Cancel verification by removing phone number
    await removePhone()
    verificationPending.value = false
    verificationCode.value = ''
    requiresWhatsAppMessage.value = false
    error.value = null
    phoneNumber.value = ''
  } catch (err: any) {
    console.error('Failed to cancel verification:', err)
    // Just clear the local state even if API call fails
    verificationPending.value = false
    verificationCode.value = ''
    requiresWhatsAppMessage.value = false
    error.value = null
  }
}

const removePhone = async () => {
  const confirmed = await dialog.confirm({
    title: t('config.phoneVerification.confirmRemoveTitle'),
    message: t('config.phoneVerification.confirmRemove'),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    danger: true,
  })

  if (!confirmed) return

  try {
    loading.value = true
    error.value = null

    const { response, payload } = await requestWithFallback('/api/v1/user/verify-phone', {
      method: 'DELETE',
      headers: buildHeaders(),
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

const formatDate = (timestamp?: number) => {
  if (!timestamp) return '—'
  return new Date(timestamp * 1000).toLocaleDateString()
}

onMounted(() => {
  loadStatus()
})
</script>
