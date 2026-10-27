<template>
  <div class="min-h-screen flex items-center justify-center bg-chat px-4 py-10">
    <div class="w-full max-w-md surface-card rounded-2xl p-6 sm:p-8">
      <h1 class="text-xl font-semibold txt-primary mb-1">
        {{ knownPlan ? $t('auth.start.title', { plan: planName }) : $t('auth.start.planInvalid') }}
      </h1>
      <p v-if="priceLine" class="text-sm txt-secondary mb-4">{{ priceLine }}</p>

      <p v-if="cancelled" class="text-sm txt-primary mb-4" data-testid="start-cancelled">
        {{ $t('auth.start.cancelled') }}
      </p>

      <form v-if="knownPlan" class="flex flex-col gap-3" @submit.prevent="submit">
        <label class="text-sm font-medium txt-primary" for="start-email">
          {{ $t('auth.start.emailLabel') }}
        </label>
        <input
          id="start-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="start-email"
        />
        <p class="text-xs txt-secondary">{{ $t('auth.start.emailHint') }}</p>

        <p
          v-if="message"
          class="text-sm text-red-600 dark:text-red-400"
          role="alert"
          data-testid="start-message"
        >
          {{ message }}
        </p>

        <button
          type="submit"
          class="btn-primary px-4 py-2.5 text-sm font-medium"
          :disabled="busy"
          data-testid="start-continue"
        >
          {{ $t('auth.start.continue') }}
        </button>
      </form>

      <router-link
        v-else
        to="/register"
        class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex"
      >
        {{ $t('auth.register') }}
      </router-link>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { checkoutApi } from '@/services/api/checkoutApi'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import { ApiError } from '@/services/api/httpClient'
import { useSignupChallenge } from '@/composables/useSignupChallenge'
import { getConfigSync } from '@/services/api/httpClient'

const PLANS = ['PRO', 'TEAM', 'BUSINESS']

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { getToken } = useSignupChallenge()

const plan = computed(() => String(route.query.plan || '').toUpperCase())
const knownPlan = computed(() => PLANS.includes(plan.value))
const planName = computed(() => plan.value.charAt(0) + plan.value.slice(1).toLowerCase())
const cancelled = computed(() => route.query.cancelled === '1')

const email = ref('')
const busy = ref(false)
const message = ref('')
const priceLine = ref('')

if (getConfigSync().auth?.payFirst !== true) {
  void router.replace('/register')
}

if (knownPlan.value) {
  subscriptionApi
    .getPlans()
    .then((catalogue) => {
      const found = catalogue.plans.find((entry) => entry.id === plan.value)
      if (found) {
        priceLine.value = `${found.price} ${found.currency} ${t('auth.start.perMonth')}`
      }
    })
    .catch(() => undefined)
}

const MESSAGE_FOR_CODE: Record<string, string> = {
  PAY_FIRST_EMAIL_INVALID: 'auth.start.emailInvalid',
  PAY_FIRST_PLAN_INVALID: 'auth.start.planInvalid',
  PAY_FIRST_RATE_LIMITED: 'auth.start.busy',
  PAY_FIRST_UNAVAILABLE: 'auth.start.unavailable',
  SIGNUP_PAUSED: 'auth.start.paused',
  SIGNUP_CHALLENGE_FAILED: 'auth.start.unavailable',
}

async function submit() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  try {
    const challengeToken = await getToken('checkout_start')
    const started = await checkoutApi.start(email.value.trim(), plan.value, challengeToken)
    window.location.assign(started.checkoutUrl)
  } catch (err) {
    if (err instanceof ApiError && err.code === 'PAY_FIRST_OFF') {
      void router.replace('/register')
      return
    }
    const key = err instanceof ApiError ? MESSAGE_FOR_CODE[err.code ?? ''] : undefined
    message.value = t(key ?? 'auth.start.unavailable')
  } finally {
    busy.value = false
  }
}
</script>
