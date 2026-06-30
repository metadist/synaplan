<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-files-generated"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <FilesTabs active="generated" />
        <div class="surface-card p-4 sm:p-6">
          <FilesGrid />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import FilesTabs from '@/components/files/FilesTabs.vue'
import FilesGrid from '@/components/files/FilesGrid.vue'
</script>
