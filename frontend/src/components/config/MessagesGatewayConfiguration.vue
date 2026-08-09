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

      <!-- Admin: enable + upstream -->
      <div
        v-if="status.is_admin"
        class="surface-card p-6 space-y-5"
        data-testid="section-agents-admin"
      >
        <h3 class="text-lg font-semibold txt-primary">
          {{ $t('messagesGateway.adminTitle') }}
        </h3>

        <label class="flex items-center gap-3 text-sm txt-primary">
          <input
            v-model="enabledFlag"
            type="checkbox"
            class="rounded border-light-border"
            data-testid="checkbox-agents-enabled"
            @change="onToggleEnabled"
          />
          {{ $t('messagesGateway.enableLabel') }}
        </label>

        <label class="flex items-center gap-3 text-sm txt-primary">
          <input
            v-model="allowOperatorFlag"
            type="checkbox"
            class="rounded border-light-border"
            data-testid="checkbox-agents-operator-key"
            @change="onToggleOperatorKey"
          />
          {{ $t('messagesGateway.allowOperatorKeyLabel') }}
        </label>

        <div>
          <label class="block text-sm font-medium txt-primary mb-1">
            {{ $t('messagesGateway.webSearchLabel') }}
          </label>
          <select
            v-model="webSearchMode"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-agents-web-search"
            @change="onChangeWebSearchMode"
          >
            <option value="auto">{{ $t('messagesGateway.webSearchModeAuto') }}</option>
            <option value="synaplan" :disabled="!status.web_search_available">
              {{ $t('messagesGateway.webSearchModeSynaplan') }}
            </option>
            <option value="passthrough">
              {{ $t('messagesGateway.webSearchModePassthrough') }}
            </option>
            <option value="off">{{ $t('messagesGateway.webSearchModeOff') }}</option>
          </select>
          <p class="txt-secondary text-xs mt-1">
            {{
              status.web_search_available
                ? $t('messagesGateway.webSearchHint')
                : $t('messagesGateway.webSearchUnavailable')
            }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-1">
            {{ $t('messagesGateway.visionLabel') }}
          </label>
          <select
            v-model="visionMode"
            class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-agents-vision"
            @change="onChangeVisionMode"
          >
            <option value="auto">{{ $t('messagesGateway.visionModeAuto') }}</option>
            <option value="synaplan" :disabled="!status.vision_available">
              {{ $t('messagesGateway.visionModeSynaplan') }}
            </option>
            <option value="passthrough">
              {{ $t('messagesGateway.visionModePassthrough') }}
            </option>
            <option value="off">{{ $t('messagesGateway.visionModeOff') }}</option>
          </select>
          <p class="txt-secondary text-xs mt-1">
            {{
              status.vision_available
                ? $t('messagesGateway.visionHint')
                : $t('messagesGateway.visionUnavailable')
            }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-1">
            {{ $t('messagesGateway.upstreamLabel') }}
          </label>
          <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.upstreamWarning') }}</p>
          <div class="flex flex-wrap gap-2">
            <input
              v-model="upstreamUrl"
              type="url"
              class="flex-1 min-w-[16rem] px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono"
              data-testid="input-agents-upstream"
            />
            <button
              type="button"
              class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
              :disabled="savingUpstream"
              data-testid="btn-agents-save-upstream"
              @click="onSaveUpstream"
            >
              {{ $t('messagesGateway.saveUpstream') }}
            </button>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium txt-primary mb-1">
            {{ $t('messagesGateway.aliasesLabel') }}
          </label>
          <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.aliasesHint') }}</p>
          <textarea
            v-model="aliasesJson"
            rows="4"
            class="w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs font-mono"
            data-testid="input-agents-aliases"
          />
          <button
            type="button"
            class="btn-primary mt-2 px-4 py-2 rounded-lg text-sm font-medium"
            :disabled="savingAliases"
            data-testid="btn-agents-save-aliases"
            @click="onSaveAliases"
          >
            {{ $t('messagesGateway.saveAliases') }}
          </button>
        </div>
      </div>
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
  saveMessagesGatewayAliases,
  saveMessagesGatewayFlags,
  saveMessagesGatewayKey,
  saveMessagesGatewayUpstream,
  type MessagesGatewayStatus,
  type VisionMode,
  type WebSearchMode,
} from '@/services/api/messagesGatewayApi'

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(true)
const status = ref<MessagesGatewayStatus | null>(null)
const apiKey = ref('')
const savingKey = ref(false)
const clearingKey = ref(false)
const savingUpstream = ref(false)
const savingAliases = ref(false)
const enabledFlag = ref(false)
const allowOperatorFlag = ref(false)
const webSearchMode = ref<WebSearchMode>('auto')
const visionMode = ref<VisionMode>('auto')
const upstreamUrl = ref('')
const aliasesJson = ref('{}')

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

async function load() {
  loading.value = true
  try {
    status.value = await getMessagesGatewayStatus()
    enabledFlag.value = status.value.enabled
    allowOperatorFlag.value = status.value.allow_operator_key ?? false
    webSearchMode.value = resolveWebSearchMode(
      (status.value.web_search_mode as WebSearchMode | undefined) ?? 'auto',
      Boolean(status.value.web_search_available)
    )
    visionMode.value = resolveVisionMode(
      (status.value.vision_mode as VisionMode | undefined) ?? 'auto',
      Boolean(status.value.vision_available)
    )
    upstreamUrl.value = status.value.upstream_url
    aliasesJson.value = JSON.stringify(status.value.model_aliases ?? {}, null, 2)
    await persistUnavailableModeFallbacks()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.loadError'))
  } finally {
    loading.value = false
  }
}

/**
 * `synaplan` needs a configured provider. If the DB still has that mode after
 * the provider disappeared, show (and persist) `auto` so the control cannot
 * leave the instance in a state that silently does nothing useful.
 */
function resolveWebSearchMode(mode: WebSearchMode, available: boolean): WebSearchMode {
  return !available && mode === 'synaplan' ? 'auto' : mode
}

function resolveVisionMode(mode: VisionMode, available: boolean): VisionMode {
  return !available && mode === 'synaplan' ? 'auto' : mode
}

async function persistUnavailableModeFallbacks(): Promise<void> {
  if (!status.value) return

  const patch: Partial<{ web_search_mode: WebSearchMode; vision_mode: VisionMode }> = {}
  if (
    status.value.web_search_mode === 'synaplan' &&
    !status.value.web_search_available &&
    webSearchMode.value === 'auto'
  ) {
    patch.web_search_mode = 'auto'
  }
  if (
    status.value.vision_mode === 'synaplan' &&
    !status.value.vision_available &&
    visionMode.value === 'auto'
  ) {
    patch.vision_mode = 'auto'
  }
  if (Object.keys(patch).length === 0) return

  try {
    await saveMessagesGatewayFlags(patch)
    status.value = {
      ...status.value,
      ...patch,
    }
  } catch {
    // Leave the coerced UI value; the next successful save will catch up.
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

async function onToggleEnabled() {
  try {
    await saveMessagesGatewayFlags({ enabled: enabledFlag.value })
    success(t('messagesGateway.flagsSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    enabledFlag.value = status.value?.enabled ?? false
  }
}

async function onToggleOperatorKey() {
  try {
    await saveMessagesGatewayFlags({ allow_operator_key: allowOperatorFlag.value })
    success(t('messagesGateway.flagsSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    allowOperatorFlag.value = status.value?.allow_operator_key ?? false
  }
}

async function onChangeWebSearchMode() {
  try {
    await saveMessagesGatewayFlags({ web_search_mode: webSearchMode.value })
    success(t('messagesGateway.flagsSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    webSearchMode.value = resolveWebSearchMode(
      (status.value?.web_search_mode as WebSearchMode | undefined) ?? 'auto',
      Boolean(status.value?.web_search_available)
    )
  }
}

async function onChangeVisionMode() {
  try {
    await saveMessagesGatewayFlags({ vision_mode: visionMode.value })
    success(t('messagesGateway.flagsSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    visionMode.value = resolveVisionMode(
      (status.value?.vision_mode as VisionMode | undefined) ?? 'auto',
      Boolean(status.value?.vision_available)
    )
  }
}

async function onSaveUpstream() {
  savingUpstream.value = true
  try {
    await saveMessagesGatewayUpstream(upstreamUrl.value.trim())
    success(t('messagesGateway.upstreamSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.upstreamError'))
  } finally {
    savingUpstream.value = false
  }
}

async function onSaveAliases() {
  savingAliases.value = true
  try {
    const parsed = JSON.parse(aliasesJson.value) as Record<string, string>
    await saveMessagesGatewayAliases(parsed)
    success(t('messagesGateway.aliasesSaved'))
    await load()
  } catch (err) {
    error((err as Error).message || t('messagesGateway.aliasesError'))
  } finally {
    savingAliases.value = false
  }
}

onMounted(() => {
  load()
})
</script>
