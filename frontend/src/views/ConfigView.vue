<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-config"
    >
      <div class="max-w-[100rem] mx-auto" data-testid="section-config">
        <div v-if="currentPage === 'ai-models'" data-testid="section-ai-models">
          <AIModelsConfiguration />
        </div>

        <div v-else-if="currentPage === 'saved-tasks'" data-testid="section-saved-tasks">
          <SavedTasksOverview />
        </div>

        <div v-else-if="currentPage === 'approvals'" data-testid="section-approvals">
          <ApprovalsInbox />
        </div>

        <div
          v-else-if="currentPage === 'api-documentation'"
          data-testid="section-api-documentation"
        >
          <ApiDocumentation />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import AIModelsConfiguration from '@/components/config/AIModelsConfiguration.vue'
import ApiDocumentation from '@/components/config/ApiDocumentation.vue'
import SavedTasksOverview from '@/components/config/SavedTasksOverview.vue'
import ApprovalsInbox from '@/components/config/ApprovalsInbox.vue'

const route = useRoute()

// Canonical paths only; older URLs arrive here through router redirects.
const currentPage = computed(() => {
  const path = route.path
  if (path.startsWith('/apps/api/docs')) return 'api-documentation'
  if (path === '/tasks') return 'saved-tasks'
  if (path === '/approvals') return 'approvals'
  return 'ai-models'
})
</script>
