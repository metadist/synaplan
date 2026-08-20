<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { connectionsApi, type ConnectionItem } from '@/services/api/connectionsApi'
import { dropboxApi } from '@/services/api/dropboxApi'
import { m365Api } from '@/services/api/m365Api'
import { useAuthStore } from '@/stores/auth'
import ConnectionStatusPill from '@/components/config/ConnectionStatusPill.vue'
import DavConnectionForm from '@/components/config/DavConnectionForm.vue'
import RegistryConnectionRow from '@/components/config/RegistryConnectionRow.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { success, error: showError } = useNotification()

const items = ref<ConnectionItem[]>([])
const loading = ref(true)
const m365Available = ref(false)
const m365Connecting = ref(false)
const dropboxAvailable = ref(false)
const dropboxConnecting = ref(false)

const isAdmin = computed(() => auth.isAdmin)
const registryItems = computed(() => items.value.filter((row) => row.source === 'registry'))
const adapterItems = computed(() => items.value.filter((row) => row.source !== 'registry'))

/** Healthy connections per OAuth provider — drive the "already connected" state on the provider cards. */
const connectedAccounts = (type: string): string[] =>
  items.value
    .filter((row) => row.type === type && row.status === 'connected')
    .map((row) => row.name)

const m365Accounts = computed(() => connectedAccounts('m365'))
const m365Connected = computed(() => m365Accounts.value.length > 0)
const dropboxAccounts = computed(() => connectedAccounts('dropbox'))
const dropboxConnected = computed(() => dropboxAccounts.value.length > 0)

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

const loadDropbox = async () => {
  try {
    dropboxAvailable.value = (await dropboxApi.status()).available
  } catch {
    dropboxAvailable.value = false
  }
}

const formatChecked = (timestamp: number | null): string =>
  timestamp ? new Date(timestamp * 1000).toLocaleString(locale.value) : ''

const onAdapterOpen = async (item: ConnectionItem) => {
  if (item.manage_path) {
    await router.push(item.manage_path)
  }
}

const onRegistryChanged = (updated: ConnectionItem) => {
  items.value = items.value.map((row) => (row.id === updated.id ? updated : row))
}

const onRegistryRemoved = (id: string) => {
  items.value = items.value.filter((row) => row.id !== id)
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

const connectDropbox = async () => {
  dropboxConnecting.value = true
  try {
    window.location.href = await dropboxApi.authorizeUrl()
  } catch {
    showError(t('config.connections.providers.dropbox.startFailed'))
    dropboxConnecting.value = false
  }
}

const onReconnect = (item: ConnectionItem) => {
  if (item.type === 'dropbox') {
    void connectDropbox()
  } else {
    void connectM365()
  }
}

/** Read the outcome an OAuth callback redirected us back with, then clean the URL. */
const consumeConsentResult = async () => {
  for (const provider of ['m365', 'dropbox'] as const) {
    const result = route.query[provider]
    if (typeof result !== 'string') {
      continue
    }
    if ('connected' === result) {
      success(t(`config.connections.providers.${provider}.connected`))
    } else {
      const reason = typeof route.query.reason === 'string' ? route.query.reason : 'unknown'
      const known = ['access_denied', 'missing_code', 'exchange_failed', 'invalid_state']
      showError(
        t(
          known.includes(reason)
            ? `config.connections.providers.${provider}.errors.${reason}`
            : `config.connections.providers.${provider}.errors.unknown`
        )
      )
    }
    await router.replace({ query: {} })
    return
  }
}

onMounted(async () => {
  await Promise.all([load(), loadM365(), loadDropbox()])
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
        <RegistryConnectionRow
          v-for="item in registryItems"
          :key="item.id"
          :item="item"
          :locale="locale"
          @changed="onRegistryChanged"
          @removed="onRegistryRemoved"
          @reconnect="onReconnect(item)"
        />
        <li
          v-for="item in adapterItems"
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
          </div>
          <ConnectionStatusPill :status="item.status" />
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            data-testid="btn-test-connection"
            @click="onAdapterOpen(item)"
          >
            <Icon icon="heroicons:cog-6-tooth" class="w-4 h-4" />
            {{ $t('config.connections.manage') }}
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
        <li class="surface-card p-4 sm:p-5 space-y-3" data-testid="provider-m365">
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)]"
              >
                <Icon icon="simple-icons:microsoftoffice" class="w-5 h-5 text-[var(--brand)]" />
              </span>
              <p class="font-medium txt-primary">
                {{ $t('config.connections.providers.m365.name') }}
              </p>
            </div>
            <button
              v-if="m365Available"
              type="button"
              :class="[
                m365Connected ? 'btn-secondary' : 'btn-primary',
                'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap shrink-0',
              ]"
              :disabled="m365Connecting"
              data-testid="btn-connect-m365"
              @click="connectM365"
            >
              {{
                m365Connecting
                  ? $t('config.connections.providers.m365.connecting')
                  : m365Connected
                    ? $t('config.connections.providers.m365.connectAnother')
                    : $t('config.connections.providers.m365.connect')
              }}
            </button>
          </div>
          <div class="space-y-1.5 w-full">
            <p class="text-sm txt-secondary leading-relaxed">
              {{ $t('config.connections.providers.m365.description') }}
            </p>
            <p class="text-xs txt-secondary flex items-start gap-1">
              <Icon icon="heroicons:globe-alt" class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
              {{ $t('config.connections.providers.m365.hosting') }}
            </p>
            <p
              v-if="m365Connected"
              class="text-sm flex items-center gap-1.5 text-[var(--status-success)]"
              data-testid="m365-connected-hint"
            >
              <Icon icon="heroicons:check-circle" class="w-4 h-4 flex-shrink-0" />
              {{
                $t('config.connections.providers.m365.connectedAs', {
                  account: m365Accounts.join(', '),
                })
              }}
            </p>
            <p v-if="!m365Available" class="text-sm txt-secondary">
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
        </li>

        <!-- Dropbox -->
        <li class="surface-card p-4 sm:p-5 space-y-3" data-testid="provider-dropbox">
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)]"
              >
                <Icon icon="simple-icons:dropbox" class="w-5 h-5 text-[var(--brand)]" />
              </span>
              <p class="font-medium txt-primary">
                {{ $t('config.connections.providers.dropbox.name') }}
              </p>
            </div>
            <button
              v-if="dropboxAvailable"
              type="button"
              :class="[
                dropboxConnected ? 'btn-secondary' : 'btn-primary',
                'inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap shrink-0',
              ]"
              :disabled="dropboxConnecting"
              data-testid="btn-connect-dropbox"
              @click="connectDropbox"
            >
              {{
                dropboxConnecting
                  ? $t('config.connections.providers.dropbox.connecting')
                  : dropboxConnected
                    ? $t('config.connections.providers.dropbox.connectAnother')
                    : $t('config.connections.providers.dropbox.connect')
              }}
            </button>
          </div>
          <div class="space-y-1.5 w-full">
            <p class="text-sm txt-secondary leading-relaxed">
              {{ $t('config.connections.providers.dropbox.description') }}
            </p>
            <p class="text-xs txt-secondary flex items-start gap-1">
              <Icon icon="heroicons:globe-alt" class="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
              {{ $t('config.connections.providers.dropbox.hosting') }}
            </p>
            <p
              v-if="dropboxConnected"
              class="text-sm flex items-center gap-1.5 text-[var(--status-success)]"
              data-testid="dropbox-connected-hint"
            >
              <Icon icon="heroicons:check-circle" class="w-4 h-4 flex-shrink-0" />
              {{
                $t('config.connections.providers.dropbox.connectedAs', {
                  account: dropboxAccounts.join(', '),
                })
              }}
            </p>
            <p v-if="!dropboxAvailable" class="text-sm txt-secondary">
              {{ $t('config.connections.providers.dropbox.unavailable') }}
              <router-link
                v-if="isAdmin"
                :to="{ path: '/admin/config', query: { tab: 'channels', section: 'dropbox' } }"
                class="text-[var(--brand)] hover:underline"
              >
                {{ $t('config.connections.providers.dropbox.adminLink') }}
              </router-link>
              <span v-else>{{ $t('config.connections.providers.dropbox.askAdmin') }}</span>
            </p>
          </div>
        </li>

        <!-- Nextcloud / WebDAV folder and calendar -->
        <DavConnectionForm @created="load" />

        <!-- Mailbox (IMAP) -->
        <li class="surface-card p-4 sm:p-5 space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)]"
              >
                <Icon icon="heroicons:envelope" class="w-5 h-5 text-[var(--brand)]" />
              </span>
              <p class="font-medium txt-primary">
                {{ $t('config.connections.providers.mailbox.name') }}
              </p>
            </div>
            <router-link
              to="/channels/email"
              class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap shrink-0"
            >
              {{ $t('config.connections.providers.mailbox.action') }}
            </router-link>
          </div>
          <p class="text-sm txt-secondary leading-relaxed w-full">
            {{ $t('config.connections.providers.mailbox.description') }}
          </p>
        </li>

        <!-- MCP server -->
        <li class="surface-card p-4 sm:p-5 space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)]"
              >
                <Icon icon="heroicons:puzzle-piece" class="w-5 h-5 text-[var(--brand)]" />
              </span>
              <p class="font-medium txt-primary">
                {{ $t('config.connections.providers.mcp.name') }}
              </p>
            </div>
            <router-link
              to="/channels/mcp"
              class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap shrink-0"
            >
              {{ $t('config.connections.providers.mcp.action') }}
            </router-link>
          </div>
          <p class="text-sm txt-secondary leading-relaxed w-full">
            {{ $t('config.connections.providers.mcp.description') }}
          </p>
        </li>
      </ul>
    </section>
  </div>
</template>
