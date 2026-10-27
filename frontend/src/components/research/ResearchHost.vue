<template>
  <ResearchComposer />
  <Teleport v-if="thread" :to="thread">
    <ResearchCard />
  </Teleport>
  <div v-else class="fixed inset-x-0 bottom-24 z-40 px-3">
    <ResearchCard />
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useResearchStore } from '@/stores/research'
import ResearchCard from './ResearchCard.vue'
import ResearchComposer from './ResearchComposer.vue'

const auth = useAuthStore()
const chats = useChatsStore()
const research = useResearchStore()
const { activeChatId } = storeToRefs(chats)
const thread = ref<HTMLElement | null>(null)

function bindThread(): void {
  thread.value = document.querySelector('[data-testid="section-messages"] > div')
}

watch(
  activeChatId,
  (chatId) => {
    bindThread()
    research.attachChat(chatId)
  },
  { immediate: true }
)

watch(
  () => auth.isAuthenticated,
  (signedIn) => {
    if (signedIn) void research.ensureCapabilities()
  },
  { immediate: true }
)

onMounted(() => {
  bindThread()
})

onUnmounted(() => {
  research.pause()
})
</script>
