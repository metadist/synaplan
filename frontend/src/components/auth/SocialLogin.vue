<!-- Social Login Button Component for Vue 3 -->
<template>
  <div class="social-login">
    <button 
      @click="loginWithGoogle" 
      class="btn-social btn-google"
      :disabled="loading"
    >
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
      </svg>
      <span>{{ $t('auth.loginWithGoogle') }}</span>
    </button>

    <button 
      @click="loginWithGitHub" 
      class="btn-social btn-github"
      :disabled="loading"
    >
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
        <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
      </svg>
      <span>{{ $t('auth.loginWithGitHub') }}</span>
    </button>

    <div v-if="error" class="error-message">
      {{ error }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'

const { t } = useI18n()
const loading = ref(false)
const error = ref<string | null>(null)

const config = useConfigStore()
const API_BASE_URL = config.appBaseUrl

const loginWithGoogle = () => {
  try {
    loading.value = true
    error.value = null
    
    // Redirect to Google OAuth
    window.location.href = `${API_BASE_URL}/api/v1/auth/google/login`
  } catch (e: any) {
    error.value = e.message || t('auth.socialLoginError')
    loading.value = false
  }
}

const loginWithGitHub = () => {
  try {
    loading.value = true
    error.value = null
    
    // Redirect to GitHub OAuth
    window.location.href = `${API_BASE_URL}/api/v1/auth/github/login`
  } catch (e: any) {
    error.value = e.message || t('auth.socialLoginError')
    loading.value = false
  }
}
</script>

<style scoped>
.social-login {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
  max-width: 400px;
  margin: 0 auto;
}

.btn-social {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 12px 24px;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  background: white;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  width: 100%;
}

.btn-social:hover:not(:disabled) {
  background: #f5f5f5;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.btn-social:active:not(:disabled) {
  transform: translateY(0);
}

.btn-social:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-google {
  border-color: #4285f4;
  color: #333;
}

.btn-google:hover:not(:disabled) {
  background: #4285f4;
  color: white;
}

.btn-github {
  border-color: #24292e;
  color: #24292e;
}

.btn-github:hover:not(:disabled) {
  background: #24292e;
  color: white;
}

.icon {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}

.error-message {
  padding: 12px;
  background: #fee;
  border: 1px solid #fcc;
  border-radius: 8px;
  color: #c33;
  font-size: 14px;
  text-align: center;
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  .btn-social {
    background: #2a2a2a;
    border-color: #444;
    color: #fff;
  }

  .btn-social:hover:not(:disabled) {
    background: #333;
  }

  .btn-google {
    border-color: #4285f4;
  }

  .btn-github {
    border-color: #444;
  }
}
</style>

