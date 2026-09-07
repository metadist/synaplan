<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-platform-connect"
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
          <h1 class="text-2xl font-bold txt-primary">{{ $t('platformConnect.title') }}</h1>
          <p class="text-sm txt-secondary mt-2">
            {{ $t('platformConnect.subtitle', { client: clientLabel }) }}
          </p>
        </div>

        <div v-if="viewState === 'loading'" class="text-center py-6" data-testid="section-loading">
          <div class="relative mx-auto w-10 h-10 mb-4">
            <div class="absolute inset-0 rounded-full border-2 border-brand/20"></div>
            <div
              class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand animate-spin"
            ></div>
          </div>
          <p class="txt-secondary text-sm">{{ $t('platformConnect.loading') }}</p>
        </div>

        <div v-else-if="viewState === 'ready'" data-testid="section-ready">
          <p class="txt-primary text-center mb-4">
            {{
              $t('platformConnect.confirmPrompt', {
                client: clientLabel,
                host: confirmHost,
                uid: confirmUid,
              })
            }}
          </p>
          <div
            class="surface-chip rounded-xl p-3 text-center mb-6 ring-1 ring-black/[0.03] dark:ring-white/[0.04]"
          >
            <p class="txt-primary text-sm font-medium">{{ confirmUid }}</p>
            <p class="txt-secondary text-xs mt-1">{{ confirmHost }}</p>
          </div>

          <button
            type="button"
            class="w-full btn-primary py-3 rounded-xl font-medium transition-all duration-200 active:scale-[0.98]"
            data-testid="btn-connect"
            @click="handleConnect"
          >
            {{ $t('platformConnect.connectButton', { client: clientLabel }) }}
          </button>

          <p class="text-xs txt-secondary text-center mt-4">
            {{ $t('platformConnect.scopesNote') }}
          </p>
          <button
            type="button"
            class="block mx-auto mt-3 text-xs txt-secondary underline"
            data-testid="btn-not-you"
            @click="handleNotYou"
          >
            {{ $t('platformConnect.notYou') }}
          </button>
        </div>

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
          <p class="txt-secondary text-sm">{{ $t('platformConnect.connecting') }}</p>
        </div>

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
            {{ $t('platformConnect.successTitle') }}
          </p>
          <p class="txt-secondary text-xs">{{ $t('platformConnect.successSubtitle') }}</p>
        </div>

        <div v-else-if="viewState === 'error'" data-testid="section-error">
          <div
            class="rounded-xl p-4 mb-4 bg-red-500/10 ring-1 ring-red-500/20"
            data-testid="error-message"
          >
            <p class="txt-primary text-sm font-medium mb-1">
              {{ $t('platformConnect.errorTitle') }}
            </p>
            <p class="txt-secondary text-xs">{{ errorMessage }}</p>
          </div>
          <button
            type="button"
            class="w-full btn-primary py-3 rounded-xl font-medium"
            data-testid="btn-retry"
            @click="handleRetry"
          >
            {{ $t('platformConnect.retry') }}
          </button>
        </div>

        <div class="mt-6 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
          <p class="text-xs txt-secondary text-center">
            {{ $t('platformConnect.helpLine') }}
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
import { supportedLanguages } from '@/i18n'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { authReady } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { isPlatformLinksEnabled } from '@/composables/usePlatformLinksFeature'
import { createApiKey } from '@/services/api/apiKeysApi'
import { platformLinksApi } from '@/services/api/platformLinksApi'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'
import { resolvePlatformClient, type PlatformClientPolicy } from '@/platform-connect/clients'

interface SignInPayload {
  state: string
  apiKey: string
  keyId: number
  email: string
  baseUrl: string
}

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
const publicHost = ref<string>('')
const clientPolicy = ref<PlatformClientPolicy | null>(null)

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const currentLanguage = computed(() => locale.value)
const userEmail = computed(() => authStore.user?.email ?? '')
const stateNonce = computed(() => (route.query.state as string | undefined) ?? '')

const clientLabel = computed(() =>
  clientPolicy.value ? t(clientPolicy.value.labelKey) : t('platformConnect.clients.unknown')
)

const confirmHost = computed(() => {
  if (clientPolicy.value?.delivery === 'link-code') {
    return publicHost.value
  }
  return targetBaseUrl.value
})

const confirmUid = computed(() => {
  if (clientPolicy.value?.delivery === 'link-code') {
    return ((route.query.external_id as string | undefined) ?? '').trim()
  }
  return userEmail.value
})

const targetBaseUrl = computed(() => {
  const fromQuery = route.query.baseUrl as string | undefined
  if (fromQuery) return fromQuery
  return window.location.origin
})

function isSafeRedirect(url: string): boolean {
  try {
    const u = new URL(url)
    if (u.protocol !== 'https:') return false
    const h = u.hostname
    return (
      h === 'localhost' ||
      h === '127.0.0.1' ||
      h === 'addin.synaplan.com' ||
      h.endsWith('.synaplan.com')
    )
  } catch {
    return false
  }
}

const redirectTarget = computed(() => {
  const raw = route.query.redirect as string | undefined
  return raw && isSafeRedirect(raw) ? raw : ''
})

function cycleLanguage(): void {
  const currentIndex = supportedLanguages.indexOf(
    locale.value as (typeof supportedLanguages)[number]
  )
  const next = supportedLanguages[(currentIndex + 1) % supportedLanguages.length]
  locale.value = next
  localStorage.setItem('language', next)
}

function loadOfficeJs(): Promise<OfficeApi | null> {
  return new Promise((resolve) => {
    const w = window as unknown as { Office?: OfficeApi }
    let settled = false
    const settle = (v: OfficeApi | null): void => {
      if (settled) return
      settled = true
      resolve(v)
    }

    if (w.Office?.onReady) {
      w.Office.onReady(() => settle(w.Office ?? null))
      return
    }

    const existing = document.querySelector<HTMLScriptElement>('script[data-office-js="1"]')
    if (existing) {
      settle(null)
      return
    }

    const tag = document.createElement('script')
    tag.src = 'https://appsforoffice.microsoft.com/lib/1/hosted/office.js'
    tag.async = true
    tag.dataset.officeJs = '1'
    tag.addEventListener('load', () => {
      const office = (window as unknown as { Office?: OfficeApi }).Office
      if (!office?.onReady) {
        settle(null)
        return
      }
      office.onReady(() => settle(office))
    })
    tag.addEventListener('error', () => settle(null))
    document.head.appendChild(tag)

    setTimeout(() => settle(null), 4000)
  })
}

function buildKeyName(): string {
  const ua = navigator.userAgent
  let host = 'browser'
  const m = ua.match(/Windows NT|Mac OS X|Linux/i)
  if (m) host = m[0]
  return `Outlook Add-in (${host})`
}

async function postPayloadToParent(payload: SignInPayload): Promise<void> {
  const serialized = JSON.stringify(payload)
  let sent = false

  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(serialized, '*')
      sent = true
    }
  } catch {
    // channel 2 may still succeed
  }

  try {
    const office = (window as unknown as { Office?: OfficeApi }).Office
    if (office?.context?.ui?.messageParent) {
      office.context.ui.messageParent(serialized)
      sent = true
    }
  } catch {
    // channel 1 may have succeeded
  }

  if (!sent) {
    throw new Error(
      'No channel available to deliver the sign-in payload to the parent — ' +
        'window.opener is missing and Office.js is not present.'
    )
  }
}

async function handleOutlookConnect(): Promise<void> {
  if (!stateNonce.value) {
    errorMessage.value = t('platformConnect.errorMissingState')
    viewState.value = 'error'
    return
  }
  if (!officeReady.value) {
    errorMessage.value = t('platformConnect.errorOfficeNotReady')
    viewState.value = 'error'
    return
  }
  if (!userEmail.value) {
    errorMessage.value = t('platformConnect.errorNotSignedIn')
    viewState.value = 'error'
    return
  }

  const response = await createApiKey({
    name: buildKeyName(),
    scopes: ['messages:*', 'chats:*', 'files:*', 'rag:*'],
  })

  if (!response.success || !response.api_key?.key) {
    throw new Error(t('platformConnect.errorIssuanceFailed'))
  }

  const payload: SignInPayload = {
    state: stateNonce.value,
    apiKey: response.api_key.key,
    keyId: response.api_key.id,
    email: userEmail.value,
    baseUrl: targetBaseUrl.value,
  }

  if (redirectTarget.value) {
    const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(payload))))
    const sep = redirectTarget.value.includes('#') ? '&' : '#'
    viewState.value = 'success'
    window.location.assign(`${redirectTarget.value}${sep}payload=${encodeURIComponent(encoded)}`)
    return
  }

  await postPayloadToParent(payload)
  viewState.value = 'success'
}

async function handleLinkCodeConnect(): Promise<void> {
  const instanceId = ((route.query.instance_id as string | undefined) ?? '').trim()
  const externalId = ((route.query.external_id as string | undefined) ?? '').trim()
  const redirectUri = ((route.query.redirect_uri as string | undefined) ?? '').trim()
  const state = stateNonce.value.trim()
  if (!instanceId || !externalId || !redirectUri || !state) {
    errorMessage.value = t('platformConnect.errorMissingState')
    viewState.value = 'error'
    return
  }

  const withMemories = route.query.with_memories === '1' || route.query.with_memories === 'true'
  const response = await platformLinksApi.createLinkCode({
    instanceId,
    externalId,
    redirectUri,
    state,
    withMemories,
  })
  if (!response.redirect) {
    throw new Error(t('platformConnect.errorIssuanceFailed'))
  }
  viewState.value = 'success'
  window.location.assign(response.redirect)
}

async function handleConnect(): Promise<void> {
  errorMessage.value = ''
  viewState.value = 'connecting'
  try {
    if (clientPolicy.value?.delivery === 'link-code') {
      await handleLinkCodeConnect()
      return
    }
    await handleOutlookConnect()
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : String(err)
    viewState.value = 'error'
  }
}

async function handleNotYou(): Promise<void> {
  const redirect = route.fullPath
  await authStore.logout()
  setPendingRedirect(redirect)
  void router.push({ path: '/login', query: { redirect } })
}

function handleRetry(): void {
  errorMessage.value = ''
  viewState.value = 'loading'
  void bootstrap()
}

async function bootstrap(): Promise<void> {
  viewState.value = 'loading'
  try {
    const policy = resolvePlatformClient(route.query.client)
    clientPolicy.value = policy
    if (!policy) {
      errorMessage.value = t('platformConnect.errorUnknownClient')
      viewState.value = 'error'
      return
    }
    if (policy.flagGated && !isPlatformLinksEnabled()) {
      errorMessage.value = t('platformConnect.errorFlagOff')
      viewState.value = 'error'
      return
    }

    await authReady

    if (!authStore.isAuthenticated) {
      const redirect = route.fullPath
      setPendingRedirect(redirect)
      void router.push({ path: '/login', query: { redirect } })
      return
    }

    if (!stateNonce.value) {
      errorMessage.value = t('platformConnect.errorMissingState')
      viewState.value = 'error'
      return
    }

    if (policy.delivery === 'link-code') {
      const instanceId = ((route.query.instance_id as string | undefined) ?? '').trim()
      if (!instanceId) {
        errorMessage.value = t('platformConnect.errorMissingState')
        viewState.value = 'error'
        return
      }
      const publicInfo = await platformLinksApi.getPublicInstance(instanceId)
      publicHost.value = publicInfo.host
      viewState.value = 'ready'
      return
    }

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
