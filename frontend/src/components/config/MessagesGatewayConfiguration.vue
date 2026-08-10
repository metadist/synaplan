<template>
  <div class="space-y-6" data-testid="page-config-messages-gateway">
    <div class="surface-card p-6" data-testid="section-agents-overview">
      <div class="flex items-start gap-3">
        <div class="p-2 rounded-lg bg-[var(--brand)]/10">
          <Icon icon="heroicons:command-line" class="w-6 h-6 text-[var(--brand)]" />
        </div>
        <div class="flex-1 min-w-0">
          <h2 class="text-2xl font-semibold txt-primary mb-1">
            {{ $t('messagesGateway.title') }}
          </h2>
          <p class="txt-secondary text-sm leading-relaxed">
            {{ $t('messagesGateway.description') }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12" data-testid="section-agents-loading">
      <div
        class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
      ></div>
      <p class="mt-2 txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>

    <template v-else-if="status">
      <!-- Status -->
      <div class="surface-card p-6" data-testid="section-agents-status">
        <h3 class="text-lg font-semibold txt-primary mb-3">
          {{ $t('messagesGateway.statusTitle') }}
        </h3>
        <div class="flex flex-wrap items-center gap-3 text-sm">
          <span
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full"
            :class="
              status.enabled
                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
            "
            data-testid="badge-gateway-enabled"
          >
            <Icon
              :icon="status.enabled ? 'heroicons:check-circle' : 'heroicons:pause-circle'"
              class="w-4 h-4"
            />
            {{
              status.enabled
                ? $t('messagesGateway.statusEnabled')
                : $t('messagesGateway.statusDisabled')
            }}
          </span>
          <span class="txt-secondary font-mono text-xs" data-testid="text-upstream-url">
            {{ status.upstream_url }}
          </span>
        </div>
        <p class="txt-secondary text-sm mt-3">
          {{
            budgetUnlimited
              ? $t('messagesGateway.budgetLineUnlimited', {
                  used: status.budget.used_cost ?? '0',
                })
              : $t('messagesGateway.budgetLine', {
                  percent: status.budget.percent ?? 0,
                  used: status.budget.used_cost ?? '0',
                  budget: status.budget.budget ?? '0',
                })
          }}
        </p>
      </div>

      <!-- Setup snippet -->
      <div class="surface-card p-6" data-testid="section-agents-setup">
        <h3 class="text-lg font-semibold txt-primary mb-2">
          {{ $t('messagesGateway.setupTitle') }}
        </h3>
        <p class="txt-secondary text-sm mb-4">{{ $t('messagesGateway.setupHint') }}</p>
        <pre
          class="p-4 rounded-lg surface-chip txt-primary text-xs font-mono overflow-x-auto whitespace-pre-wrap"
          data-testid="text-setup-snippet"
          >{{ setupSnippet }}</pre>
        <button
          type="button"
          class="btn-primary mt-3 px-4 py-2 rounded-lg text-sm font-medium"
          data-testid="btn-copy-setup"
          @click="copySetup"
        >
          {{ $t('messagesGateway.copySetup') }}
        </button>
      </div>

      <!-- BYO Anthropic key -->
      <div class="surface-card p-6" data-testid="section-agents-key">
        <h3 class="text-lg font-semibold txt-primary mb-1">
          {{ $t('messagesGateway.keyTitle') }}
        </h3>
        <p class="txt-secondary text-sm mb-4">{{ $t('messagesGateway.keyHint') }}</p>

        <div class="flex flex-wrap items-center gap-3 text-sm mb-4">
          <span class="txt-secondary">
            {{ $t('messagesGateway.keySource') }}:
            <strong class="txt-primary">{{ anthropicSourceLabel }}</strong>
          </span>
          <code v-if="status.keys.anthropic?.has_user_key" class="font-mono text-xs txt-secondary">
            {{ status.keys.anthropic.user_key_masked }}
          </code>
        </div>

        <label class="block mb-4">
          <span class="text-sm font-medium txt-primary">{{
            $t('messagesGateway.apiKeyLabel')
          }}</span>
          <input
            v-model="apiKey"
            type="password"
            autocomplete="off"
            spellcheck="false"
            class="mt-1 w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
            data-testid="input-agents-api-key"
          />
        </label>

        <div class="flex flex-wrap gap-3">
          <button
            type="button"
            class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
            :disabled="!apiKey.trim() || savingKey"
            data-testid="btn-agents-save-key"
            @click="onSaveKey"
          >
            {{ $t('messagesGateway.saveKey') }}
          </button>
          <button
            v-if="status.keys.anthropic?.has_user_key"
            type="button"
            class="px-4 py-2 rounded-lg text-sm font-medium border border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-500/10"
            :disabled="clearingKey"
            data-testid="btn-agents-clear-key"
            @click="onClearKey"
          >
            {{ $t('messagesGateway.clearKey') }}
          </button>
        </div>
      </div>

      <MessagesGatewayAdminSettings v-if="status.is_admin" :status="status" @saved="load(true)" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  clearMessagesGatewayKey,
  getMessagesGatewayStatus,
  saveMessagesGatewayKey,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'
import MessagesGatewayAdminSettings from './messagesGateway/MessagesGatewayAdminSettings.vue'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const status = ref<MessagesGatewayStatus | null>(null)
const apiKey = ref('')
const savingKey = ref(false)
const clearingKey = ref(false)

// A budget of 0 means "no monthly budget configured" (unlimited), not "exhausted".
const budgetUnlimited = computed(() => Number(status.value?.budget?.budget ?? 0) <= 0)

const anthropicSourceLabel = computed(() => {
  const source = status.value?.keys?.anthropic?.effective_source ?? 'none'
  return t(`messagesGateway.source.${source}`)
})

const setupSnippet = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'https://web.synaplan.com'
  return [
    `export ANTHROPIC_BASE_URL="${origin}"`,
    'export ANTHROPIC_API_KEY="sk_your_synaplan_api_key"',
    '# or: export ANTHROPIC_AUTH_TOKEN="sk_your_synaplan_api_key"',
    '# Set exactly one credential variable.',
    'claude',
  ].join('\n')
})

/**
 * `silent` keeps the rendered page in place while a settings change is written
 * back — swapping the whole panel for a spinner after every toggle would make
 * the settings feel like they reload rather than save.
 */
async function load(silent = false) {
  if (!silent) {
    loading.value = true
  }
  try {
    status.value = await getMessagesGatewayStatus()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.loadError'))
  } finally {
    loading.value = false
  }
}

async function copySetup() {
  try {
    await navigator.clipboard.writeText(setupSnippet.value)
    success(t('messagesGateway.copySuccess'))
  } catch {
    error(t('messagesGateway.copyError'))
  }
}

async function onSaveKey() {
  if (!apiKey.value.trim() || savingKey.value) return
  savingKey.value = true
  try {
    await saveMessagesGatewayKey('anthropic', apiKey.value.trim())
    apiKey.value = ''
    success(t('messagesGateway.saveKeySuccess'))
    await load(true)
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
    await load(true)
  } catch (err) {
    error((err as Error).message || t('messagesGateway.clearKeyError'))
  } finally {
    clearingKey.value = false
  }
}

onMounted(() => {
  load()
})
</script>
