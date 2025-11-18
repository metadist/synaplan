<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin" data-testid="page-config">
      <div class="max-w-7xl mx-auto" data-testid="section-config">
        <div v-if="currentPage === 'inbound'" data-testid="section-inbound">
          <InboundConfiguration />
        </div>

        <div v-else-if="currentPage === 'ai-models'" data-testid="section-ai-models">
          <AIModelsConfiguration />
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
import TaskPromptsConfiguration from '@/components/config/TaskPromptsConfiguration.vue'
import SortingPromptConfiguration from '@/components/config/SortingPromptConfiguration.vue'
import APIKeysConfiguration from '@/components/config/APIKeysConfiguration.vue'

const route = useRoute()

const currentPage = computed(() => {
  const path = route.path
  if (path.includes('/config/inbound')) return 'inbound'
  if (path.includes('/config/ai-models')) return 'ai-models'
  if (path.includes('/config/task-prompts')) return 'task-prompts'
  if (path.includes('/config/sorting-prompt')) return 'sorting-prompt'
  if (path.includes('/config/api-keys')) return 'api-keys'
  return 'inbound'
})
</script>

