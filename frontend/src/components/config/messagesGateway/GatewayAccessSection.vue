<script setup lang="ts">
/** Who may reach the gateway, and with whose provider key. */
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
    :title="$t('messagesGateway.settings.sections.access.title')"
    :description="$t('messagesGateway.settings.sections.access.description')"
  >
    <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
      <GatewaySettingRow
        :label="$t('messagesGateway.settings.enabled.label')"
        :description="$t('messagesGateway.settings.enabled.description')"
      >
        <GatewaySettingToggle
          :model-value="form.enabled"
          :label="$t('messagesGateway.settings.enabled.label')"
          data-testid="toggle-agents-enabled"
          @update:model-value="emit('change', { enabled: $event })"
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
          @update:model-value="emit('change', { allow_operator_key: $event })"
        />
      </GatewaySettingRow>
    </div>
  </GatewaySettingSection>
</template>
