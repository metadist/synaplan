<template>
  <div class="min-h-screen bg-app txt-primary text-[16px] leading-6">
    <ErrorBoundary>
      <Suspense>
        <template #default>
          <router-view />
        </template>
        <template #fallback>
          <LoadingView />
        </template>
      </Suspense>
    </ErrorBoundary>
    <NotificationContainer />
    <Dialog />
  </div>
</template>

<script setup lang="ts">
import { useTheme } from './composables/useTheme'
import NotificationContainer from '@/components/NotificationContainer.vue'
import Dialog from '@/components/Dialog.vue'
import ErrorBoundary from '@/components/ErrorBoundary.vue'
import LoadingView from '@/views/LoadingView.vue'

useTheme()
</script>
