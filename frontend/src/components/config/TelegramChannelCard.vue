<template>
  <section v-if="visible" class="surface-card p-6" data-testid="section-telegram">
    <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
      <Icon icon="mdi:telegram" class="w-5 h-5 text-[var(--channel-telegram)]" />
      {{ t('channels.telegram.title') }}
    </h3>

    <div v-if="status === 'pending_pairing'" class="space-y-4" data-testid="text-telegram-pairing">
      <p class="text-sm txt-secondary">{{ t('channels.telegram.pairingHint') }}</p>
      <a
        v-if="state?.pairingLink"
        :href="state.pairingLink"
        target="_blank"
        rel="noopener noreferrer"
        class="btn-primary inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="link-telegram-open"
      >
        {{ t('channels.telegram.openTelegram') }}
      </a>
    </div>

    <div v-else-if="status === 'connected'" class="space-y-4" data-testid="text-telegram-connected">
      <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
        <dt class="txt-secondary">{{ t('channels.telegram.ownerLabel') }}</dt>
        <dd class="txt-primary">{{ t('channels.telegram.connectedOwner') }}</dd>
        <dt class="txt-secondary">{{ t('channels.telegram.whoElseLabel') }}</dt>
        <dd class="txt-primary">{{ t('channels.telegram.connectedWhoElse') }}</dd>
        <dt class="txt-secondary">{{ t('channels.telegram.touchesLabel') }}</dt>
        <dd class="txt-primary">{{ t('channels.telegram.connectedTouches') }}</dd>
        <dt class="txt-secondary">{{ t('channels.telegram.stopLabel') }}</dt>
        <dd class="txt-primary">{{ t('channels.telegram.connectedStop') }}</dd>
        <dt class="txt-secondary">{{ t('channels.telegram.fromLabel') }}</dt>
        <dd class="txt-primary">
          {{ t('channels.telegram.connectedFrom', { name: state?.botUsername }) }}
        </dd>
      </dl>
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
          class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
          data-testid="btn-telegram-disconnect"
          :disabled="busy"
          @click="disconnect"
        >
          {{ t('channels.telegram.disconnect') }}
        </button>
      </div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="connect">
      <p class="text-sm txt-secondary">{{ t('channels.telegram.empty') }}</p>
      <a
        href="https://t.me/BotFather"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex text-sm font-medium text-[var(--channel-telegram)]"
      >
        {{ t('channels.telegram.botFather') }}
      </a>
      <div>
        <label for="telegram-bot-token" class="block text-sm font-medium txt-primary">
          {{ t('channels.telegram.tokenLabel') }}
        </label>
        <input
          id="telegram-bot-token"
          v-model="token"
          type="password"
          autocomplete="off"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
          :placeholder="t('channels.telegram.tokenPlaceholder')"
          :disabled="busy"
          data-testid="input-telegram-token"
        />
      </div>
      <p
        v-if="formError"
        class="text-sm text-red-600 dark:text-red-400"
        data-testid="text-telegram-error"
      >
        {{ formError }}
      </p>
      <button
        type="submit"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-telegram-connect"
        :disabled="busy || token.trim() === ''"
      >
        {{ busy ? t('channels.telegram.connecting') : t('channels.telegram.connect') }}
      </button>
    </form>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import {
  connectTelegram,
  disconnectTelegram,
  getTelegramChannel,
  type TelegramChannelState,
} from '@/services/api/telegramChannelApi'

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
let pollTimer: ReturnType<typeof setInterval> | undefined
let inFlight = false

const status = computed(() => state.value?.status ?? 'none')
const visible = computed(() => loaded.value && !absent.value)

const formError = computed(() => {
  if (localError.value) return localError.value
  if (status.value === 'error' && state.value?.errorCode) return sentence(state.value.errorCode)
  return ''
})

const lastMessage = computed(() => {
  const unix = state.value?.lastMessageAt
  if (typeof unix !== 'number') return ''
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(unix * 1000)
  )
})

function sentence(code: string): string {
  const key = `channels.telegram.errors.${code}`
  return te(key) ? t(key) : t('channels.telegram.errors.generic')
}

function stopPolling(): void {
  if (pollTimer !== undefined) {
    clearInterval(pollTimer)
    pollTimer = undefined
  }
}

function ensurePolling(): void {
  if (pollTimer !== undefined) return
  pollTimer = setInterval(() => {
    void load()
  }, 4000)
}

async function load(): Promise<void> {
  if (inFlight || busy.value) return
  inFlight = true
  try {
    const previous = state.value?.status
    const next = await getTelegramChannel()
    state.value = next
    loaded.value = true
    if (previous === 'pending_pairing' && next.status === 'connected') {
      success(t('channels.telegram.connectedToast'))
    }
    if (next.status === 'pending_pairing') ensurePolling()
    else stopPolling()
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
    state.value = await connectTelegram(token.value.trim())
    token.value = ''
    loaded.value = true
    if (state.value.status === 'pending_pairing') ensurePolling()
  } catch (err) {
    const code = err instanceof ApiError ? err.code : undefined
    localError.value = code ? sentence(code) : t('channels.telegram.errors.generic')
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
  if (!accepted) return
  busy.value = true
  try {
    state.value = await disconnectTelegram()
    stopPolling()
    success(t('channels.telegram.disconnected'))
  } catch {
    notifyError(t('channels.telegram.disconnectFailed'))
  } finally {
    busy.value = false
  }
}

function openChat(): void {
  const chatId = state.value?.chatId
  if (typeof chatId !== 'number') return
  void router.push({ path: '/', query: { chat: String(chatId) } })
}

function onFocus(): void {
  if (status.value === 'pending_pairing') void load()
}

onMounted(() => {
  window.addEventListener('focus', onFocus)
  void load()
})

onUnmounted(() => {
  stopPolling()
  window.removeEventListener('focus', onFocus)
})
</script>
