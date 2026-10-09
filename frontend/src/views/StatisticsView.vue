<template>
  <MainLayout>
    <div
      class="flex h-full flex-col overflow-y-auto bg-chat px-3 py-4 sm:p-4 md:p-8 scroll-thin"
      data-testid="page-statistics"
    >
      <div class="mx-auto w-full max-w-[100rem]">
        <PageHeader
          :title="$t('config.usage.title')"
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
