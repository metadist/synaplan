<!-- OAuth Callback Handler Component -->
<template>
  <div class="oauth-callback">
    <div class="loading-spinner">
      <div class="spinner"></div>
      <h2>{{ $t('auth.oauthCallbackProcessing') }}</h2>
      <p v-if="provider">{{ providerName }}</p>
    </div>

    <div v-if="error" class="error-container">
      <div class="error-icon">⚠️</div>
      <h2>{{ $t('auth.socialLoginError') }}</h2>
      <p>{{ error }}</p>
      <button @click="goToLogin" class="btn-primary">
        {{ $t('auth.backToLogin') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'

const router = useRouter()
const authStore = useAuthStore()
const { t } = useI18n()

const provider = ref<string | null>(null)
const error = ref<string | null>(null)

const providerName = computed(() => {
  if (provider.value === 'google') return 'Google'
  if (provider.value === 'github') return 'GitHub'
  return provider.value
})

const goToLogin = () => {
  router.push('/login')
}

onMounted(async () => {
  try {
    // Parse URL parameters
    const urlParams = new URLSearchParams(window.location.search)
    const token = urlParams.get('token')
    const providerParam = urlParams.get('provider')
    const errorParam = urlParams.get('error')

    provider.value = providerParam

    if (errorParam) {
      error.value = errorParam
      return
    }

    if (!token) {
      error.value = t('auth.socialLoginError')
      return
    }

    // Store JWT token in localStorage
    localStorage.setItem('auth_token', token)

    // Set token in auth store
    authStore.token = token

    // Fetch user info with the new token
    try {
      await authStore.refreshUser()
      
      // Redirect to home after successful authentication
      setTimeout(() => {
        router.push('/')
      }, 500)
    } catch (e: any) {
      console.error('Failed to fetch user after OAuth:', e)
      error.value = t('auth.socialLoginError')
      // Clear invalid token
      localStorage.removeItem('auth_token')
      authStore.token = null
    }

  } catch (e: any) {
    console.error('OAuth callback error:', e)
    error.value = e.message || t('auth.socialLoginError')
  }
})
</script>

<style scoped>
.oauth-callback {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.loading-spinner,
.error-container {
  text-align: center;
  padding: 48px;
  background: white;
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  max-width: 400px;
  margin: 24px;
}

.spinner {
  width: 48px;
  height: 48px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid #667eea;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: 0 auto 24px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

h2 {
  font-size: 24px;
  font-weight: 600;
  color: #333;
  margin: 0 0 12px;
}

p {
  font-size: 16px;
  color: #666;
  margin: 0;
}

.error-container {
  background: #fff5f5;
  border: 2px solid #feb2b2;
}

.error-icon {
  font-size: 48px;
  margin-bottom: 16px;
}

.error-container h2 {
  color: #c53030;
}

.error-container p {
  color: #742a2a;
  margin-bottom: 24px;
}

.btn-primary {
  padding: 12px 24px;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-primary:hover {
  background: #5568d3;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-primary:active {
  transform: translateY(0);
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  .loading-spinner,
  .error-container {
    background: #2a2a2a;
  }

  h2 {
    color: #fff;
  }

  p {
    color: #ccc;
  }

  .error-container {
    background: #3a1f1f;
  }

  .error-container p {
    color: #ffb3b3;
  }
}
</style>

