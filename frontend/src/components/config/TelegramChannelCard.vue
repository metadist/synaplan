<template>
  <section v-if="visible" class="surface-card p-6" data-testid="section-telegram">
    <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
      <Icon icon="mdi:telegram" class="w-5 h-5 text-[var(--channel-telegram)]" />
      {{ t('channels.telegram.title') }}
    </h3>

    <TelegramPairingPanel
      v-if="status === 'pending_pairing'"
      :expired="pairingExpired"
      :link="state?.pairingLink"
      :valid-until="validUntil"
      :busy="busy"
      @renew="renewPairing"
      @cancel="stop('pairingCancelled', 'cancelFailed')"
    />

    <div v-else-if="status === 'connected'" class="space-y-4" data-testid="text-telegram-connected">
      <TelegramChannelFacts :bot-username="state?.botUsername" />
      <p v-if="lastMessage" class="text-sm txt-secondary">
        {{ t('channels.telegram.lastMessage', { time: lastMessage }) }}
      </p>
      <div class="flex flex-wrap gap-2">
        <button
          v-if="state?.chatId"
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-telegram-open-chat"
          @click="openChat"
        >
          {{ t('channels.telegram.openChat') }}
        </button>
        <button
          type="button"
          class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="btn-telegram-disconnect"
          :disabled="busy"
          @click="disconnect"
        >
          {{ t('channels.telegram.disconnect') }}
        </button>
      </div>
    </div>

    <template v-else>
      <div v-if="status === 'error'" class="space-y-4 mb-6" data-testid="text-telegram-state-error">
        <p class="text-sm text-red-600 dark:text-red-400">{{ sentence(state?.errorCode) }}</p>
        <p class="text-sm txt-secondary">{{ t('channels.telegram.errorHint') }}</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-if="state?.chatId"
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            data-testid="btn-telegram-open-chat"
            @click="openChat"
          >
            {{ t('channels.telegram.openChat') }}
          </button>
          <button
            type="button"
            class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            data-testid="btn-telegram-disconnect"
            :disabled="busy"
            @click="disconnect"
          >
            {{ t('channels.telegram.disconnect') }}
          </button>
        </div>
      </div>

      <div
        v-else-if="status === 'disconnected' && state?.chatId"
        class="flex flex-wrap items-center gap-3 mb-6"
        data-testid="text-telegram-disconnected"
      >
        <p class="text-sm txt-secondary">{{ t('channels.telegram.disconnectedHistory') }}</p>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-telegram-open-chat"
          @click="openChat"
        >
          {{ t('channels.telegram.openChat') }}
        </button>
      </div>

      <TelegramConnectForm v-model="token" :busy="busy" :error="localError" @submit="connect" />
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import TelegramChannelFacts from '@/components/config/TelegramChannelFacts.vue'
import TelegramConnectForm from '@/components/config/TelegramConnectForm.vue'
import TelegramPairingPanel from '@/components/config/TelegramPairingPanel.vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import {
  connectTelegram,
  disconnectTelegram,
  getTelegramChannel,
  renewTelegramPairing,
  type TelegramChannelState,
} from '@/services/api/telegramChannelApi'

const POLL_MS = 4000

const { t, te, locale } = useI18n()
const router = useRouter()
const { confirm } = useDialog()
const { success, error: notifyError } = useNotification()

const state = ref<TelegramChannelState | null>(null)
const token = ref('')
const localError = ref('')
const loaded = ref(false)
const absent = ref(false)
const busy = ref(false)
const now = ref(Date.now())
let pollTimer: ReturnType<typeof setInterval> | undefined
let inFlight = false

const status = computed(() => state.value?.status ?? 'none')
const visible = computed(() => loaded.value && !absent.value)

const pairingExpired = computed(() => {
  if (status.value !== 'pending_pairing') return false
  const expires = state.value?.pairingExpiresAt
  if (!state.value?.pairingLink) return true
  return typeof expires === 'number' && expires * 1000 <= now.value
})

const lastMessage = computed(() => formatTime(state.value?.lastMessageAt))
const validUntil = computed(() => formatTime(state.value?.pairingExpiresAt))

function formatTime(unix: number | null | undefined): string {
  if (typeof unix !== 'number') return ''
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(unix * 1000)
  )
}

function sentence(code: string | null | undefined): string {
  const key = `channels.telegram.errors.${code ?? ''}`
  return code && te(key) ? t(key) : t('channels.telegram.errors.generic')
}

function stopPolling(): void {
  if (pollTimer !== undefined) {
    clearInterval(pollTimer)
    pollTimer = undefined
  }
}

function syncPolling(): void {
  const waiting = status.value === 'pending_pairing' && !pairingExpired.value
  if (!waiting || document.hidden) {
    stopPolling()
    return
  }
  if (pollTimer !== undefined) return
  pollTimer = setInterval(() => {
    now.value = Date.now()
    if (pairingExpired.value) {
      stopPolling()
      return
    }
    void load()
  }, POLL_MS)
}

function apply(next: TelegramChannelState): void {
  state.value = next
  loaded.value = true
  now.value = Date.now()
  syncPolling()
}

async function load(): Promise<void> {
  if (inFlight || busy.value) return
  inFlight = true
  try {
    const previous = state.value?.status
    const next = await getTelegramChannel()
    apply(next)
    if (previous === 'pending_pairing' && next.status === 'connected') {
      success(t('channels.telegram.connectedToast'))
    }
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      stopPolling()
      absent.value = true
      loaded.value = false
      return
    }
    if (!loaded.value) {
      localError.value = t('channels.telegram.errors.generic')
      loaded.value = true
    }
  } finally {
    inFlight = false
  }
}

async function connect(): Promise<void> {
  localError.value = ''
  busy.value = true
  try {
    apply(await connectTelegram(token.value.trim()))
    token.value = ''
  } catch (err) {
    const code = err instanceof ApiError ? err.code : undefined
    localError.value = sentence(code)
  } finally {
    busy.value = false
  }
}

async function renewPairing(): Promise<void> {
  busy.value = true
  try {
    apply(await renewTelegramPairing())
  } catch {
    notifyError(t('channels.telegram.renewFailed'))
  } finally {
    busy.value = false
  }
}

async function stop(doneKey: string, failedKey: string): Promise<void> {
  busy.value = true
  try {
    apply(await disconnectTelegram())
    success(t(`channels.telegram.${doneKey}`))
  } catch {
    notifyError(t(`channels.telegram.${failedKey}`))
  } finally {
    busy.value = false
  }
}

async function disconnect(): Promise<void> {
  const accepted = await confirm({
    title: t('channels.telegram.confirmDisconnectTitle'),
    message: t('channels.telegram.confirmDisconnect'),
    danger: true,
  })
  if (accepted) await stop('disconnected', 'disconnectFailed')
}

function openChat(): void {
  const chatId = state.value?.chatId
  if (typeof chatId !== 'number') return
  void router.push({ path: '/', query: { chat: String(chatId) } })
}

function onVisible(): void {
  if (document.hidden) {
    stopPolling()
    return
  }
  if (status.value === 'pending_pairing') void load()
}

onMounted(() => {
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('focus', onVisible)
  void load()
})

onUnmounted(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', onVisible)
  window.removeEventListener('focus', onVisible)
})
</script>
