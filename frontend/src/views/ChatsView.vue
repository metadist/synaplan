<template>
  <MainLayout data-testid="page-chats">
    <div class="min-h-screen overflow-x-hidden bg-chat px-3 py-4 sm:p-4 md:p-8">
      <div class="mx-auto w-full max-w-[100rem]">
        <PageHeader
          :title="$t('pageTitles.allChats')"
          :subtitle="$t('chat.browser.description')"
          icon="mdi:forum-outline"
        />
        <ChatBrowser hide-header />
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ChatBrowser from '@/components/ChatBrowser.vue'
</script>
