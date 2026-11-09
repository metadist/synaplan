<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12 relative overflow-hidden"
    data-testid="page-cloud-consent"
  >
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div
        class="absolute -top-24 left-1/4 w-[28rem] h-[28rem] bg-brand/6 dark:bg-brand/12 rounded-full blur-3xl animate-float"
      ></div>
      <div
        class="absolute -bottom-24 right-1/4 w-[28rem] h-[28rem] bg-brand/4 dark:bg-brand/8 rounded-full blur-3xl animate-float-delayed"
      ></div>
    </div>

    <div class="w-full max-w-sm relative z-10" data-testid="section-card">
      <div
        class="backdrop-blur-xl rounded-[1.25rem] shadow-xl p-8 bg-[var(--bg-card)] ring-1 ring-black/[0.05] shadow-black/[0.04] dark:ring-white/[0.04] dark:shadow-black/30"
      >
        <div class="text-center mb-6" data-testid="section-header">
          <div class="w-10 h-[3px] bg-brand rounded-full mx-auto mb-5"></div>
          <h1 class="text-2xl font-bold txt-primary">{{ $t('platformConnect.title') }}</h1>
          <p class="text-sm txt-secondary mt-2">
            {{ $t('platformConnect.subtitle', { client: clientLabel }) }}
          </p>
        </div>

        <div v-if="viewState === 'loading' || viewState === 'connecting'" class="text-center py-6">
          <div class="relative mx-auto w-10 h-10 mb-4">
            <div class="absolute inset-0 rounded-full border-2 border-brand/20"></div>
            <div
              class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand animate-spin"
            ></div>
          </div>
          <p class="txt-secondary text-sm">
            {{
              viewState === 'loading'
                ? $t('platformConnect.loading')
                : $t('platformConnect.connecting')
            }}
          </p>
        </div>

        <div v-else-if="viewState === 'ready' && consent" data-testid="section-ready">
          <p class="txt-primary text-center mb-4">
            {{
              $t('platformConnect.confirmPrompt', {
                client: clientLabel,
                host: consent.host,
                uid: consent.externalId,
              })
            }}
          </p>
          <div
            class="surface-chip rounded-xl p-3 text-center ring-1 ring-black/[0.03] dark:ring-white/[0.04] mb-3"
          >
            <p class="txt-primary text-sm font-medium">{{ consent.externalId }}</p>
            <p class="txt-secondary text-xs mt-1">{{ consent.host }}</p>
          </div>
          <p class="txt-secondary text-xs text-center mb-6" data-testid="signed-in-as">
            {{ $t('platformConnect.signedInAs', { email: userEmail }) }}
          </p>

          <button
            type="button"
            class="w-full btn-primary px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 active:scale-[0.98]"
            data-testid="btn-connect"
            @click="decide(true)"
          >
            {{ $t('platformConnect.connectButton', { client: clientLabel }) }}
          </button>
          <button
            type="button"
            class="w-full mt-2 px-4 py-3 rounded-xl text-sm font-medium icon-ghost"
            data-testid="btn-deny"
            @click="decide(false)"
          >
            {{ $t('platformConnect.denyButton') }}
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

        <div v-else-if="viewState === 'error'" data-testid="section-error">
          <div
            class="rounded-xl p-4 bg-red-500/10 ring-1 ring-red-500/20"
            data-testid="error-message"
          >
            <p class="txt-primary text-sm font-medium mb-1">
              {{ $t('platformConnect.errorTitle') }}
            </p>
            <p class="txt-secondary text-xs">{{ errorMessage }}</p>
          </div>
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
import { useAuthStore, authReady } from '@/stores/auth'
import { platformLinksApi, type CloudConsent } from '@/services/api/platformLinksApi'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'
import { resolvePlatformClient } from '@/platform-connect/clients'

/**
 * /connect/cloud — consent for a cloud (Nextcloud, ownCloud) that asks to
 * act for the signed-in person through Synaplan's OAuth2 server. The backend
 * parked the checked authorization request under `?request=`; this page
 * shows which cloud account asks, and the person allows or denies. The
 * browser then follows the redirect the backend returns, back to the cloud.
 */

type ViewState = 'loading' | 'ready' | 'connecting' | 'error'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const authStore = useAuthStore()

const viewState = ref<ViewState>('loading')
const errorMessage = ref('')
const consent = ref<CloudConsent | null>(null)

const requestId = computed(() => ((route.query.request as string | undefined) ?? '').trim())
const userEmail = computed(() => authStore.user?.email ?? '')
const clientLabel = computed(() => {
  const policy = resolvePlatformClient(consent.value?.client)
  return policy ? t(policy.labelKey) : t('platformConnect.clients.unknown')
})

function fail(message: string): void {
  errorMessage.value = message
  viewState.value = 'error'
}

async function decide(approve: boolean): Promise<void> {
  viewState.value = 'connecting'
  try {
    const { redirect } = await platformLinksApi.decideCloudConsent(requestId.value, approve)
    window.location.assign(redirect)
  } catch (err) {
    fail(err instanceof Error ? err.message : String(err))
  }
}

async function handleNotYou(): Promise<void> {
  const redirect = route.fullPath
  await authStore.logout()
  setPendingRedirect(redirect)
  void router.push({ path: '/login', query: { redirect } })
}

async function bootstrap(): Promise<void> {
  if (!/^[a-f0-9]{48}$/.test(requestId.value)) {
    fail(t('platformConnect.errorMissingState'))
    return
  }
  await authReady
  if (!authStore.isAuthenticated) {
    const redirect = route.fullPath
    setPendingRedirect(redirect)
    void router.push({ path: '/login', query: { redirect } })
    return
  }
  try {
    consent.value = await platformLinksApi.getCloudConsent(requestId.value)
    viewState.value = 'ready'
  } catch (err) {
    fail(err instanceof Error ? err.message : String(err))
  }
}

onMounted(() => {
  void bootstrap()
})
</script>
