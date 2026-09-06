<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-files-incoming"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <FilesTabs active="incoming" />
        <div class="surface-card p-4 sm:p-6">
          <IncomingInbox />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import FilesTabs from '@/components/files/FilesTabs.vue'
import IncomingInbox from '@/components/files/IncomingInbox.vue'
</script>
