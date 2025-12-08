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
import { useAuthStore } from '@/stores/auth'
import NotificationContainer from '@/components/NotificationContainer.vue'
import Dialog from '@/components/Dialog.vue'
import ErrorBoundary from '@/components/ErrorBoundary.vue'
import LoadingView from '@/views/LoadingView.vue'

useTheme()

// SECURITY: Clean up any legacy localStorage entries from before cookie-based auth
// These should NEVER exist - if they do, they're from old code and must be removed
const legacyKeys = ['auth_token', 'auth_user', 'refresh_token', 'dev-token']
legacyKeys.forEach(key => {
  if (localStorage.getItem(key)) {
    console.warn(`🧹 Removing legacy localStorage key: ${key}`)
    localStorage.removeItem(key)
  }
})

// Initialize auth state on app start (validates cookies via /me endpoint)
const authStore = useAuthStore()
authStore.checkAuth()
</script>
