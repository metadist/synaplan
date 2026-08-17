<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { connectionsApi, type ConnectionItem } from '@/services/api/connectionsApi'
import ConnectionStatusPill from '@/components/config/ConnectionStatusPill.vue'

const props = defineProps<{
  item: ConnectionItem
  locale: string
}>()

const emit = defineEmits<{
  changed: [item: ConnectionItem]
  removed: [id: string]
  reconnect: []
}>()

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const testing = ref(false)
const editing = ref(false)
const saving = ref(false)
const name = ref('')
const username = ref('')
const appPassword = ref('')
const folder = ref('Synaplan')
const calendar = ref('personal')
const serverUrl = ref('')

const isDav = computed(() => props.item.type === 'webdav' || props.item.type === 'caldav')

const promptName = computed((): string => {
  if (typeof props.item.channel === 'string' && props.item.channel.trim() !== '') {
    return props.item.channel
  }
  const fromConfig = props.item.config?.channel
  return typeof fromConfig === 'string' ? fromConfig : ''
})

const formatChecked = (timestamp: number | null): string =>
  timestamp ? new Date(timestamp * 1000).toLocaleString(props.locale) : ''

const davMarker = (type: string): string =>
  type === 'caldav' ? '/remote.php/dav/calendars/' : '/remote.php/dav/files/'

const parseDavConfig = () => {
  const config = props.item.config ?? {}
  username.value = typeof config.username === 'string' ? config.username : ''
  folder.value = typeof config.folder === 'string' ? config.folder : 'Synaplan'
  const base = typeof config.base_url === 'string' ? config.base_url.replace(/\/+$/, '') : ''
  const marker = davMarker(props.item.type)
  const at = base.indexOf(marker)
  if (at >= 0) {
    serverUrl.value = base.slice(0, at)
    const rest = base.slice(at + marker.length)
    const parts = rest.split('/').filter(Boolean).map(decodeURIComponent)
    if (parts[0]) username.value = parts[0]
    if (props.item.type === 'caldav' && parts[1]) calendar.value = parts[1]
  } else {
    serverUrl.value = base
  }
}

const openEdit = () => {
  name.value = props.item.name
  appPassword.value = ''
  parseDavConfig()
  editing.value = true
}

const davBaseUrl = (): string => {
  const server = serverUrl.value.trim().replace(/\/+$/, '')
  const user = encodeURIComponent(username.value.trim())
  if (props.item.type === 'caldav') {
    return `${server}/remote.php/dav/calendars/${user}/${encodeURIComponent(calendar.value.trim())}`
  }
  if (server.includes('/remote.php/dav/')) {
    return server
  }
  return `${server}/remote.php/dav/files/${user}`
}

const onTest = async () => {
  testing.value = true
  try {
    const result = await connectionsApi.test(props.item.id)
    emit('changed', result.connection)
    if (false === result.succeeded) {
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
    testing.value = false
  }
}

const onSave = async () => {
  if (!name.value.trim() || saving.value) return
  saving.value = true
  try {
    const payload: { name: string; secret?: string; config?: Record<string, unknown> } = {
      name: name.value.trim(),
    }
    if (appPassword.value) payload.secret = appPassword.value
    if (isDav.value) {
      payload.config = {
        ...(props.item.config ?? {}),
        base_url: davBaseUrl(),
        username: username.value.trim(),
      }
      if (props.item.type === 'webdav') {
        payload.config.folder = folder.value.trim() || 'Synaplan'
      }
    }
    const updated = await connectionsApi.update(props.item.id, payload)
    emit('changed', updated)
    editing.value = false
    success(t('config.connections.updated'))
    await onTest()
  } catch {
    showError(t('config.connections.updateFailed'))
  } finally {
    saving.value = false
  }
}

const onDelete = async () => {
  const confirmed = await dialog.confirm({
    title: t('config.connections.deleteTitle'),
    message: t('config.connections.deleteMessage', { name: props.item.name }),
    confirmText: t('config.connections.deleteConfirm'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await connectionsApi.remove(props.item.id)
    emit('removed', props.item.id)
    success(t('config.connections.deleted', { name: props.item.name }))
  } catch {
    showError(t('config.connections.deleteFailed'))
  }
}
</script>

<template>
  <li class="surface-card p-4 space-y-3" data-testid="connection-row">
    <div class="flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-0">
        <p class="font-medium txt-primary truncate">{{ item.name }}</p>
        <p class="text-xs txt-secondary">
          {{ $t(`config.connections.types.${item.type}`, item.type) }}
          <span v-if="item.last_checked">
            ·
            {{ $t('config.connections.lastChecked', { when: formatChecked(item.last_checked) }) }}
          </span>
        </p>
        <p v-if="promptName" class="text-xs txt-secondary mt-1" data-testid="connection-channel">
          {{ $t('config.connections.channelHint', { channel: promptName }) }}
          <span class="pill ml-1">{{ promptName }}</span>
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
        class="btn-primary inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
        @click="emit('reconnect')"
      >
        <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
        {{ $t('config.connections.reconnect') }}
      </button>
      <button
        type="button"
        class="btn-secondary inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
        :disabled="testing"
        data-testid="btn-test-connection"
        @click="onTest"
      >
        <Icon
          :icon="testing ? 'heroicons:arrow-path' : 'heroicons:signal'"
          :class="['w-4 h-4', testing && 'animate-spin']"
        />
        {{ testing ? $t('config.connections.testing') : $t('config.connections.test') }}
      </button>
      <button
        type="button"
        class="btn-secondary inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
        data-testid="btn-edit-connection"
        @click="openEdit"
      >
        <Icon icon="heroicons:pencil-square" class="w-4 h-4" />
        {{ $t('common.edit') }}
      </button>
      <button
        type="button"
        class="icon-ghost icon-ghost--danger inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
        data-testid="btn-delete-connection"
        @click="onDelete"
      >
        <Icon icon="heroicons:trash" class="w-4 h-4" />
        {{ $t('common.delete') }}
      </button>
    </div>

    <form v-if="editing" class="space-y-3 pt-1" data-testid="connection-edit-form" @submit.prevent="onSave">
      <label class="block text-sm">
        <span class="txt-secondary">{{ $t('config.connections.editName') }}</span>
        <input
          v-model="name"
          type="text"
          required
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="connection-edit-name"
        />
      </label>
      <template v-if="isDav">
        <label class="block text-sm">
          <span class="txt-secondary">{{ $t('config.connections.providers.dav.serverUrl') }}</span>
          <input
            v-model="serverUrl"
            type="url"
            required
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="connection-edit-server"
          />
        </label>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="block text-sm">
            <span class="txt-secondary">{{ $t('config.connections.providers.dav.username') }}</span>
            <input
              v-model="username"
              type="text"
              required
              autocomplete="off"
              class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="connection-edit-username"
            />
          </label>
          <label class="block text-sm">
            <span class="txt-secondary">{{
              $t('config.connections.providers.dav.appPassword')
            }}</span>
            <input
              v-model="appPassword"
              type="password"
              autocomplete="new-password"
              class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="connection-edit-password"
            />
          </label>
        </div>
        <p class="text-xs txt-secondary">{{ $t('config.connections.keepPassword') }}</p>
        <label v-if="item.type === 'webdav'" class="block text-sm">
          <span class="txt-secondary">{{ $t('config.connections.providers.dav.folder') }}</span>
          <input
            v-model="folder"
            type="text"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="connection-edit-folder"
          />
        </label>
        <label v-else class="block text-sm">
          <span class="txt-secondary">{{ $t('config.connections.providers.dav.withCalendar') }}</span>
          <input
            v-model="calendar"
            type="text"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="connection-edit-calendar"
          />
        </label>
      </template>
      <div class="flex gap-2">
        <button
          type="submit"
          class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium"
          :disabled="saving || !name.trim()"
          data-testid="btn-save-connection"
        >
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
        <button
          type="button"
          class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium"
          @click="editing = false"
        >
          {{ $t('common.cancel') }}
        </button>
      </div>
    </form>
  </li>
</template>
