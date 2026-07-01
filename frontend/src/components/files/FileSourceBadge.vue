<template>
  <span
    class="inline-flex items-center gap-1 text-[11px] txt-secondary whitespace-nowrap"
    :title="t('files.help.source')"
    data-testid="file-source-badge"
  >
    <Icon :icon="icon" class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
    <span class="truncate">{{ label }}</span>
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
