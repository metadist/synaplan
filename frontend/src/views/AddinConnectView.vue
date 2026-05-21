<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-addin-connect"
  >
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute -top-24 left-1/4 w-[28rem] h-[28rem] bg-brand/6 dark:bg-brand/12 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute -bottom-24 right-1/4 w-[28rem] h-[28rem] bg-brand/4 dark:bg-brand/8 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>

    <div class="absolute top-6 right-6 flex items-center gap-2" data-testid="section-controls">
      <button class="h-9 px-3 rounded-lg icon-ghost text-xs font-medium" @click="cycleLanguage">
        {{ currentLanguage.toUpperCase() }}
      </button>
    </div>

    <div class="w-full max-w-sm relative z-10" data-testid="section-card">
      <div
        class="backdrop-blur-xl rounded-[1.25rem] shadow-xl p-8"
        :class="
          isDark
            ? 'ring-1 ring-white/[0.04] shadow-black/30'
            : 'ring-1 ring-black/[0.05] shadow-black/[0.04]'
        "
        :style="{
          backgroundColor: isDark ? 'rgba(15, 15, 16, 0.8)' : 'rgba(255, 255, 255, 0.95)',
        }"
      >
        <div class="text-center mb-6" data-testid="section-header">
          <div class="w-10 h-[3px] bg-brand rounded-full mx-auto mb-5"></div>
          <h1 class="text-2xl font-bold txt-primary">{{ $t('addinConnect.title') }}</h1>
          <p class="text-sm txt-secondary mt-2">{{ $t('addinConnect.subtitle') }}</p>
        </div>

        <!-- Loading: checking auth / waiting on Office.js -->
        <div v-if="viewState === 'loading'" class="text-center py-6" data-testid="section-loading">
          <div class="relative mx-auto w-10 h-10 mb-4">
            <div class="absolute inset-0 rounded-full border-2 border-brand/20"></div>
            <div
              class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand animate-spin"
            ></div>
          </div>
          <p class="txt-secondary text-sm">{{ $t('addinConnect.loading') }}</p>
        </div>

        <!-- Ready: authenticated user, ask to confirm the connection -->
        <div v-else-if="viewState === 'ready'" data-testid="section-ready">
          <p class="txt-primary text-center mb-4">
            {{ $t('addinConnect.confirmPrompt') }}
          </p>
          <div
            class="surface-chip rounded-xl p-3 text-center mb-6 ring-1 ring-black/[0.03] dark:ring-white/[0.04]"
          >
            <p class="txt-primary text-sm font-medium">{{ userEmail }}</p>
            <p class="txt-secondary text-xs mt-1">{{ targetBaseUrl }}</p>
          </div>

          <button
            type="button"
            class="w-full btn-primary py-3 rounded-xl font-medium transition-all duration-200 active:scale-[0.98]"
            data-testid="btn-connect"
            @click="handleConnect"
          >
            {{ $t('addinConnect.connectButton') }}
          </button>

          <p class="text-xs txt-secondary text-center mt-4">
            {{ $t('addinConnect.scopesNote') }}
          </p>
        </div>

        <!-- Connecting: spinner while creating the API key -->
        <div
          v-else-if="viewState === 'connecting'"
          class="text-center py-6"
          data-testid="section-connecting"
        >
          <div class="relative mx-auto w-10 h-10 mb-4">
            <div class="absolute inset-0 rounded-full border-2 border-brand/20"></div>
            <div
              class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand animate-spin"
            ></div>
          </div>
          <p class="txt-secondary text-sm">{{ $t('addinConnect.connecting') }}</p>
        </div>

        <!-- Success: payload sent to parent, popup will close itself -->
        <div
          v-else-if="viewState === 'success'"
          class="text-center py-6"
          data-testid="section-success"
        >
          <div
            class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-500/10 flex items-center justify-center"
          >
            <CheckCircleIcon class="w-10 h-10 text-green-500" />
          </div>
          <p class="txt-primary text-sm font-medium mb-2">
            {{ $t('addinConnect.successTitle') }}
          </p>
          <p class="txt-secondary text-xs">{{ $t('addinConnect.successSubtitle') }}</p>
        </div>

        <!-- Error: show the message and offer a retry -->
        <div v-else-if="viewState === 'error'" data-testid="section-error">
          <div
            class="rounded-xl p-4 mb-4 bg-red-500/10 ring-1 ring-red-500/20"
            data-testid="error-message"
          >
            <p class="txt-primary text-sm font-medium mb-1">{{ $t('addinConnect.errorTitle') }}</p>
            <p class="txt-secondary text-xs">{{ errorMessage }}</p>
          </div>
          <button
            type="button"
            class="w-full btn-primary py-3 rounded-xl font-medium"
            data-testid="btn-retry"
            @click="handleRetry"
          >
            {{ $t('addinConnect.retry') }}
          </button>
        </div>

        <div class="mt-6 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
          <p class="text-xs txt-secondary text-center">
            {{ $t('addinConnect.helpLine') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { authReady } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { createApiKey } from '@/services/api/apiKeysApi'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'

/**
 * /addin/connect — Synamail Outlook add-in bridge page.
 *
 * Loaded inside Office.context.ui.displayDialogAsync from the Synamail
 * taskpane. Issues a scoped API key for the signed-in Synaplan user and
 * posts it back to the parent taskpane via Office.context.ui.messageParent.
 *
 * Flow:
 *   1. User clicks "Sign in to Synaplan" in the Outlook taskpane.
 *   2. Outlook opens https://web.synaplan.com/addin/connect?state=<nonce>.
 *   3. If unauthenticated, this view redirects to /login?redirect=...
 *      (with the same state preserved through the round-trip).
 *   4. If authenticated, it shows "Connect this Outlook to <email>?" + a
 *      Connect button.
 *   5. On click: POST /api/v1/apikeys, then Office.context.ui.messageParent
 *      with { state, apiKey, keyId, email, baseUrl }.
 *   6. The taskpane validates the state nonce and stores the payload in
 *      Office.context.roamingSettings.
 *
 * The Synamail repo lives at github.com/metadist/Synamail. See
 * synaMail/docs/SYNAPLAN_INTEGRATION.md for the full integration map.
 */

interface SignInPayload {
  state: string
  apiKey: string
  keyId: number
  email: string
  baseUrl: string
}

// Minimal Office.js typings — Office.js is loaded from the CDN by the
// <script> tag inserted below, not via @types/office-js (which the main
// Synaplan SPA doesn't need as a dependency).
interface OfficeUi {
  messageParent: (data: string) => void
}
interface OfficeApi {
  onReady: (cb: () => void) => void
  context: { ui: OfficeUi }
}

type ViewState = 'loading' | 'ready' | 'connecting' | 'success' | 'error'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const authStore = useAuthStore()
const themeStore = useTheme()

const viewState = ref<ViewState>('loading')
const errorMessage = ref<string>('')
const officeReady = ref(false)

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const currentLanguage = computed(() => locale.value)

const userEmail = computed(() => authStore.user?.email ?? '')

const stateNonce = computed(() => (route.query.state as string | undefined) ?? '')

const targetBaseUrl = computed(() => {
  const fromQuery = route.query.baseUrl as string | undefined
  if (fromQuery) return fromQuery
  return window.location.origin
})

function cycleLanguage(): void {
  const languages = ['de', 'en', 'es', 'tr']
  const currentIndex = languages.indexOf(locale.value)
  const nextIndex = (currentIndex + 1) % languages.length
  locale.value = languages[nextIndex]
  localStorage.setItem('language', languages[nextIndex])
}

function loadOfficeJs(): Promise<OfficeApi> {
  return new Promise((resolve, reject) => {
    const w = window as unknown as { Office?: OfficeApi }
    if (w.Office?.onReady) {
      w.Office.onReady(() => resolve(w.Office as OfficeApi))
      return
    }
    const existing = document.querySelector<HTMLScriptElement>('script[data-office-js="1"]')
    const tag = existing ?? document.createElement('script')
    tag.src = 'https://appsforoffice.microsoft.com/lib/1/hosted/office.js'
    tag.async = true
    tag.dataset.officeJs = '1'
    tag.addEventListener('load', () => {
      const office = (window as unknown as { Office?: OfficeApi }).Office
      if (!office) {
        reject(new Error('Office.js loaded but did not expose the Office global'))
        return
      }
      office.onReady(() => resolve(office))
    })
    tag.addEventListener('error', () => {
      reject(new Error('Failed to load Office.js — are you opening this page inside Outlook?'))
    })
    if (!existing) document.head.appendChild(tag)
  })
}

function buildKeyName(): string {
  const ua = navigator.userAgent
  // Best-effort short host tag for the API key name.
  let host = 'browser'
  const m = ua.match(/Windows NT|Mac OS X|Linux/i)
  if (m) host = m[0]
  return `Outlook Add-in (${host})`
}

async function postPayloadToParent(payload: SignInPayload): Promise<void> {
  const serialized = JSON.stringify(payload)
  const office = (window as unknown as { Office?: OfficeApi }).Office
  if (!office?.context?.ui?.messageParent) {
    throw new Error('Office.context.ui.messageParent is not available')
  }
  office.context.ui.messageParent(serialized)
  // Fallback channel: Office's cross-domain messageParent is unreliable in
  // Outlook on the Web when the taskpane and bridge live on different
  // origins (taskpane on the add-in host, bridge on Synaplan). A direct
  // window.opener.postMessage to the taskpane works whenever the popup's
  // opener relationship survived. The Synamail taskpane listens for both
  // channels and accepts whichever delivers first.
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(serialized, '*')
    }
  } catch {
    // Best-effort fallback — never block the primary success path on it.
  }
}

async function handleConnect(): Promise<void> {
  errorMessage.value = ''
  viewState.value = 'connecting'

  if (!stateNonce.value) {
    errorMessage.value = t('addinConnect.errorMissingState')
    viewState.value = 'error'
    return
  }
  if (!officeReady.value) {
    errorMessage.value = t('addinConnect.errorOfficeNotReady')
    viewState.value = 'error'
    return
  }
  if (!userEmail.value) {
    errorMessage.value = t('addinConnect.errorNotSignedIn')
    viewState.value = 'error'
    return
  }

  try {
    const response = await createApiKey({
      name: buildKeyName(),
      scopes: ['messages:*', 'chats:*', 'files:*', 'rag:*'],
    })

    if (!response.success || !response.api_key?.key) {
      throw new Error(t('addinConnect.errorIssuanceFailed'))
    }

    await postPayloadToParent({
      state: stateNonce.value,
      apiKey: response.api_key.key,
      keyId: response.api_key.id,
      email: userEmail.value,
      baseUrl: targetBaseUrl.value,
    })

    viewState.value = 'success'
    // The parent taskpane closes the dialog as soon as it receives the
    // payload, so we don't have to do anything else here.
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err)
    viewState.value = 'error'
  }
}

function handleRetry(): void {
  errorMessage.value = ''
  viewState.value = officeReady.value && userEmail.value ? 'ready' : 'loading'
  if (viewState.value === 'loading') {
    // Bootstrap again from the top.
    void bootstrap()
  }
}

async function bootstrap(): Promise<void> {
  viewState.value = 'loading'
  try {
    // Wait for the auth store to finish its initial check so user.value
    // reflects the actual cookie session.
    await authReady

    if (!authStore.isAuthenticated) {
      // Stash in sessionStorage too: a social-provider OAuth round-trip
      // would otherwise drop the `redirect` query and strand the user.
      const redirect =
        `/addin/connect?state=${encodeURIComponent(stateNonce.value)}` +
        `&baseUrl=${encodeURIComponent(targetBaseUrl.value)}`
      setPendingRedirect(redirect)
      void router.push({ path: '/login', query: { redirect } })
      return
    }

    if (!stateNonce.value) {
      errorMessage.value = t('addinConnect.errorMissingState')
      viewState.value = 'error'
      return
    }

    // Load Office.js. If we're not in an Office dialog this rejects;
    // we surface that as an error rather than silently sitting on the
    // loading state.
    await loadOfficeJs()
    officeReady.value = true
    viewState.value = 'ready'
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err)
    viewState.value = 'error'
  }
}

onMounted(() => {
  void bootstrap()
})
</script>
