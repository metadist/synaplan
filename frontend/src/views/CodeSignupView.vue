<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-code-signup"
  >
    <div class="absolute top-6 right-6 flex items-center gap-4">
      <button
        type="button"
        class="h-10 px-4 rounded-xl icon-ghost text-sm font-medium"
        @click="cycleLanguage"
      >
        {{ currentLanguage.toUpperCase() }}
      </button>
      <button
        type="button"
        class="h-10 w-10 rounded-xl icon-ghost flex items-center justify-center"
        :aria-label="$t('appearance.cycle')"
        @click="toggleTheme"
      >
        <SunIcon v-if="themeStore.isDark?.value" class="w-5 h-5" />
        <MoonIcon v-else class="w-5 h-5" />
      </button>
    </div>

    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <router-link to="/login" class="inline-block">
          <img :src="logoSrc" :alt="config.branding.name" class="h-12 mx-auto mb-6" />
        </router-link>
        <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('codeSignup.title') }}</h1>
        <p class="txt-secondary">{{ $t('codeSignup.lead') }}</p>
      </div>

      <div class="surface-card p-8">
        <p
          v-if="phase === 'opening'"
          class="txt-secondary text-sm"
          role="status"
          aria-live="polite"
          data-testid="text-opening"
        >
          {{ $t('codeSignup.opening') }}
        </p>

        <div v-else-if="phase === 'ready'" class="text-center space-y-4" data-testid="text-ready">
          <h2 class="text-2xl font-bold txt-primary">{{ $t('codeSignup.readyTitle') }}</h2>
          <p class="txt-secondary text-sm leading-relaxed" role="status">
            {{ created ? $t('codeSignup.readyCreated') : $t('codeSignup.readyExisting') }}
          </p>
          <router-link
            to="/"
            class="btn-primary inline-flex items-center justify-center w-full px-4 py-2.5 text-sm font-medium"
            data-testid="link-chat"
          >
            {{ $t('codeSignup.goToChat') }}
          </router-link>
        </div>

        <div v-else-if="phase === 'sent'" class="text-center space-y-4" data-testid="text-sent">
          <h2 class="text-2xl font-bold txt-primary">{{ $t('codeSignup.sentTitle') }}</h2>
          <p class="txt-secondary text-sm leading-relaxed" role="status">
            {{ $t('codeSignup.sent', { email }) }}
          </p>
        </div>

        <form v-else class="space-y-5" data-testid="form-code-signup" @submit.prevent="submit">
          <div>
            <label for="code-signup-email" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('auth.email') }}
            </label>
            <input
              id="code-signup-email"
              v-model="email"
              type="email"
              required
              autocomplete="email"
              class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              data-testid="input-email"
            />
          </div>
          <div>
            <label for="code-signup-code" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('codeSignup.code') }}
            </label>
            <input
              id="code-signup-code"
              v-model="code"
              type="text"
              required
              autocapitalize="characters"
              autocomplete="off"
              class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              data-testid="input-code"
            />
          </div>
          <p
            v-if="errorKey"
            class="text-sm text-red-600 dark:text-red-400"
            role="alert"
            data-testid="text-error"
          >
            {{ $t(errorKey) }}
          </p>
          <p v-if="errorKey === 'codeSignup.off'" class="text-sm">
            <router-link to="/register" class="txt-primary underline" data-testid="link-register">
              {{ $t('codeSignup.createAccount') }}
            </router-link>
          </p>
          <p
            v-if="errorKey === 'codeSignup.uncertain' || errorKey === 'codeSignup.external'"
            class="text-sm"
          >
            <router-link to="/login" class="txt-primary underline" data-testid="link-sign-in">
              {{ $t('codeSignup.signIn') }}
            </router-link>
          </p>
          <button
            type="submit"
            class="btn-primary w-full px-4 py-2.5 text-sm font-medium"
            :disabled="submitting"
            data-testid="btn-continue"
          >
            {{ submitting ? $t('codeSignup.sending') : $t('codeSignup.continue') }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline'
import { cycleLocale } from '@/i18n'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useSignupChallenge } from '@/composables/useSignupChallenge'
import { useTheme } from '@/composables/useTheme'
import { consumeCodeSignup, requestCodeSignup } from '@/services/api/codeSignupApi'
import { ApiError } from '@/services/api/httpClient'
import { authService } from '@/services/authService'
import { resetPrincipalClientState } from '@/services/resetPrincipalClientState'
import { setSessionHint } from '@/services/sessionHint'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'

const route = useRoute()
const { locale } = useI18n()
const themeStore = useTheme()
const config = useConfigStore()
const { getToken: getChallengeToken } = useSignupChallenge()
const { logoSrc } = useBrandLogo(themeStore.isDark)

const email = ref('')
const code = ref('')
const submitting = ref(false)
const created = ref(false)
const errorKey = ref<string | null>(null)
const phase = ref<'form' | 'sent' | 'opening' | 'ready' | 'failed'>('form')

const currentLanguage = computed(() => locale.value)
const cycleLanguage = () => {
  void cycleLocale()
}
const toggleTheme = () => themeStore.cycleTheme()

function messageKey(error: unknown, forLink: boolean): string {
  if (!(error instanceof ApiError)) {
    // The account may already exist. Do not say that nothing was created.
    return forLink ? 'codeSignup.uncertain' : 'codeSignup.unreachable'
  }
  if (error.code === 'SIGNUP_CODE_INVALID') return 'codeSignup.invalid'
  if (error.code === 'CODE_SIGNUP_RATE_LIMITED') return 'codeSignup.limited'
  if (error.code === 'CODE_SIGNUP_OFF') return 'codeSignup.off'
  if (error.code === 'SIGNUP_CHALLENGE_FAILED') return 'codeSignup.challenge'
  if (error.code === 'CODE_SIGNUP_EXTERNAL') return 'codeSignup.external'
  if (forLink && error.code === 'CODE_SIGNUP_INVALID') return 'codeSignup.linkInvalid'
  return forLink ? 'codeSignup.linkInvalid' : 'codeSignup.unreachable'
}

async function submit(): Promise<void> {
  errorKey.value = null
  submitting.value = true
  try {
    const challengeToken = await getChallengeToken('code_signup')
    await requestCodeSignup(email.value.trim(), code.value.trim(), challengeToken)
    phase.value = 'sent'
  } catch (error) {
    errorKey.value = messageKey(error, false)
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  const token = route.query.token
  if (typeof token !== 'string' || '' === token) return
  phase.value = 'opening'
  try {
    const result = await consumeCodeSignup(token)
    setSessionHint()
    authService.adoptSession({
      id: result.user.id,
      email: result.user.email,
      level: result.user.level,
      isAdmin: result.user.isAdmin,
      emailVerified: result.user.emailVerified,
    })
    await resetPrincipalClientState()
    useAuthStore().adoptCurrentSession()
    try {
      await useAuthStore().refreshUser()
      await useConfigStore().reload()
    } catch {
      // The cookies are already set. The next page load reads the session.
    }
    created.value = result.created
    phase.value = 'ready'
  } catch (error) {
    errorKey.value = messageKey(error, true)
    phase.value = 'failed'
  }
})
</script>
