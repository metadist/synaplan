<template>
  <div class="space-y-4" data-testid="section-agents-key">
    <div v-if="loading" class="text-center py-8">
      <div
        class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
      ></div>
      <p class="mt-2 txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>

    <template v-else-if="status">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="txt-secondary">
          {{ $t('messagesGateway.keySource') }}:
          <strong class="txt-primary">{{ anthropicSourceLabel }}</strong>
        </span>
        <code v-if="status.keys.anthropic?.has_user_key" class="font-mono text-xs txt-secondary">
          {{ status.keys.anthropic.user_key_masked }}
        </code>
      </div>

      <label class="block">
        <span class="text-sm font-medium txt-primary">{{ $t('messagesGateway.apiKeyLabel') }}</span>
        <input
          v-model="apiKey"
          type="password"
          autocomplete="off"
          spellcheck="false"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
          data-testid="input-agents-api-key"
        />
      </label>

      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="!apiKey.trim() || savingKey"
          data-testid="btn-agents-save-key"
          @click="onSaveKey"
        >
          {{ $t('messagesGateway.saveKey') }}
        </button>
        <button
          v-if="status.keys.anthropic?.has_user_key"
          type="button"
          class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="clearingKey"
          data-testid="btn-agents-clear-key"
          @click="onClearKey"
        >
          {{ $t('messagesGateway.clearKey') }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  clearMessagesGatewayKey,
  getMessagesGatewayStatus,
  saveMessagesGatewayKey,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const status = ref<MessagesGatewayStatus | null>(null)
const apiKey = ref('')
const savingKey = ref(false)
const clearingKey = ref(false)

const anthropicSourceLabel = computed(() => {
  const source = status.value?.keys?.anthropic?.effective_source ?? 'none'
  return t(`messagesGateway.source.${source}`)
})

async function load() {
  loading.value = true
  try {
    status.value = await getMessagesGatewayStatus()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.loadError'))
  } finally {
    loading.value = false
  }
}

async function onSaveKey() {
  if (!apiKey.value.trim() || savingKey.value) return
  savingKey.value = true
  try {
    await saveMessagesGatewayKey('anthropic', apiKey.value.trim())
    apiKey.value = ''
    success(t('messagesGateway.saveKeySuccess'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.saveKeyError'))
  } finally {
    savingKey.value = false
  }
}

async function onClearKey() {
  const confirmed = await confirm({
    title: t('messagesGateway.clearKeyConfirmTitle'),
    message: t('messagesGateway.clearKeyConfirm'),
    confirmText: t('messagesGateway.clearKey'),
    danger: true,
  })
  if (!confirmed) return
  clearingKey.value = true
  try {
    await clearMessagesGatewayKey('anthropic')
    success(t('messagesGateway.clearKeySuccess'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.clearKeyError'))
  } finally {
    clearingKey.value = false
  }
}

onMounted(() => {
  void load()
})
</script>
