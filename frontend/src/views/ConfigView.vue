<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-config"
    >
      <div class="max-w-7xl mx-auto" data-testid="section-config">
        <div v-if="currentPage === 'inbound'" data-testid="section-inbound">
          <InboundConfiguration />
        </div>

        <div v-else-if="currentPage === 'ai-models'" data-testid="section-ai-models">
          <AIModelsConfiguration />
        </div>

        <div
          v-else-if="currentPage === 'ai-provider-higgsfield'"
          data-testid="section-ai-provider-higgsfield"
        >
          <HiggsfieldConnection />
        </div>

        <div v-else-if="currentPage === 'task-prompts'" data-testid="section-task-prompts">
          <TaskPromptsConfiguration />
        </div>

        <div v-else-if="currentPage === 'sorting-prompt'" data-testid="section-sorting-prompt">
          <SortingPromptConfiguration />
        </div>

        <div v-else-if="currentPage === 'api-keys'" data-testid="section-api-keys">
          <APIKeysConfiguration />
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
import InboundConfiguration from '@/components/config/InboundConfiguration.vue'
import AIModelsConfiguration from '@/components/config/AIModelsConfiguration.vue'
import HiggsfieldConnection from '@/components/config/HiggsfieldConnection.vue'
import TaskPromptsConfiguration from '@/components/config/TaskPromptsConfiguration.vue'
import SortingPromptConfiguration from '@/components/config/SortingPromptConfiguration.vue'
import APIKeysConfiguration from '@/components/config/APIKeysConfiguration.vue'
import ApiDocumentation from '@/components/config/ApiDocumentation.vue'

const route = useRoute()

// Canonical paths per the §4.6 URL map; legacy /config/* arrives here only
// via router redirects, so matching the new tree is sufficient.
const currentPage = computed(() => {
  const path = route.path
  if (path.startsWith('/channels/api/docs')) return 'api-documentation'
  if (path.startsWith('/channels/api')) return 'api-keys'
  if (path.startsWith('/channels')) return 'inbound'
  if (path.startsWith('/ai/providers/higgsfield')) return 'ai-provider-higgsfield'
  if (path.startsWith('/ai/models')) return 'ai-models'
  if (path.startsWith('/ai/instructions')) return 'task-prompts'
  if (path.startsWith('/ai/routing')) return 'sorting-prompt'
  return 'inbound'
})
</script>
