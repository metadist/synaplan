<script setup lang="ts">
/**
 * Which tools the AI assistant can call, and who executes them.
 *
 * The section leads with what is running right now, because the settings below
 * are policy ("automatic") while the readout is outcome.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  MessagesGatewaySettings,
  MessagesGatewayStatus,
  WebFetchMode,
  WebSearchMode,
} from '@/services/api/messagesGatewayApi'
import type { GatewayForm } from './types'
import GatewayActiveTools from './GatewayActiveTools.vue'
import GatewaySettingNumber from './GatewaySettingNumber.vue'
import GatewaySettingRow from './GatewaySettingRow.vue'
import GatewaySettingSection from './GatewaySettingSection.vue'
import GatewaySettingSelect from './GatewaySettingSelect.vue'
import GatewaySettingToggle from './GatewaySettingToggle.vue'

const MIN_TOOL_ROUNDS = 1
const MAX_TOOL_ROUNDS = 32

const props = defineProps<{
  form: GatewayForm
  status: MessagesGatewayStatus
}>()

const emit = defineEmits<{
  change: [patch: MessagesGatewaySettings]
}>()

const { t } = useI18n()

const mcpNote = computed(() =>
  (props.status.mcp_servers_configured ?? 0) > 0
    ? undefined
    : t('messagesGateway.settings.mcpTools.noneConfigured')
)

/** Everything below the MCP switch is dead weight while the switch is off. */
const mcpDependentNote = computed(() =>
  props.form.mcp_tools_enabled ? undefined : t('messagesGateway.settings.requiresMcpTools')
)

const webSearchNote = computed(() =>
  props.status.web_search_available ? undefined : t('messagesGateway.webSearchUnavailable')
)

const webSearchOptions = computed(() => [
  { value: 'auto', label: t('messagesGateway.webSearchModeAuto') },
  {
    value: 'synaplan',
    label: t('messagesGateway.webSearchModeSynaplan'),
    disabled: !props.status.web_search_available,
  },
  { value: 'passthrough', label: t('messagesGateway.webSearchModePassthrough') },
  { value: 'off', label: t('messagesGateway.webSearchModeOff') },
])

const webFetchOptions = computed(() => [
  { value: 'auto', label: t('messagesGateway.webFetchModeAuto') },
  { value: 'passthrough', label: t('messagesGateway.webFetchModePassthrough') },
  { value: 'off', label: t('messagesGateway.webFetchModeOff') },
])
</script>

<template>
  <GatewaySettingSection
    :title="$t('messagesGateway.settings.sections.tools.title')"
    :description="$t('messagesGateway.settings.sections.tools.description')"
  >
    <GatewayActiveTools
      :tools="status.server_tools ?? []"
      :mcp-servers="status.mcp_servers_configured ?? 0"
      :mcp-enabled="form.mcp_tools_enabled"
    />

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
          @update:model-value="emit('change', { mcp_tools_enabled: $event })"
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
          @update:model-value="emit('change', { mcp_tools_with_client_tools: $event })"
        />
      </GatewaySettingRow>

      <GatewaySettingRow
        :label="$t('messagesGateway.settings.toolRounds.label')"
        :description="$t('messagesGateway.settings.toolRounds.description')"
        :note="mcpDependentNote"
        :disabled="!form.mcp_tools_enabled"
      >
        <GatewaySettingNumber
          :model-value="form.mcp_max_iterations"
          :min="MIN_TOOL_ROUNDS"
          :max="MAX_TOOL_ROUNDS"
          :disabled="!form.mcp_tools_enabled"
          data-testid="input-agents-tool-rounds"
          @change="emit('change', { mcp_max_iterations: $event })"
        />
      </GatewaySettingRow>

      <GatewaySettingRow
        :label="$t('messagesGateway.webSearchLabel')"
        :description="$t('messagesGateway.webSearchHint')"
        :note="webSearchNote"
      >
        <GatewaySettingSelect
          :model-value="form.web_search_mode"
          :options="webSearchOptions"
          data-testid="select-agents-web-search"
          @update:model-value="emit('change', { web_search_mode: $event as WebSearchMode })"
        />
      </GatewaySettingRow>

      <GatewaySettingRow
        :label="$t('messagesGateway.webFetchLabel')"
        :description="$t('messagesGateway.webFetchHint')"
      >
        <GatewaySettingSelect
          :model-value="form.web_fetch_mode"
          :options="webFetchOptions"
          data-testid="select-agents-web-fetch"
          @update:model-value="emit('change', { web_fetch_mode: $event as WebFetchMode })"
        />
      </GatewaySettingRow>
    </div>
  </GatewaySettingSection>
</template>
