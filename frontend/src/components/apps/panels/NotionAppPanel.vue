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
    </div>

    <div
      v-else
      class="surface-card rounded-2xl p-4 sm:p-5 space-y-5"
      data-testid="section-notion-connected"
    >
      <p class="txt-primary text-sm">{{ $t('apps.notion.connected') }}</p>

      <div>
        <label class="inline-flex items-center gap-3 cursor-pointer">
          <span class="text-sm font-medium txt-primary">{{ $t('apps.notion.writeLabel') }}</span>
          <span class="relative inline-flex">
            <input
              type="checkbox"
              class="sr-only peer"
              :checked="status.allow_write"
              :disabled="saving"
              data-testid="toggle-notion-write"
              @change="onWrite"
            />
            <span
              class="w-11 h-6 bg-gray-300 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand)] peer-disabled:opacity-50 transition-colors"
            ></span>
            <span
              class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"
            ></span>
          </span>
        </label>
        <p class="txt-secondary text-sm mt-2" data-testid="text-notion-write-consequence">
          {{ status.allow_write ? $t('apps.notion.writeOn') : $t('apps.notion.writeOff') }}
        </p>
      </div>

      <div>
        <p class="text-sm font-medium txt-primary">{{ $t('apps.notion.examplesTitle') }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-notion-example-read"
            @click="tryPrompt($t('apps.notion.exampleRead'))"
          >
            {{ $t('apps.notion.exampleRead') }}
          </button>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-notion-example-write"
            @click="tryPrompt($t('apps.notion.exampleWrite'))"
          >
            {{ $t('apps.notion.exampleWrite') }}
          </button>
        </div>
      </div>

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
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { mcpServersApi } from '@/services/api/mcpServersApi'
import { notionAppApi, type NotionAppStatus } from '@/services/api/notionAppApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const loadError = ref(false)
const saving = ref(false)
const connecting = ref(false)
const status = ref<NotionAppStatus | null>(null)

const load = async () => {
  loading.value = true
  loadError.value = false
  try {
    status.value = await notionAppApi.status()
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
    error(err instanceof Error && err.message ? err.message : t('apps.notion.connectFailed'))
    connecting.value = false
  }
}

const onWrite = async (event: Event) => {
  const input = event.target
  if (!(input instanceof HTMLInputElement) || !status.value) return
  const next = input.checked
  saving.value = true
  try {
    status.value = await notionAppApi.setWriting(next)
    success(next ? t('apps.notion.writeSavedOn') : t('apps.notion.writeSavedOff'))
  } catch (err) {
    input.checked = !next
    error(err instanceof Error && err.message ? err.message : t('apps.notion.writeFailed'))
  } finally {
    saving.value = false
  }
}

const disconnect = async () => {
  const id = status.value?.server_id
  if (id == null) return
  const ok = await confirm({
    title: t('apps.notion.disconnectTitle'),
    message: t('apps.notion.disconnectMessage'),
    confirmText: t('apps.notion.disconnect'),
    danger: true,
  })
  if (!ok) return
  try {
    await mcpServersApi.disconnectOAuth(id)
    success(t('apps.notion.disconnected'))
    await load()
  } catch (err) {
    error(err instanceof Error && err.message ? err.message : t('apps.notion.disconnectFailed'))
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
