<template>
  <section class="surface-card rounded-lg p-4 mb-6" data-testid="research-admin-auto">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h2 class="text-lg font-semibold txt-primary">{{ t('adminSetup.research.auto.title') }}</h2>
        <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.auto.help') }}</p>
      </div>
      <GatewaySettingToggle
        :model-value="autoEnabled"
        :label="t('adminSetup.research.auto.title')"
        :disabled="!researchOn || switching"
        testid="research-admin-auto-switch"
        @update:model-value="setAuto"
      />
    </div>

    <p
      v-if="!researchOn"
      class="text-sm txt-secondary mt-3"
      data-testid="research-admin-auto-needs-research"
    >
      {{ t('adminSetup.research.auto.needsResearch') }}
    </p>
    <p
      v-else-if="!autoEnabled"
      class="text-sm txt-secondary mt-3"
      data-testid="research-admin-auto-consequence"
    >
      {{ t('adminSetup.research.auto.offConsequence') }}
    </p>
    <p
      v-else-if="!webSearchAvailable"
      class="text-sm txt-secondary mt-3"
      data-testid="research-admin-auto-no-web"
    >
      {{ t('adminSetup.research.auto.noWeb') }}
    </p>

    <p v-if="errorText" class="text-sm text-red-600 dark:text-red-400 mt-3" role="alert">
      {{ errorText }}
    </p>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import GatewaySettingToggle from '@/components/config/messagesGateway/GatewaySettingToggle.vue'
import { saveResearchAdmin, type ResearchAdmin } from '@/services/api/researchAdminApi'
import { useResearchStore } from '@/stores/research'
import { REFUSAL_KEYS, researchRefusal } from './researchAdminErrors'

/**
 * The second switch of the research tab: whether the chat starts a research
 * on its own. It needs the first switch; while research is off it only says
 * so. A successful change drops the chat's cached capabilities so Look
 * deeper follows at once.
 */
const props = defineProps<{
  researchOn: boolean
  autoEnabled: boolean
  webSearchAvailable: boolean
}>()

const emit = defineEmits<{
  /** The tab as the server stored it after the change. */
  saved: [view: ResearchAdmin]
}>()

const { t } = useI18n()
const researchStore = useResearchStore()
const switching = ref(false)
const errorText = ref('')

async function setAuto(enabled: boolean): Promise<void> {
  if (enabled === props.autoEnabled || !props.researchOn) return
  switching.value = true
  errorText.value = ''
  try {
    const view = await saveResearchAdmin({ autoEnabled: enabled })
    researchStore.forgetCapabilities()
    emit('saved', view)
  } catch (error) {
    const code = researchRefusal(error)?.code
    errorText.value =
      code === 'research_off'
        ? t('adminSetup.research.auto.needsResearch')
        : t(REFUSAL_KEYS[code ?? ''] ?? 'adminSetup.research.auto.switchFailed')
  } finally {
    switching.value = false
  }
}
</script>
