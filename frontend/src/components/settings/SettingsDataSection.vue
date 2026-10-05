<template>
  <section id="data" class="space-y-6 scroll-mt-24">
    <ExportImportPanel />
    <div v-show="!profileLoadFailed" class="info-box-blue" data-testid="section-privacy-notice">
      <p class="text-sm info-box-blue-text flex items-start gap-2">
        <Icon icon="mdi:information" class="w-5 h-5 info-box-blue-icon flex-shrink-0 mt-0.5" />
        <span>{{ $t('profile.privacyNotice') }}</span>
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import ExportImportPanel from '@/components/settings/ExportImportPanel.vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { profileLoadFailed } = injectProfileSettings()
</script>
