<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <p class="text-sm txt-secondary" data-testid="stream-reconnecting">
    {{ t('processing.reconnectingDesc') }}
  </p>
</template>
