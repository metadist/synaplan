<!-- OAuth Callback Handler Component -->
<!-- With cookie-based auth, tokens are set automatically by the redirect -->
<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 relative overflow-hidden">
    <!-- Background decoration -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float"></div>
      <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl animate-float-delayed"></div>
    </div>

    <!-- Loading State -->
    <div v-if="!error" class="surface-card p-8 max-w-md w-full relative z-10">
      <div class="text-center">
        <!-- Spinner -->
        <div class="inline-block w-16 h-16 border-4 border-gray-200 dark:border-gray-700 border-t-[var(--brand)] rounded-full animate-spin mb-6"></div>
        
        <!-- Title -->
        <h2 class="text-2xl font-bold txt-primary mb-2">
          {{ $t('auth.oauthCallbackProcessing') }}
        </h2>
        
        <!-- Provider Name -->
        <p v-if="provider" class="txt-secondary text-sm">
          {{ providerName }}
        </p>
      </div>
    </div>

    <!-- Error State -->
    <div v-if="error" class="surface-card p-8 max-w-md w-full relative z-10">
      <div class="text-center">
        <!-- Error Icon -->
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/30 mb-4">
          <svg class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        </div>
        
        <!-- Error Message -->
        <h2 class="text-2xl font-bold txt-primary mb-2">
          {{ $t('auth.socialLoginError') }}
        </h2>
        <p class="txt-secondary text-sm mb-6">
          {{ error }}
        </p>
        
        <!-- Back Button -->
        <Button @click="goToLogin" class="w-full btn-primary">
          {{ $t('auth.backToLogin') }}
        </Button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'
import Button from '../Button.vue'

const router = useRouter()
const authStore = useAuthStore()
const { t } = useI18n()

const provider = ref<string | null>(null)
const error = ref<string | null>(null)

const providerName = computed(() => {
  if (provider.value === 'google') return 'Google'
  if (provider.value === 'github') return 'GitHub'
  if (provider.value === 'keycloak') return 'Keycloak'
  return provider.value
})

const goToLogin = () => {
  router.push('/login')
}

onMounted(async () => {
  try {
    // Parse URL parameters
    const urlParams = new URLSearchParams(window.location.search)
    const successParam = urlParams.get('success')
    const providerParam = urlParams.get('provider')
    const errorParam = urlParams.get('error')

    provider.value = providerParam

    console.log('🔐 OAuth Callback:', {
      success: successParam,
      provider: providerParam,
      error: errorParam,
    })

    // Check for error in URL params
    if (errorParam) {
      error.value = decodeURIComponent(errorParam)
      return
    }

    // With cookie-based auth, cookies are already set by the redirect
    // We just need to verify the session and fetch user info
    if (successParam === 'true') {
      console.log('✅ OAuth success indicated, verifying session...')
      
      try {
        // Use the OAuth callback handler which fetches user from /me
        const result = await authStore.handleOAuthCallback()
        
        if (result) {
          console.log('✅ Session verified, redirecting to home')
          // Clear URL params for clean history
          window.history.replaceState({}, document.title, '/auth/callback')
          
          // Redirect to home
          setTimeout(() => {
            router.push('/')
          }, 500)
        } else {
          console.error('❌ Session verification failed')
          error.value = t('auth.socialLoginError')
        }
      } catch (e: any) {
        console.error('❌ Failed to verify session after OAuth:', e)
        error.value = t('auth.socialLoginError')
      }
    } else {
      // No success param and no error - something went wrong
      console.error('❌ OAuth callback without success or error')
      error.value = t('auth.socialLoginError')
    }

  } catch (e: any) {
    console.error('❌ OAuth callback error:', e)
    error.value = e.message || t('auth.socialLoginError')
  }
})
</script>

<style scoped>
@keyframes float {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(-20px); }
}

@keyframes float-delayed {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(20px); }
}

.animate-float {
  animation: float 6s ease-in-out infinite;
}

.animate-float-delayed {
  animation: float-delayed 8s ease-in-out infinite;
}
</style>
