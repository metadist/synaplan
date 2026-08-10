<script setup lang="ts">
/**
 * Image handling, in two independent layers.
 *
 * The mode decides *who reads* an image turn (Synaplan's vision model or the
 * upstream). Detail and the per-request cap decide *what goes on the wire* and
 * apply in every mode — an agent loop that resends its whole screenshot history
 * is expensive no matter which model ends up reading it.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  ImageDetail,
  MessagesGatewaySettings,
  MessagesGatewayStatus,
  VisionMode,
} from '@/services/api/messagesGatewayApi'
import type { GatewayForm } from './types'
import GatewaySettingNumber from './GatewaySettingNumber.vue'
import GatewaySettingRow from './GatewaySettingRow.vue'
import GatewaySettingSection from './GatewaySettingSection.vue'
import GatewaySettingSelect from './GatewaySettingSelect.vue'

const MIN_MAX_IMAGES = 0
const MAX_MAX_IMAGES = 100

const props = defineProps<{
  form: GatewayForm
  status: MessagesGatewayStatus
}>()

const emit = defineEmits<{
  change: [patch: MessagesGatewaySettings]
}>()

const { t } = useI18n()

const visionNote = computed(() =>
  props.status.vision_available ? undefined : t('messagesGateway.visionUnavailable')
)

const visionOptions = computed(() => [
  { value: 'auto', label: t('messagesGateway.visionModeAuto') },
  {
    value: 'synaplan',
    label: t('messagesGateway.visionModeSynaplan'),
    disabled: !props.status.vision_available,
  },
  { value: 'passthrough', label: t('messagesGateway.visionModePassthrough') },
  { value: 'off', label: t('messagesGateway.visionModeOff') },
])

const detailOptions = computed(() => [
  { value: 'auto', label: t('messagesGateway.settings.imageDetail.optionAuto') },
  { value: 'low', label: t('messagesGateway.settings.imageDetail.optionLow') },
  { value: 'high', label: t('messagesGateway.settings.imageDetail.optionHigh') },
])

const maxImagesHint = computed(() =>
  0 === props.form.vision_max_images
    ? t('messagesGateway.settings.maxImages.unlimited')
    : t('messagesGateway.settings.maxImages.limited', { count: props.form.vision_max_images })
)
</script>

<template>
  <GatewaySettingSection
    :title="$t('messagesGateway.settings.sections.vision.title')"
    :description="$t('messagesGateway.settings.sections.vision.description')"
  >
    <div class="divide-y divide-light-border/20 dark:divide-dark-border/10">
      <GatewaySettingRow
        :label="$t('messagesGateway.visionLabel')"
        :description="$t('messagesGateway.visionHint')"
        :note="visionNote"
      >
        <GatewaySettingSelect
          :model-value="form.vision_mode"
          :options="visionOptions"
          data-testid="select-agents-vision-mode"
          @update:model-value="emit('change', { vision_mode: $event as VisionMode })"
        />
      </GatewaySettingRow>

      <GatewaySettingRow
        :label="$t('messagesGateway.settings.imageDetail.label')"
        :description="$t('messagesGateway.settings.imageDetail.description')"
      >
        <GatewaySettingSelect
          :model-value="form.vision_image_detail"
          :options="detailOptions"
          data-testid="select-agents-image-detail"
          @update:model-value="emit('change', { vision_image_detail: $event as ImageDetail })"
        />
      </GatewaySettingRow>

      <GatewaySettingRow
        :label="$t('messagesGateway.settings.maxImages.label')"
        :description="$t('messagesGateway.settings.maxImages.description')"
      >
        <div class="flex items-center gap-3 sm:justify-end">
          <GatewaySettingNumber
            :model-value="form.vision_max_images"
            :min="MIN_MAX_IMAGES"
            :max="MAX_MAX_IMAGES"
            data-testid="input-agents-max-images"
            @change="emit('change', { vision_max_images: $event })"
          />
          <span class="text-xs txt-secondary w-24 text-left">{{ maxImagesHint }}</span>
        </div>
      </GatewaySettingRow>
    </div>
  </GatewaySettingSection>
</template>
