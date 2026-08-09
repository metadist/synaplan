<script setup lang="ts">
/**
 * Instance-wide settings for the Anthropic-compatible gateway (admins only).
 *
 * Every control writes one setting and reports the outcome immediately —
 * there is no "Save all" button to forget. The upstream URL and the model
 * aliases are the exception: free text is only submitted on demand.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import {
  saveMessagesGatewayAliases,
  saveMessagesGatewayFlags,
  saveMessagesGatewayUpstream,
  type ImageDetail,
  type MessagesGatewaySettings,
  type MessagesGatewayStatus,
  type VisionMode,
  type WebSearchMode,
} from '@/services/api/messagesGatewayApi'
import GatewaySettingRow from './GatewaySettingRow.vue'
import GatewaySettingToggle from './GatewaySettingToggle.vue'

const MIN_TOOL_ROUNDS = 1
const MAX_TOOL_ROUNDS = 32
const MIN_MAX_IMAGES = 0
const MAX_MAX_IMAGES = 100

const props = defineProps<{
  status: MessagesGatewayStatus
}>()

const emit = defineEmits<{
  saved: []
}>()

const { t } = useI18n()
const { success, error } = useNotification()

const saving = ref(false)
const savingUpstream = ref(false)
const savingAliases = ref(false)

const form = ref({
  enabled: false,
  allow_operator_key: false,
  mcp_tools_enabled: false,
  mcp_tools_with_client_tools: false,
  mcp_max_iterations: 8,
  web_search_mode: 'auto' as WebSearchMode,
  vision_mode: 'auto' as VisionMode,
  vision_image_detail: 'auto' as ImageDetail,
  vision_max_images: 0,
  context_injection_enabled: false,
  budget_notice_enabled: true,
  session_summary_enabled: true,
})

const upstreamUrl = ref('')
const aliasesJson = ref('{}')

const mcpServerCount = computed(() => props.status.mcp_servers_configured ?? 0)

const mcpNote = computed(() =>
  mcpServerCount.value > 0 ? undefined : t('messagesGateway.settings.mcpTools.noneConfigured')
)
const mcpDependentNote = computed(() =>
  form.value.mcp_tools_enabled ? undefined : t('messagesGateway.settings.requiresMcpTools')
)
const webSearchNote = computed(() =>
  props.status.web_search_available ? undefined : t('messagesGateway.webSearchUnavailable')
)
const visionNote = computed(() =>
  props.status.vision_available ? undefined : t('messagesGateway.visionUnavailable')
)
const maxImagesHint = computed(() =>
  0 === form.value.vision_max_images
    ? t('messagesGateway.settings.maxImages.unlimited')
    : t('messagesGateway.settings.maxImages.limited', { count: form.value.vision_max_images })
)

watch(() => props.status, syncFromStatus, { immediate: true, deep: true })

/**
 * `synaplan` needs a configured provider. A stored mode whose provider has
 * since disappeared would leave the page advertising a capability that never
 * runs, so fall back to `auto` for display and write that back once.
 */
function usableMode<T extends WebSearchMode | VisionMode>(mode: T, available: boolean): T {
  return !available && 'synaplan' === mode ? ('auto' as T) : mode
}

function syncFromStatus() {
  const status = props.status
  form.value = {
    enabled: status.enabled,
    allow_operator_key: status.allow_operator_key ?? false,
    mcp_tools_enabled: status.mcp_tools_enabled ?? false,
    mcp_tools_with_client_tools: status.mcp_tools_with_client_tools ?? false,
    mcp_max_iterations: status.mcp_max_iterations ?? 8,
    web_search_mode: usableMode(
      status.web_search_mode ?? 'auto',
      Boolean(status.web_search_available)
    ),
    vision_mode: usableMode(status.vision_mode ?? 'auto', Boolean(status.vision_available)),
    vision_image_detail: status.vision_image_detail ?? 'auto',
    vision_max_images: status.vision_max_images ?? 0,
    context_injection_enabled: status.context_injection_enabled ?? false,
    budget_notice_enabled: status.budget_notice_enabled ?? true,
    session_summary_enabled: status.session_summary_enabled ?? true,
  }
  upstreamUrl.value = status.upstream_url
  aliasesJson.value = JSON.stringify(status.model_aliases ?? {}, null, 2)
  void persistUnusableModes()
}

async function persistUnusableModes(): Promise<void> {
  const patch: MessagesGatewaySettings = {}
  if (form.value.web_search_mode !== props.status.web_search_mode) {
    patch.web_search_mode = form.value.web_search_mode
  }
  if (form.value.vision_mode !== props.status.vision_mode) {
    patch.vision_mode = form.value.vision_mode
  }
  if (0 === Object.keys(patch).length) return

  try {
    await saveMessagesGatewayFlags(patch)
  } catch {
    // Keep the coerced value on screen; the next explicit save catches up.
  }
}

async function apply(patch: MessagesGatewaySettings) {
  if (saving.value) return
  saving.value = true
  try {
    await saveMessagesGatewayFlags(patch)
    success(t('messagesGateway.flagsSaved'))
    emit('saved')
  } catch (err) {
    error((err as Error).message || t('messagesGateway.flagsError'))
    syncFromStatus()
  } finally {
    saving.value = false
  }
}

function clamp(value: number, min: number, max: number): number {
  if (!Number.isFinite(value)) return min
  return Math.min(max, Math.max(min, Math.round(value)))
}

async function onToolRoundsChange() {
  const value = clamp(Number(form.value.mcp_max_iterations), MIN_TOOL_ROUNDS, MAX_TOOL_ROUNDS)
  form.value.mcp_max_iterations = value
  if (value === props.status.mcp_max_iterations) return
  await apply({ mcp_max_iterations: value })
}

async function onMaxImagesChange() {
  const value = clamp(Number(form.value.vision_max_images), MIN_MAX_IMAGES, MAX_MAX_IMAGES)
  form.value.vision_max_images = value
  if (value === props.status.vision_max_images) return
  await apply({ vision_max_images: value })
}

async function onSaveUpstream() {
  savingUpstream.value = true
  try {
    await saveMessagesGatewayUpstream(upstreamUrl.value.trim())
    success(t('messagesGateway.upstreamSaved'))
    emit('saved')
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
    emit('saved')
  } catch (err) {
    error((err as Error).message || t('messagesGateway.aliasesError'))
  } finally {
    savingAliases.value = false
  }
}
</script>

<template>
  <div class="surface-card p-6" data-testid="section-agents-admin">
    <div class="flex items-start gap-3 mb-2">
      <div class="p-2 rounded-lg bg-[var(--brand)]/10">
        <Icon icon="heroicons:adjustments-horizontal" class="w-5 h-5 text-[var(--brand)]" />
      </div>
      <div class="min-w-0">
        <h3 class="text-lg font-semibold txt-primary">
          {{ $t('messagesGateway.settings.title') }}
        </h3>
        <p class="txt-secondary text-sm mt-0.5">{{ $t('messagesGateway.settings.subtitle') }}</p>
      </div>
    </div>

    <p
      v-if="!form.enabled"
      class="mt-4 flex items-start gap-2 rounded-lg px-3 py-2 text-xs bg-amber-500/10 text-amber-700 dark:text-amber-300"
      data-testid="notice-agents-disabled"
    >
      <Icon icon="heroicons:pause-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
      <span>{{ $t('messagesGateway.settings.disabledNotice') }}</span>
    </p>

    <!-- Access -->
    <section class="mt-6">
      <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">
        {{ $t('messagesGateway.settings.sections.access.title') }}
      </h4>
      <p class="txt-secondary text-xs mt-1">
        {{ $t('messagesGateway.settings.sections.access.description') }}
      </p>
      <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
        <GatewaySettingRow
          :label="$t('messagesGateway.settings.enabled.label')"
          :description="$t('messagesGateway.settings.enabled.description')"
        >
          <GatewaySettingToggle
            :model-value="form.enabled"
            :label="$t('messagesGateway.settings.enabled.label')"
            data-testid="toggle-agents-enabled"
            @update:model-value="
              (value) => {
                form.enabled = value
                apply({ enabled: value })
              }
            "
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.operatorKey.label')"
          :description="$t('messagesGateway.settings.operatorKey.description')"
        >
          <GatewaySettingToggle
            :model-value="form.allow_operator_key"
            :label="$t('messagesGateway.settings.operatorKey.label')"
            data-testid="toggle-agents-operator-key"
            @update:model-value="
              (value) => {
                form.allow_operator_key = value
                apply({ allow_operator_key: value })
              }
            "
          />
        </GatewaySettingRow>
      </div>
    </section>

    <!-- Tool calling -->
    <section class="mt-8">
      <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">
        {{ $t('messagesGateway.settings.sections.tools.title') }}
      </h4>
      <p class="txt-secondary text-xs mt-1">
        {{ $t('messagesGateway.settings.sections.tools.description') }}
      </p>
      <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
        <GatewaySettingRow
          :label="$t('messagesGateway.settings.mcpTools.label')"
          :description="$t('messagesGateway.settings.mcpTools.description')"
          :note="mcpNote"
        >
          <GatewaySettingToggle
            :model-value="form.mcp_tools_enabled"
            :label="$t('messagesGateway.settings.mcpTools.label')"
            data-testid="toggle-agents-mcp-tools"
            @update:model-value="
              (value) => {
                form.mcp_tools_enabled = value
                apply({ mcp_tools_enabled: value })
              }
            "
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.mcpWithClientTools.label')"
          :description="$t('messagesGateway.settings.mcpWithClientTools.description')"
          :note="mcpDependentNote"
          :disabled="!form.mcp_tools_enabled"
        >
          <GatewaySettingToggle
            :model-value="form.mcp_tools_with_client_tools"
            :label="$t('messagesGateway.settings.mcpWithClientTools.label')"
            :disabled="!form.mcp_tools_enabled"
            data-testid="toggle-agents-mcp-with-client-tools"
            @update:model-value="
              (value) => {
                form.mcp_tools_with_client_tools = value
                apply({ mcp_tools_with_client_tools: value })
              }
            "
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.toolRounds.label')"
          :description="$t('messagesGateway.settings.toolRounds.description')"
          :note="mcpDependentNote"
          :disabled="!form.mcp_tools_enabled"
        >
          <input
            v-model.number="form.mcp_max_iterations"
            type="number"
            :min="MIN_TOOL_ROUNDS"
            :max="MAX_TOOL_ROUNDS"
            :disabled="!form.mcp_tools_enabled"
            class="w-24 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm text-right focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:cursor-not-allowed"
            data-testid="input-agents-tool-rounds"
            @change="onToolRoundsChange"
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.webSearchLabel')"
          :description="$t('messagesGateway.webSearchHint')"
          :note="webSearchNote"
        >
          <select
            :value="form.web_search_mode"
            class="w-full sm:w-56 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-agents-web-search"
            @change="
              (event) => {
                const value = (event.target as HTMLSelectElement).value as WebSearchMode
                form.web_search_mode = value
                apply({ web_search_mode: value })
              }
            "
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
        </GatewaySettingRow>
      </div>
    </section>

    <!-- Images -->
    <section class="mt-8">
      <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">
        {{ $t('messagesGateway.settings.sections.vision.title') }}
      </h4>
      <p class="txt-secondary text-xs mt-1">
        {{ $t('messagesGateway.settings.sections.vision.description') }}
      </p>
      <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
        <GatewaySettingRow
          :label="$t('messagesGateway.visionLabel')"
          :description="$t('messagesGateway.visionHint')"
          :note="visionNote"
        >
          <select
            :value="form.vision_mode"
            class="w-full sm:w-56 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-agents-vision-mode"
            @change="
              (event) => {
                const value = (event.target as HTMLSelectElement).value as VisionMode
                form.vision_mode = value
                apply({ vision_mode: value })
              }
            "
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
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.imageDetail.label')"
          :description="$t('messagesGateway.settings.imageDetail.description')"
        >
          <select
            :value="form.vision_image_detail"
            class="w-full sm:w-56 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="select-agents-image-detail"
            @change="
              (event) => {
                const value = (event.target as HTMLSelectElement).value as ImageDetail
                form.vision_image_detail = value
                apply({ vision_image_detail: value })
              }
            "
          >
            <option value="auto">
              {{ $t('messagesGateway.settings.imageDetail.optionAuto') }}
            </option>
            <option value="low">{{ $t('messagesGateway.settings.imageDetail.optionLow') }}</option>
            <option value="high">
              {{ $t('messagesGateway.settings.imageDetail.optionHigh') }}
            </option>
          </select>
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.maxImages.label')"
          :description="$t('messagesGateway.settings.maxImages.description')"
        >
          <div class="flex items-center gap-3 sm:justify-end">
            <input
              v-model.number="form.vision_max_images"
              type="number"
              :min="MIN_MAX_IMAGES"
              :max="MAX_MAX_IMAGES"
              class="w-24 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm text-right focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-agents-max-images"
              @change="onMaxImagesChange"
            />
            <span class="text-xs txt-secondary w-24 text-left">{{ maxImagesHint }}</span>
          </div>
        </GatewaySettingRow>
      </div>
    </section>

    <!-- Context & session -->
    <section class="mt-8">
      <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">
        {{ $t('messagesGateway.settings.sections.session.title') }}
      </h4>
      <p class="txt-secondary text-xs mt-1">
        {{ $t('messagesGateway.settings.sections.session.description') }}
      </p>
      <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
        <GatewaySettingRow
          :label="$t('messagesGateway.settings.contextInjection.label')"
          :description="$t('messagesGateway.settings.contextInjection.description')"
        >
          <GatewaySettingToggle
            :model-value="form.context_injection_enabled"
            :label="$t('messagesGateway.settings.contextInjection.label')"
            data-testid="toggle-agents-context-injection"
            @update:model-value="
              (value) => {
                form.context_injection_enabled = value
                apply({ context_injection_enabled: value })
              }
            "
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.budgetNotice.label')"
          :description="$t('messagesGateway.settings.budgetNotice.description')"
        >
          <GatewaySettingToggle
            :model-value="form.budget_notice_enabled"
            :label="$t('messagesGateway.settings.budgetNotice.label')"
            data-testid="toggle-agents-budget-notice"
            @update:model-value="
              (value) => {
                form.budget_notice_enabled = value
                apply({ budget_notice_enabled: value })
              }
            "
          />
        </GatewaySettingRow>

        <GatewaySettingRow
          :label="$t('messagesGateway.settings.sessionSummary.label')"
          :description="$t('messagesGateway.settings.sessionSummary.description')"
        >
          <GatewaySettingToggle
            :model-value="form.session_summary_enabled"
            :label="$t('messagesGateway.settings.sessionSummary.label')"
            data-testid="toggle-agents-session-summary"
            @update:model-value="
              (value) => {
                form.session_summary_enabled = value
                apply({ session_summary_enabled: value })
              }
            "
          />
        </GatewaySettingRow>
      </div>
    </section>

    <!-- Connection -->
    <section class="mt-8">
      <h4 class="text-sm font-semibold txt-primary uppercase tracking-wide">
        {{ $t('messagesGateway.settings.sections.connection.title') }}
      </h4>
      <p class="txt-secondary text-xs mt-1">
        {{ $t('messagesGateway.settings.sections.connection.description') }}
      </p>

      <div class="mt-4">
        <label class="block text-sm font-medium txt-primary mb-1" for="agents-upstream">
          {{ $t('messagesGateway.upstreamLabel') }}
        </label>
        <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.upstreamWarning') }}</p>
        <div class="flex flex-wrap gap-2">
          <input
            id="agents-upstream"
            v-model="upstreamUrl"
            type="url"
            class="flex-1 min-w-[16rem] px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm font-mono"
            data-testid="input-agents-upstream"
          />
          <button
            type="button"
            class="btn-primary px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
            :disabled="savingUpstream"
            data-testid="btn-agents-save-upstream"
            @click="onSaveUpstream"
          >
            {{ $t('messagesGateway.saveUpstream') }}
          </button>
        </div>
      </div>

      <div class="mt-5">
        <label class="block text-sm font-medium txt-primary mb-1" for="agents-aliases">
          {{ $t('messagesGateway.aliasesLabel') }}
        </label>
        <p class="txt-secondary text-xs mb-2">{{ $t('messagesGateway.aliasesHint') }}</p>
        <textarea
          id="agents-aliases"
          v-model="aliasesJson"
          rows="4"
          class="w-full px-3 py-2 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs font-mono"
          data-testid="input-agents-aliases"
        />
        <button
          type="button"
          class="btn-primary mt-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50"
          :disabled="savingAliases"
          data-testid="btn-agents-save-aliases"
          @click="onSaveAliases"
        >
          {{ $t('messagesGateway.saveAliases') }}
        </button>
      </div>
    </section>
  </div>
</template>
