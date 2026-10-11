<template>
  <div class="space-y-6" data-testid="panel-app-whatsapp">
    <FeatureNotConfiguredNotice
      v-if="gate"
      module="whatsapp"
      :docs="gate.docs ?? 'modules/whatsapp'"
    />
    <template v-else>
      <PhoneVerification />
      <div class="surface-card p-6" data-testid="section-whatsapp">
        <ChannelAssistantSelect
          :model-value="assistantId"
          :label="$t('channels.whatsappAssistant')"
          :none-label="$t('channels.whatsappAssistantNone')"
          :hint="$t('channels.whatsappAssistantHint')"
          test-id="select-whatsapp-assistant"
          @update:model-value="saveAssistant"
        />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ChannelAssistantSelect from '@/components/assistants/ChannelAssistantSelect.vue'
import PhoneVerification from '@/components/config/PhoneVerification.vue'
import FeatureNotConfiguredNotice from '@/components/common/FeatureNotConfiguredNotice.vue'
import {
  featureNotConfigured,
  type FeatureNotConfigured,
} from '@/services/api/featureNotConfigured'
import { getWhatsAppAssistant, setWhatsAppAssistant } from '@/services/api/whatsappAssistantApi'
import { useNotification } from '@/composables/useNotification'

const { t } = useI18n()
const { success, error } = useNotification()

const gate = ref<FeatureNotConfigured | null>(null)
const assistantId = ref<number | null>(null)

onMounted(async () => {
  try {
    assistantId.value = await getWhatsAppAssistant()
  } catch (err: unknown) {
    gate.value = featureNotConfigured(err)
  }
})

// Saved on user interaction only — a watcher would also fire for the value
// loaded on mount and greet the user with a save toast they never triggered.
async function saveAssistant(id: number | null): Promise<void> {
  const previous = assistantId.value
  assistantId.value = id
  try {
    await setWhatsAppAssistant(id)
    success(t('channels.whatsappAssistantSaved'))
  } catch {
    assistantId.value = previous
    error(t('channels.whatsappAssistantFailed'))
  }
}
</script>
