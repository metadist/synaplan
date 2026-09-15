<template>
  <MainLayout>
    <div
      class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin"
      data-testid="page-statistics"
    >
      <div class="max-w-7xl mx-auto w-full px-4 md:px-6 py-4 md:py-8">
        <PageHeader
          :title="$t('statistics.title')"
          :subtitle="$t('config.usage.description')"
          icon="heroicons:chart-bar"
          data-testid="section-header"
        />

        <section data-testid="section-usage">
          <UsageStatistics />
        </section>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import UsageStatistics from '@/components/config/UsageStatistics.vue'

const route = useRoute()
const router = useRouter()

watch(
  () => route.hash,
  (hash) => {
    if (hash === '#chats') {
      void router.replace({ path: '/chats' })
    }
  },
  { immediate: true }
)
</script>
