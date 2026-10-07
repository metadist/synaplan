<template>
  <MainLayout data-testid="page-chats">
    <div class="container mx-auto px-6 py-8 max-w-[100rem] overflow-x-hidden">
      <PageHeader
        :title="$t('pageTitles.allChats')"
        :subtitle="$t('chat.browser.description')"
        icon="mdi:forum-outline"
      />
      <ChatBrowser hide-header />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ChatBrowser from '@/components/ChatBrowser.vue'
</script>
