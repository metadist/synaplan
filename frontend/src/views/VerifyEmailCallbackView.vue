<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12" data-testid="page-verify-email-callback">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <img :src="logoSrc" alt="synaplan" class="h-12 mx-auto mb-6" />
      </div>

      <div class="surface-card p-8 text-center" data-testid="section-verify-card">
        <div v-if="loading" data-testid="state-loading">
          <div class="w-16 h-16 mx-auto rounded-full bg-blue-500/10 flex items-center justify-center mb-6">
            <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
          <h2 class="text-2xl font-bold txt-primary mb-2">Verifying Email...</h2>
          <p class="txt-secondary">Please wait while we verify your email address.</p>
        </div>

        <div v-else-if="verified" data-testid="state-success">
          <div class="w-16 h-16 mx-auto rounded-full bg-green-500/10 flex items-center justify-center mb-6">
            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
          </div>
          <h2 class="text-2xl font-bold txt-primary mb-2">Email Verified!</h2>
          <p class="txt-secondary mb-6">Your email has been successfully verified.</p>
          <router-link to="/login" class="btn-primary px-6 py-3 rounded-lg inline-block" data-testid="link-success-login">
            Go to Login
          </router-link>
        </div>

        <div v-else data-testid="state-error">
          <div class="w-16 h-16 mx-auto rounded-full bg-red-500/10 flex items-center justify-center mb-6">
            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </div>
          <h2 class="text-2xl font-bold txt-primary mb-2">Verification Failed</h2>
          <p class="txt-secondary mb-6">{{ error || 'Invalid or expired verification token.' }}</p>
          <router-link to="/login" class="btn-secondary px-6 py-3 rounded-lg inline-block" data-testid="link-error-login">
            Back to Login
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from '../composables/useTheme'
import { authApi } from '@/services/api'

const route = useRoute()
const router = useRouter()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const loading = ref(true)
const verified = ref(false)
const error = ref('')

onMounted(async () => {
  const token = route.query.token as string

  if (!token) {
    error.value = 'No verification token provided.'
    loading.value = false
    return
  }

  try {
    await authApi.verifyEmail(token)
    verified.value = true
  } catch (err: any) {
    console.error('Email verification failed:', err)
    error.value = err.response?.data?.error || err.message || 'Verification failed.'
    verified.value = false
  } finally {
    loading.value = false
  }
})
</script>
