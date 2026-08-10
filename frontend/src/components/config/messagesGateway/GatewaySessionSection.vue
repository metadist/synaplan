<script setup lang="ts">
/** What Synaplan adds around the conversation itself. */
import type { MessagesGatewaySettings } from '@/services/api/messagesGatewayApi'
import type { GatewayForm } from './types'
import GatewaySettingRow from './GatewaySettingRow.vue'
import GatewaySettingSection from './GatewaySettingSection.vue'
import GatewaySettingToggle from './GatewaySettingToggle.vue'

defineProps<{
  form: GatewayForm
}>()

const emit = defineEmits<{
  change: [patch: MessagesGatewaySettings]
}>()
</script>

<template>
  <GatewaySettingSection
    :title="$t('messagesGateway.settings.sections.session.title')"
    :description="$t('messagesGateway.settings.sections.session.description')"
  >
    <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
      <GatewaySettingRow
        :label="$t('messagesGateway.settings.contextInjection.label')"
        :description="$t('messagesGateway.settings.contextInjection.description')"
      >
        <GatewaySettingToggle
          :model-value="form.context_injection_enabled"
          :label="$t('messagesGateway.settings.contextInjection.label')"
          data-testid="toggle-agents-context-injection"
          @update:model-value="emit('change', { context_injection_enabled: $event })"
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
          @update:model-value="emit('change', { budget_notice_enabled: $event })"
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
          @update:model-value="emit('change', { session_summary_enabled: $event })"
        />
      </GatewaySettingRow>
    </div>
  </GatewaySettingSection>
</template>
