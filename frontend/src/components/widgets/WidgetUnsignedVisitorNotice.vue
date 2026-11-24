<template>
  <div
    v-if="visible"
    class="info-box-yellow flex items-start gap-3"
    role="status"
    data-testid="notice-visitor-unsigned"
  >
    <Icon
      icon="heroicons:exclamation-triangle"
      class="info-box-yellow-icon w-5 h-5 flex-shrink-0 mt-0.5"
    />
    <div class="flex-1 min-w-0">
      <p class="info-box-yellow-title text-sm">
        {{ t('widgets.advancedConfig.userDataIntegration.unsigned.title') }}
      </p>
      <p class="info-box-yellow-text text-xs mt-1 mb-3">
        {{ t('widgets.advancedConfig.userDataIntegration.unsigned.text') }}
      </p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="busy"
        data-testid="btn-visitor-require-signature"
        @click="requireSignature"
      >
        <Icon icon="heroicons:shield-check" class="w-4 h-4" />
        {{ t('widgets.advancedConfig.userDataIntegration.unsigned.action') }}
      </button>
    </div>
  </div>
</template>

<script lang="ts">
import { ref } from 'vue'

/** Widgets switched in this page visit; the widget list still holds their old config. */
const switchedOn = ref<string[]>([])
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { requireVisitorSignature } from '@/services/api/widgetVisitorApi'

const props = defineProps<{ widgetId: string; unsignedAllowed: boolean }>()
const { t } = useI18n()
const { confirm } = useDialog()
const { success, error } = useNotification()

const busy = ref(false)
const visible = computed(() => props.unsignedAllowed && !switchedOn.value.includes(props.widgetId))

async function requireSignature(): Promise<void> {
  const ok = await confirm({
    title: t('widgets.advancedConfig.userDataIntegration.unsigned.confirmTitle'),
    message: t('widgets.advancedConfig.userDataIntegration.unsigned.confirmText'),
    confirmText: t('widgets.advancedConfig.userDataIntegration.unsigned.action'),
    danger: true,
  })
  if (!ok) {
    return
  }
  busy.value = true
  try {
    await requireVisitorSignature(props.widgetId)
    switchedOn.value = [...switchedOn.value, props.widgetId]
    success(t('widgets.advancedConfig.userDataIntegration.unsigned.done'))
  } catch {
    error(t('widgets.advancedConfig.userDataIntegration.unsigned.failed'))
  } finally {
    busy.value = false
  }
}
</script>
