<script setup lang="ts">
import { Icon } from '@iconify/vue'
import ConfigField from '@/components/admin/ConfigField.vue'
import DropboxSetupGuide from '@/components/admin/DropboxSetupGuide.vue'
import M365SetupGuide from '@/components/admin/M365SetupGuide.vue'
import ManagedKeysStatusCard from '@/components/admin/ManagedKeysStatusCard.vue'
import type { ResolvedConfigSection, SystemConfigHandle } from '@/composables/useSystemConfig'

defineProps<{
  section: ResolvedConfigSection
  config: SystemConfigHandle
}>()
</script>

<template>
  <div class="space-y-4" :data-testid="`config-section-body-${section.id}`">
    <p v-if="section.isLive" class="text-xs txt-secondary">
      {{ $t('admin.config.liveHint') }}
    </p>
    <M365SetupGuide v-if="section.tab === 'channels' && section.id === 'm365'" />
    <DropboxSetupGuide v-if="section.tab === 'channels' && section.id === 'dropbox'" />
    <ManagedKeysStatusCard v-if="section.allManaged" :fields="section.managedFields" />
    <template v-else>
      <ConfigField
        v-for="field in section.fields"
        :key="field.key"
        :field-key="field.key"
        :schema="field.schema"
        :value="field.value"
        @update="config.update"
      />
      <ManagedKeysStatusCard
        v-if="section.managedFields.length > 0"
        :fields="section.managedFields"
      />
    </template>
    <button
      v-if="section.testService"
      type="button"
      :disabled="!!config.testingService.value"
      class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
      :data-testid="`btn-config-test-${section.testService}`"
      @click="config.testService(section.testService)"
    >
      <Icon
        :icon="
          config.testingService.value === section.testService ? 'mdi:loading' : 'mdi:connection'
        "
        :class="['w-4 h-4', config.testingService.value === section.testService && 'animate-spin']"
        aria-hidden="true"
      />
      {{ $t('admin.config.testConnection') }}
    </button>
  </div>
</template>
