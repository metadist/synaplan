<template>
  <MainLayout>
    <div
      ref="scrollContainer"
      class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin"
      data-testid="page-statistics"
    >
      <div class="max-w-7xl mx-auto w-full px-6 py-8 space-y-12">
        <!-- Header -->
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-semibold txt-primary mb-2">📊 {{ $t('statistics.title') }}</h1>
          <p class="txt-secondary">
            {{ $t('config.usage.description') }}
          </p>
        </div>

        <!-- Usage Statistics Section -->
        <section data-testid="section-usage">
          <UsageStatistics />
        </section>

        <!-- Divider -->
        <div class="border-t border-light-border dark:border-dark-border"></div>

        <!-- Chat Browser Section -->
        <section id="chats" ref="chatsSection" data-testid="section-chat-browser">
          <ChatBrowser />
        </section>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import UsageStatistics from '@/components/config/UsageStatistics.vue'
import ChatBrowser from '@/components/ChatBrowser.vue'

const route = useRoute()
const scrollContainer = ref<HTMLElement | null>(null)
const chatsSection = ref<HTMLElement | null>(null)

const scrollToChats = async () => {
  if (route.hash === '#chats' && scrollContainer.value && chatsSection.value) {
    await nextTick()
    // Get the offset position of the chats section relative to the container
    const offsetTop = chatsSection.value.offsetTop - 20 // 20px padding from top
    scrollContainer.value.scrollTo({
      top: offsetTop,
      behavior: 'smooth',
    })
  }
}

onMounted(() => {
  // Small delay to ensure everything is rendered
  setTimeout(scrollToChats, 100)
})

// Watch for hash changes
watch(() => route.hash, scrollToChats)
</script>
