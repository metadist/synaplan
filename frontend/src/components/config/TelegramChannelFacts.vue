<template>
  <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
    <dt class="txt-secondary">{{ t('channels.telegram.ownerLabel') }}</dt>
    <dd class="txt-primary">{{ t('channels.telegram.connectedOwner') }}</dd>
    <dt class="txt-secondary">{{ t('channels.telegram.whoElseLabel') }}</dt>
    <dd class="txt-primary">{{ t('channels.telegram.connectedWhoElse') }}</dd>
    <dt class="txt-secondary">{{ t('channels.telegram.touchesLabel') }}</dt>
    <dd class="txt-primary">{{ t('channels.telegram.connectedTouches') }}</dd>
    <dt class="txt-secondary">{{ t('channels.telegram.stopLabel') }}</dt>
    <dd class="txt-primary">{{ t('channels.telegram.connectedStop') }}</dd>
    <dt class="txt-secondary">{{ t('channels.telegram.fromLabel') }}</dt>
    <dd class="txt-primary">{{ t('channels.telegram.connectedFrom', { name: botUsername }) }}</dd>
  </dl>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ botUsername: string | null | undefined }>()

const { t } = useI18n()
</script>
