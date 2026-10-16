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
    <p class="text-sm txt-secondary">
      {{ $t('aiInfra.extraction.sidecarSettings') }}
      <RouterLink
        class="text-[var(--brand)] underline underline-offset-2"
        :to="{ path: AI_INFRA_PATH, query: { tab: 'documents', section: 'tika' } }"
        data-testid="extraction-sidecar-settings"
      >
        {{ $t('aiInfra.extraction.sidecarSettingsLink') }}
      </RouterLink>
    </p>
  </div>
</template>

<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { AI_INFRA_PATH } from '@/constants/operateSettings'
import type { ExtractionStatus } from '@/services/api/adminPlugsApi'

defineProps<{
  adapters: ExtractionStatus['adapters']
}>()
</script>
