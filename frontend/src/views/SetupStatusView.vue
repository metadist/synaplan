<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-setup-status"
    >
      <div class="max-w-3xl mx-auto space-y-6">
        <PageHeader
          :title="t('settings.features.services.statusPage.title')"
          :subtitle="leadText"
          icon="mdi:server-network"
          data-testid="section-setup-status-header"
        />

        <div
          v-if="!readinessStore.loaded && !readinessStore.loadFailed"
          class="surface-card rounded-2xl p-8 text-center"
          data-testid="state-setup-status-loading"
        >
          <p class="txt-secondary text-sm">{{ t('settings.features.loading') }}</p>
        </div>

        <div
          v-else-if="readinessStore.loadFailed && readinessStore.services.length === 0"
          class="surface-card rounded-2xl p-8 text-center space-y-4"
          data-testid="state-setup-status-error"
        >
          <p class="txt-secondary text-sm">{{ t('common.error') }}</p>
          <button
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium"
            data-testid="btn-setup-status-retry"
            @click="readinessStore.refresh"
          >
            {{ t('common.retry') }}
          </button>
        </div>

        <ServiceReadinessSection
          v-else-if="readinessStore.services.length > 0"
          :services="readinessStore.services"
        />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ServiceReadinessSection from '@/components/admin/ServiceReadinessSection.vue'
import { useFreeApiStore } from '@/stores/freeApi'
import { useServiceReadinessStore } from '@/stores/serviceReadiness'

/**
 * Calm onboarding status page opened from the "Still setting up" pill
 * (`/setup/status`, plan §5.10, C15). Renders the same `services` list as
 * Operate → System status, with a lead line that notes when the Synaplan Demo
 * is answering chat while the background containers finish.
 */
const { t } = useI18n()
const freeApi = useFreeApiStore()
const readinessStore = useServiceReadinessStore()

const demoLead = computed(() => freeApi.isAvailable() && freeApi.status?.active === true)

const leadText = computed(() =>
  demoLead.value
    ? t('settings.features.services.statusPage.leadDemo')
    : t('settings.features.services.statusPage.lead')
)

onMounted(() => {
  readinessStore.acquire({ live: true })
  if (freeApi.isAvailable() && freeApi.status === null) {
    void freeApi.load()
  }
})

onUnmounted(() => {
  readinessStore.release({ live: true })
})
</script>
