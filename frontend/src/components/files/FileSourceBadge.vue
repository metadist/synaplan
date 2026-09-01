<template>
  <!-- Same as FileVectorPill: nowrap on the outer element defeats the inner
       truncate, so the badge could grow past its column. Default web uploads
       are omitted — labelling every row "Upload" does not distinguish anything. -->
  <span
    v-if="visible"
    class="inline-flex items-center gap-1 text-[11px] txt-secondary min-w-0 max-w-full overflow-hidden"
    :title="t('files.help.source')"
    data-testid="file-source-badge"
  >
    <Icon :icon="icon" class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
    <span class="truncate whitespace-nowrap">{{ label }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { FileSource } from '@/services/filesService'

const props = withDefaults(
  defineProps<{
    source?: FileSource
  }>(),
  {
    source: 'web_upload',
  }
)

const { t } = useI18n()

const visible = computed(() => props.source !== 'web_upload')

const icon = computed(() => {
  const map: Record<FileSource, string> = {
    web_upload: 'mdi:tray-arrow-up',
    chat_attachment: 'mdi:chat-outline',
    outlook: 'mdi:microsoft-outlook',
    nextcloud: 'mdi:cloud-outline',
    opencloud: 'mdi:cloud-outline',
    whatsapp: 'mdi:whatsapp',
    widget: 'mdi:puzzle-outline',
    api: 'mdi:api',
    generated: 'mdi:auto-fix',
  }
  return map[props.source] ?? 'mdi:tray-arrow-up'
})

const label = computed(() => t(`files.sourceLabel.${props.source}`))
</script>
