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

        <div v-if="busyLabel" class="text-center py-6" role="status">
          <div class="relative mx-auto w-10 h-10 mb-4">
            <div class="absolute inset-0 rounded-full border-2 border-brand/20"></div>
            <div
              class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand animate-spin"
            ></div>
          </div>
          <p class="txt-secondary text-sm">
            {{ busyLabel }}
          </p>
        </div>

        <div v-else-if="viewState === 'ready' && consent" data-testid="section-ready">
          <p class="txt-primary text-center mb-4">
            {{
              $t('platformConnect.confirmPrompt', {
                client: clientLabel,
                host: consent.host,
                uid: consent.external_id,
              })
            }}
          </p>
          <div
            class="surface-chip rounded-xl p-3 text-center ring-1 ring-black/[0.03] dark:ring-white/[0.04] mb-3"
          >
            <p class="txt-primary text-sm font-medium break-all">{{ consent.external_id }}</p>
            <p class="txt-secondary text-xs mt-1 break-all">{{ consent.host }}</p>
          </div>
          <p class="txt-secondary text-xs text-center mb-5" data-testid="signed-in-as">
            {{ $t('platformConnect.signedInAs', { email: userEmail }) }}
          </p>
          <p
            v-if="consent.unreviewed"
            class="text-sm text-red-600 dark:text-red-400 text-center mb-5"
            data-testid="unreviewed-cloud"
          >
            {{ $t('platformConnect.unreviewed') }}
          </p>

          <div v-if="permissions.length > 0" class="mb-6" data-testid="section-permissions">
            <p class="txt-primary text-sm font-medium mb-2">
              {{ $t('platformConnect.permissionsTitle', { client: clientLabel }) }}
            </p>
            <ul class="space-y-1.5">
              <li
                v-for="permission in permissions"
                :key="permission"
                class="flex items-start gap-2 text-sm txt-secondary"
              >
                <Icon
                  icon="heroicons:check"
                  class="w-4 h-4 mt-0.5 shrink-0 text-brand"
                  aria-hidden="true"
                />
                <span>{{ $t(permission) }}</span>
              </li>
            </ul>
          </div>

          <button
            type="button"
            class="btn-primary w-full px-4 py-2.5 text-sm font-medium"
            data-testid="btn-connect"
            @click="decide(true)"
          >
            {{ $t('platformConnect.connectButton', { client: clientLabel }) }}
          </button>
          <button
            type="button"
            class="btn-secondary w-full mt-2 px-4 py-2.5 text-sm font-medium"
            data-testid="btn-deny"
            @click="decide(false)"
          >
            {{ $t('platformConnect.denyButton') }}
          </button>

          <p class="text-xs txt-secondary text-center mt-4">
            {{ $t('platformConnect.revocableNote') }}
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
          <div class="alert-error" role="alert" data-testid="error-message">
            <p class="alert-error-text text-sm mb-1">
              {{ $t('platformConnect.errorTitle') }}
            </p>
            <p class="alert-error-text text-xs font-normal">{{ errorMessage }}</p>
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
import { Icon } from '@iconify/vue'
import { useAuthStore, authReady } from '@/stores/auth'
import { ApiError } from '@/services/api/httpClient'
import { platformLinksApi, type CloudConsent } from '@/services/api/platformLinksApi'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'
import { resolvePlatformClient } from '@/platform-connect/clients'

/**
 * /connect/cloud — consent for a cloud (Nextcloud, ownCloud) that asks to
 * act for the signed-in person through Synaplan's OAuth2 server. The cloud's
 * server pushed the authorization request and the browser arrives with its
 * id under `?request=`; this page shows which cloud account asks and what
 * it may do, and the person allows or denies. The browser then follows the
 * redirect the backend returns, back to the cloud.
 */

type ViewState = 'loading' | 'ready' | 'connecting' | 'denying' | 'error'

const BUSY_KEYS: Partial<Record<ViewState, string>> = {
  loading: 'platformConnect.loading',
  connecting: 'platformConnect.connecting',
  denying: 'platformConnect.returning',
}

/** What each scope a cloud can ask for lets it do, in the person's words. */
const PERMISSION_KEYS: Record<string, string> = {
  chat: 'platformConnect.permissions.chat',
  files: 'platformConnect.permissions.files',
  rag: 'platformConnect.permissions.rag',
  'cloud:watch': 'platformConnect.permissions.watch',
  memories: 'platformConnect.permissions.memories',
}

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
const busyLabel = computed(() => {
  const key = BUSY_KEYS[viewState.value]
  return key ? t(key) : ''
})
const permissions = computed(() =>
  (consent.value?.scopes ?? []).flatMap((scope) =>
    scope in PERMISSION_KEYS ? [PERMISSION_KEYS[scope]] : []
  )
)

/**
 * A request Synaplan no longer knows ran out, was decided before, or never
 * existed; the way forward is the same. Anything else is said without the
 * server's wording.
 */
function fail(err?: unknown): void {
  const gone = err === undefined || (err instanceof ApiError && err.status === 404)
  errorMessage.value = t(
    gone ? 'platformConnect.errorExpired' : 'platformConnect.errorIssuanceFailed'
  )
  viewState.value = 'error'
}

async function decide(approve: boolean): Promise<void> {
  viewState.value = approve ? 'connecting' : 'denying'
  try {
    const { redirect } = await platformLinksApi.decideCloudConsent(requestId.value, approve)
    window.location.assign(redirect)
  } catch (err) {
    fail(err)
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
    fail()
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
    fail(err)
  }
}

onMounted(() => {
  void bootstrap()
})
</script>
