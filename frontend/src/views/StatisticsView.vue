<template>
  <MainLayout>
    <div class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin" data-testid="page-statistics">
      <div class="max-w-7xl mx-auto w-full px-6 py-8">
        <!-- Header -->
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-semibold txt-primary mb-2">
            📊 {{ $t('statistics.title') }}
          </h1>
          <p class="txt-secondary">
            {{ $t('config.usage.description') }}
          </p>
        </div>

        <!-- Real Usage Statistics Component -->
        <UsageStatistics data-testid="comp-usage-statistics" />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import UsageStatistics from '@/components/config/UsageStatistics.vue'
</script>
