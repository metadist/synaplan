<template>
  <p v-if="file.generated" class="text-xs txt-secondary" data-testid="file-provenance">
    {{
      $t('files.provenance.generated', {
        model: file.generated_model || $t('files.provenance.badge'),
        date: file.uploaded_date,
      })
    }}
    <span class="block" :title="help">{{ stateLabel }}</span>
  </p>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { FileItem } from '@/services/filesService'

const props = defineProps<{ file: FileItem }>()
const { t } = useI18n()

const stateLabel = computed(() => {
  if (props.file.credentials === 'signed' || props.file.credentials === 'both') {
    return t('files.provenance.signed')
  }
  if (props.file.credentials === 'provider') {
    return t('files.provenance.provider')
  }
  return t('files.provenance.none')
})

const help = computed(() => {
  if (props.file.credentials === 'signed' || props.file.credentials === 'both') {
    return t('files.provenance.signedHelp')
  }
  if (props.file.credentials === 'provider') {
    return t('files.provenance.providerHelp')
  }
  return t('files.provenance.noneHelp')
})
</script>
