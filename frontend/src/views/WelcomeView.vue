<template>
  <div class="min-h-screen flex items-center justify-center bg-chat px-4 py-10">
    <div class="w-full max-w-md surface-card rounded-2xl p-6 sm:p-8" data-testid="welcome-card">
      <p class="text-sm txt-primary" data-testid="welcome-status">{{ sentence }}</p>

      <router-link
        v-if="state === 'ready'"
        to="/"
        class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
        data-testid="welcome-to-chat"
      >
        {{ $t('auth.welcome.toChat') }}
      </router-link>
      <router-link
        v-else-if="state === 'existing' || state === 'other'"
        to="/login"
        class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
      >
        {{ $t('auth.login') }}
      </router-link>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { checkoutApi } from '@/services/api/checkoutApi'
import { ApiError } from '@/services/api/httpClient'

const ATTEMPTS = 6
const PAUSE_MS = 2000

const route = useRoute()
const { t } = useI18n()

const state = ref<'working' | 'ready' | 'existing' | 'conflict' | 'slow' | 'other' | 'missing'>(
  'working'
)
const plan = ref('')
const email = ref('')
let stopped = false

const sentence = computed(() => {
  const values = { plan: planName(plan.value), email: email.value }
  const key = {
    working: 'auth.welcome.working',
    ready: 'auth.welcome.ready',
    existing: 'auth.welcome.existing',
    conflict: 'auth.welcome.conflict',
    slow: 'auth.welcome.slow',
    other: 'auth.welcome.otherBrowser',
    missing: 'auth.welcome.notFound',
  }[state.value]
  return t(key, values)
})

onMounted(settle)
onUnmounted(() => {
  stopped = true
})

async function settle() {
  const sessionId = String(route.query.session_id || '')
  if ('' === sessionId) {
    state.value = 'missing'
    return
  }
  for (let attempt = 0; attempt < ATTEMPTS && !stopped; attempt++) {
    try {
      const claim = await checkoutApi.claim(sessionId)
      if (stopped) return
      plan.value = claim.plan
      email.value = claim.email
      if (claim.status === 'pending') {
        await pause()
        continue
      }
      state.value = claim.status === 'ready' ? (claim.signedIn ? 'ready' : 'other') : claim.status
      return
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        state.value = 'missing'
        return
      }
      await pause()
    }
  }
  if (!stopped) state.value = plan.value ? 'slow' : 'missing'
}

function pause(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, PAUSE_MS))
}

function planName(id: string): string {
  return id ? id.charAt(0) + id.slice(1).toLowerCase() : ''
}
</script>
