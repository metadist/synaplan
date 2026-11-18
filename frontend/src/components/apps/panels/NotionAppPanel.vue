<template>
  <div data-testid="section-notion">
    <div v-if="loading" class="txt-secondary text-sm">{{ $t('apps.notion.loading') }}</div>

    <div
      v-else-if="loadError"
      class="surface-card rounded-2xl p-4 sm:p-5"
      data-testid="section-notion-error"
    >
      <p class="txt-primary text-sm">{{ $t('apps.notion.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary mt-4 px-4 py-2.5 text-sm font-medium"
        data-testid="btn-notion-retry"
        @click="load"
      >
        {{ $t('apps.notion.retry') }}
      </button>
    </div>

    <div
      v-else-if="status?.status === 'reauth_required'"
      class="surface-card rounded-2xl p-4 sm:p-5"
      data-testid="section-notion-reauth"
    >
      <p class="txt-primary text-sm">{{ $t('apps.notion.reconnectTitle') }}</p>
      <div class="mt-4 flex flex-wrap gap-3">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="connecting"
          data-testid="btn-notion-reconnect"
          @click="connect"
        >
          {{ $t('apps.notion.reconnect') }}
        </button>
        <button
          type="button"
          class="btn-danger rounded-lg px-4 py-2.5 text-sm font-medium"
          data-testid="btn-notion-disconnect"
          @click="disconnect"
        >
          {{ $t('apps.notion.disconnect') }}
        </button>
      </div>
    </div>

    <div
      v-else-if="status?.status !== 'connected'"
      class="surface-card rounded-2xl p-4 sm:p-5"
      data-testid="section-notion-connect"
    >
      <template v-if="status?.sharing.shared_by">
        <p class="txt-primary text-sm" data-testid="text-notion-shared">
          {{ $t('apps.notion.sharing.memberNote', { owner: status.sharing.shared_by }) }}
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
          <button
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="usingShared"
            data-testid="btn-notion-use-shared"
            @click="useShared"
          >
            {{ $t('apps.notion.sharing.use') }}
          </button>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="connecting"
            data-testid="btn-notion-connect"
            @click="connect"
          >
            {{ $t('apps.notion.sharing.connectOwn') }}
          </button>
        </div>
        <NotionSharedPermissions
          v-if="status.sharing.shared_server_id != null"
          :server-id="status.sharing.shared_server_id"
        />
      </template>
      <template v-else>
        <p class="txt-primary text-sm">{{ $t('apps.notion.notConnected') }}</p>
        <button
          type="button"
          class="btn-primary mt-4 px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="connecting"
          data-testid="btn-notion-connect"
          @click="connect"
        >
          {{ $t('apps.notion.connect') }}
        </button>
      </template>
    </div>

    <div v-else class="space-y-4" data-testid="section-notion-connected">
      <TabNav
        :model-value="tab"
        :tabs="tabs"
        :aria-label="$t('mcpServers.permissions.tabsLabel')"
        testid="tabs-notion"
        mobile-trigger-testid="tabs-notion-mobile-trigger"
        @update:model-value="selectTab"
      />
      <div
        v-if="tab === 'permissions' && status?.server_id != null"
        class="surface-card rounded-2xl p-4 sm:p-5"
        data-testid="section-notion-permissions"
      >
        <McpToolPermissions
          :server-id="status.server_id"
          server-name="Notion"
          @notice-dismissed="notice = false"
        />
      </div>
      <template v-else>
        <McpPermissionsNotice
          v-if="notice"
          :show-review="true"
          :busy="noticeBusy"
          @review="tab = 'permissions'"
          @dismiss="dismissNotice"
        />
        <div class="surface-card rounded-2xl p-4 sm:p-5 space-y-5">
          <NotionConnectedSection v-model:status="status" :account="account" @try="tryPrompt" />
          <div class="flex flex-wrap gap-3">
            <button
              type="button"
              class="btn-primary px-4 py-2.5 text-sm font-medium"
              data-testid="btn-notion-try-chat"
              @click="tryPrompt('')"
            >
              {{ $t('apps.notion.tryChat') }}
            </button>
            <button
              type="button"
              class="btn-danger rounded-lg px-4 py-2.5 text-sm font-medium"
              data-testid="btn-notion-disconnect"
              @click="disconnect"
            >
              {{ $t('apps.notion.disconnect') }}
            </button>
          </div>
        </div>
        <div class="surface-card rounded-2xl p-4 sm:p-5">
          <NotionWatches />
        </div>
        <div class="surface-card rounded-2xl p-4 sm:p-5">
          <NotionRecentChanges />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { mcpServersApi } from '@/services/api/mcpServersApi'
import { userFacingError } from '@/utils/userFacingError'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import McpPermissionsNotice from '@/components/config/mcp/McpPermissionsNotice.vue'
import NotionSharedPermissions from '@/components/apps/panels/NotionSharedPermissions.vue'
import McpToolPermissions from '@/components/config/mcp/McpToolPermissions.vue'
import NotionConnectedSection from './notion/NotionConnectedSection.vue'
import NotionRecentChanges from './notion/NotionRecentChanges.vue'
import NotionWatches from './notion/NotionWatches.vue'
import {
  notionAppApi,
  type NotionAppAccount,
  type NotionAppStatus,
} from '@/services/api/notionAppApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const loadError = ref(false)
const connecting = ref(false)
const usingShared = ref(false)
type NotionTab = 'overview' | 'permissions'
const tab = ref<NotionTab>(route.query.tab === 'permissions' ? 'permissions' : 'overview')
/** Keeps the tab in the URL so a reload or a shared link opens the same tab. */
const selectTab = (next: string) => {
  tab.value = next === 'permissions' ? 'permissions' : 'overview'
  const query = { ...route.query }
  if (tab.value === 'permissions') query.tab = 'permissions'
  else delete query.tab
  void router.replace({ query })
}
const tabs = computed<TabNavItem[]>(() => [
  { id: 'overview', label: t('mcpServers.permissions.overviewTab'), testid: 'tab-notion-overview' },
  {
    id: 'permissions',
    label: t('mcpServers.permissions.permissionsTab'),
    testid: 'tab-notion-permissions',
    badge: notice.value ? 1 : undefined,
  },
])
const notice = ref(false)
const noticeBusy = ref(false)
const status = ref<NotionAppStatus | null>(null)
const account = ref<NotionAppAccount | null>(null)

const loadAccount = async () => {
  try {
    account.value = await notionAppApi.account()
  } catch {
    account.value = null
  }
}

const loadNotice = async (id: number) => {
  try {
    notice.value = (await mcpServersApi.toolPermissions(id)).notice
  } catch {
    notice.value = false
  }
}

const dismissNotice = async () => {
  const id = status.value?.server_id
  if (id == null) return
  noticeBusy.value = true
  try {
    notice.value = (await mcpServersApi.dismissToolNotice(id)).notice
  } catch (err) {
    error(userFacingError(err, t('mcpServers.permissions.saveFailed')))
  } finally {
    noticeBusy.value = false
  }
}

const load = async () => {
  loading.value = true
  loadError.value = false
  account.value = null
  try {
    status.value = await notionAppApi.status()
    if (status.value.status === 'connected') {
      void loadAccount()
      if (status.value.server_id != null) void loadNotice(status.value.server_id)
    }
  } catch {
    loadError.value = true
    status.value = null
  } finally {
    loading.value = false
  }
}

const connect = async () => {
  connecting.value = true
  try {
    window.location.assign(await notionAppApi.connect())
  } catch (err) {
    error(userFacingError(err, t('apps.notion.connectFailed')))
    connecting.value = false
  }
}

const useShared = async () => {
  usingShared.value = true
  try {
    await notionAppApi.useShared()
    success(t('apps.notion.sharing.usingToast'))
    tryPrompt('')
  } catch (err) {
    error(userFacingError(err, t('apps.notion.sharing.useFailed')))
  } finally {
    usingShared.value = false
  }
}

const disconnect = async () => {
  const id = status.value?.server_id
  if (id == null) return
  const ok = await confirm({
    title: t('apps.notion.disconnectTitle'),
    message: status.value?.sharing.shared_on
      ? t('apps.notion.disconnectMessageShared')
      : t('apps.notion.disconnectMessage'),
    confirmText: t('apps.notion.disconnect'),
    danger: true,
  })
  if (!ok) return
  try {
    await mcpServersApi.disconnectOAuth(id)
    success(t('apps.notion.disconnected'))
    await load()
  } catch (err) {
    error(userFacingError(err, t('apps.notion.disconnectFailed')))
  }
}

const tryPrompt = (text: string) => {
  void router.push(text === '' ? { name: 'chat' } : { name: 'chat', query: { prefill: text } })
}

const consumeCallback = async () => {
  const connected = route.query.connected
  const oauthError = route.query.oauth_error
  if (typeof connected === 'string' && connected !== '') {
    success(t('apps.notion.connected'))
  } else if (typeof oauthError === 'string' && oauthError !== '') {
    error(t('apps.notion.connectFailed'))
  } else {
    return
  }
  const next = { ...route.query }
  delete next.connected
  delete next.oauth_error
  await router.replace({ query: next })
}

onMounted(() => {
  void load()
  void consumeCallback()
})
</script>
