<template>
  <div class="mb-6">
    <div class="flex flex-wrap gap-2 mb-3">
      <span
        v-for="adapter in adapters"
        :key="adapter.key"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs surface-card border border-light-border/30 dark:border-dark-border/20"
        :data-testid="`extraction-health-${adapter.key}`"
      >
        <span
          class="w-2 h-2 rounded-full"
          :class="
            adapter.health.available ? 'bg-[var(--status-success)]' : 'bg-[var(--status-warning)]'
          "
        />
        <span class="txt-primary">{{ adapter.label }}</span>
        <span class="txt-secondary">
          {{
            adapter.health.available
              ? $t('aiInfra.extraction.healthAvailable')
              : $t('aiInfra.extraction.healthUnavailable')
          }}
        </span>
        <span
          v-if="!adapter.health.available && adapter.health.reason"
          class="txt-secondary max-w-[16rem] truncate"
          :title="adapter.health.reason"
        >
          — {{ adapter.health.reason }}
        </span>
      </span>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <button
        v-for="service in sidecarServices"
        :key="service"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
        :disabled="testing === service"
        :data-testid="`extraction-test-${service}`"
        @click="testSidecar(service)"
      >
        {{
          testing === service
            ? $t('aiInfra.extraction.testingSidecar')
            : $t(`aiInfra.extraction.testSidecar.${service}`)
        }}
      </button>
      <RouterLink
        class="text-sm text-[var(--brand)] underline underline-offset-2"
        :to="{ path: '/admin/config', query: { tab: 'processing', section: 'docling' } }"
        data-testid="extraction-sidecar-settings"
      >
        {{ $t('aiInfra.extraction.sidecarSettingsLink') }}
      </RouterLink>
    </div>
    <p class="text-sm txt-secondary mt-2">{{ $t('aiInfra.extraction.sidecarSettings') }}</p>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { testConnection } from '@/services/api/adminConfigApi'
import type { ExtractionStatus } from '@/services/api/adminPlugsApi'

defineProps<{
  adapters: ExtractionStatus['adapters']
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

const sidecarServices = ['tika', 'docling'] as const
const testing = ref<string | null>(null)

async function testSidecar(service: (typeof sidecarServices)[number]) {
  testing.value = service
  try {
    const result = await testConnection(service)
    if (result.success) {
      success(result.message)
    } else {
      showError(result.message)
    }
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.extraction.loadFailed'))
  } finally {
    testing.value = null
  }
}
</script>
