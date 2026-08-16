<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { connectionsApi, type ConnectionItem } from '@/services/api/connectionsApi'
import { m365Api } from '@/services/api/m365Api'
import { useAuthStore } from '@/stores/auth'
import ConnectionStatusPill from '@/components/config/ConnectionStatusPill.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { success, error: showError } = useNotification()

const items = ref<ConnectionItem[]>([])
const loading = ref(true)
const testingId = ref<string | null>(null)
const m365Available = ref(false)
const m365Connecting = ref(false)

const isAdmin = computed(() => auth.isAdmin)

const load = async () => {
  loading.value = true
  try {
    items.value = await connectionsApi.list()
  } catch {
    showError(t('config.connections.loadFailed'))
  } finally {
    loading.value = false
  }
}

const loadM365 = async () => {
  try {
    m365Available.value = (await m365Api.status()).available
  } catch {
    m365Available.value = false
  }
}

const formatChecked = (timestamp: number | null): string =>
  timestamp ? new Date(timestamp * 1000).toLocaleString(locale.value) : ''

const onTest = async (item: ConnectionItem) => {
  if (item.source !== 'registry') {
    if (item.manage_path) {
      await router.push(item.manage_path)
    }
    return
  }
  testingId.value = item.id
  try {
    const result = await connectionsApi.test(item.id)
    items.value = items.value.map((row) => (row.id === item.id ? result.connection : row))
    if (false === result.succeeded) {
      // The tester knows why it failed; a generic "test failed" would send the
      // user back to guessing.
      showError(result.error || t('config.connections.testFailed'))
      return
    }
    success(
      result.account
        ? t('config.connections.testOkAccount', { account: result.account })
        : t('config.connections.testOk')
    )
  } catch {
    showError(t('config.connections.testFailed'))
  } finally {
    testingId.value = null
  }
}

const connectM365 = async () => {
  m365Connecting.value = true
  try {
    window.location.href = await m365Api.authorizeUrl()
  } catch {
    showError(t('config.connections.providers.m365.startFailed'))
    m365Connecting.value = false
  }
}

/** Read the outcome Microsoft's callback redirected us back with, then clean the URL. */
const consumeConsentResult = async () => {
  const result = route.query.m365
  if (typeof result !== 'string') {
    return
  }
  if ('connected' === result) {
    success(t('config.connections.providers.m365.connected'))
  } else {
    const reason = typeof route.query.reason === 'string' ? route.query.reason : 'unknown'
    const known = ['access_denied', 'missing_code', 'exchange_failed', 'invalid_state']
    showError(
      t(
        known.includes(reason)
          ? `config.connections.providers.m365.errors.${reason}`
          : 'config.connections.providers.m365.errors.unknown'
      )
    )
  }
  await router.replace({ query: {} })
}

onMounted(async () => {
  await Promise.all([load(), loadM365()])
  await consumeConsentResult()
})
</script>

<template>
  <div class="space-y-6" data-testid="page-connections">
    <div class="surface-card p-6">
      <h2 class="text-2xl font-semibold txt-primary">{{ $t('config.connections.title') }}</h2>
      <p class="txt-secondary text-sm mt-1">{{ $t('config.connections.subtitle') }}</p>
      <p class="txt-secondary text-sm mt-2">{{ $t('config.connections.explainer') }}</p>
    </div>

    <!-- What the user already has -->
    <section>
      <h3 class="text-sm font-semibold txt-primary mb-2 px-1">
        {{ $t('config.connections.yourConnections') }}
      </h3>

      <div v-if="loading" class="txt-secondary text-sm px-1">
        {{ $t('config.connections.loading') }}
      </div>
      <div
        v-else-if="items.length === 0"
        class="surface-card p-6 txt-secondary text-sm"
        data-testid="connections-empty"
      >
        {{ $t('config.connections.empty') }}
      </div>
      <ul v-else class="space-y-3">
        <li
          v-for="item in items"
          :key="item.id"
          class="surface-card p-4 flex flex-wrap items-center gap-3"
          data-testid="connection-row"
        >
          <div class="flex-1 min-w-0">
            <p class="font-medium txt-primary truncate">{{ item.name }}</p>
            <p class="text-xs txt-secondary">
              {{ $t(`config.connections.types.${item.type}`, item.type) }}
              <span v-if="item.last_checked">
                ·
                {{
                  $t('config.connections.lastChecked', { when: formatChecked(item.last_checked) })
                }}
              </span>
            </p>
            <p
              v-if="item.status === 'reauth_required'"
              class="text-xs text-[var(--status-warning)] mt-1"
            >
              {{ $t('config.connections.reauthHint') }}
            </p>
          </div>
          <ConnectionStatusPill :status="item.status" />
          <button
            v-if="item.type === 'm365' && item.status === 'reauth_required'"
            type="button"
            class="btn-primary inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            :disabled="m365Connecting"
            @click="connectM365"
          >
            <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
            {{ $t('config.connections.reconnect') }}
          </button>
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            :disabled="testingId === item.id"
            data-testid="btn-test-connection"
            @click="onTest(item)"
          >
            <Icon
              :icon="testingId === item.id ? 'heroicons:arrow-path' : 'heroicons:signal'"
              :class="['w-4 h-4', testingId === item.id && 'animate-spin']"
            />
            {{
              testingId === item.id
                ? $t('config.connections.testing')
                : $t('config.connections.test')
            }}
          </button>
        </li>
      </ul>
    </section>

    <!-- What the user can add, and what each option actually does -->
    <section>
      <h3 class="text-sm font-semibold txt-primary mb-2 px-1">
        {{ $t('config.connections.addTitle') }}
      </h3>
      <p class="text-sm txt-secondary mb-3 px-1">{{ $t('config.connections.addSubtitle') }}</p>

      <ul class="space-y-3">
        <!-- Microsoft 365 -->
        <li class="surface-card p-4 flex flex-wrap items-start gap-3" data-testid="provider-m365">
          <Icon icon="simple-icons:microsoftoffice" class="w-6 h-6 text-[var(--brand)] mt-0.5" />
          <div class="flex-1 min-w-0">
            <p class="font-medium txt-primary">
              {{ $t('config.connections.providers.m365.name') }}
            </p>
            <p class="text-sm txt-secondary mt-0.5">
              {{ $t('config.connections.providers.m365.description') }}
            </p>
            <p class="text-xs txt-secondary mt-1 flex items-center gap-1">
              <Icon icon="heroicons:globe-alt" class="w-3.5 h-3.5 flex-shrink-0" />
              {{ $t('config.connections.providers.m365.hosting') }}
            </p>
            <p v-if="!m365Available" class="text-sm txt-secondary mt-2">
              {{ $t('config.connections.providers.m365.unavailable') }}
              <router-link
                v-if="isAdmin"
                :to="{ path: '/admin/config', query: { tab: 'channels', section: 'm365' } }"
                class="text-[var(--brand)] hover:underline"
              >
                {{ $t('config.connections.providers.m365.adminLink') }}
              </router-link>
              <span v-else>{{ $t('config.connections.providers.m365.askAdmin') }}</span>
            </p>
          </div>
          <button
            v-if="m365Available"
            type="button"
            class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
            :disabled="m365Connecting"
            data-testid="btn-connect-m365"
            @click="connectM365"
          >
            {{
              m365Connecting
                ? $t('config.connections.providers.m365.connecting')
                : $t('config.connections.providers.m365.connect')
            }}
          </button>
        </li>

        <!-- Mailbox (IMAP) -->
        <li class="surface-card p-4 flex flex-wrap items-start gap-3">
          <Icon icon="heroicons:envelope" class="w-6 h-6 text-[var(--brand)] mt-0.5" />
          <div class="flex-1 min-w-0">
            <p class="font-medium txt-primary">
              {{ $t('config.connections.providers.mailbox.name') }}
            </p>
            <p class="text-sm txt-secondary mt-0.5">
              {{ $t('config.connections.providers.mailbox.description') }}
            </p>
          </div>
          <router-link
            to="/channels/email"
            class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
          >
            {{ $t('config.connections.providers.mailbox.action') }}
          </router-link>
        </li>

        <!-- MCP server -->
        <li class="surface-card p-4 flex flex-wrap items-start gap-3">
          <Icon icon="heroicons:puzzle-piece" class="w-6 h-6 text-[var(--brand)] mt-0.5" />
          <div class="flex-1 min-w-0">
            <p class="font-medium txt-primary">
              {{ $t('config.connections.providers.mcp.name') }}
            </p>
            <p class="text-sm txt-secondary mt-0.5">
              {{ $t('config.connections.providers.mcp.description') }}
            </p>
          </div>
          <router-link
            to="/channels/mcp"
            class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
          >
            {{ $t('config.connections.providers.mcp.action') }}
          </router-link>
        </li>
      </ul>
    </section>
  </div>
</template>
