<template>
  <div class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12" data-testid="page-reset-password">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <img :src="logoSrc" alt="synaplan" class="h-12 mx-auto mb-6" />
        <h1 class="text-3xl font-bold txt-primary mb-2">Reset Password</h1>
        <p class="txt-secondary">Enter your new password</p>
      </div>

      <div class="surface-card p-8" data-testid="section-reset-card">
        <div v-if="!success" data-testid="section-reset-form">
          <form @submit.prevent="handleReset" class="space-y-5" data-testid="form-reset-password">
            <div data-testid="field-new-password">
              <label for="password" class="block text-sm font-medium txt-primary mb-2">
                New Password
              </label>
              <input
                id="password"
                v-model="password"
                type="password"
                required
                class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
                :class="{ 'ring-2 ring-red-500': passwordErrors.length > 0 }"
                placeholder="Enter new password"
                data-testid="input-new-password"
              />
              <div v-if="passwordErrors.length > 0" class="mt-2 space-y-1">
                <p v-for="err in passwordErrors" :key="err" class="text-xs text-red-600 dark:text-red-400">• {{ err }}</p>
              </div>
            </div>

            <div data-testid="field-confirm-password">
              <label for="confirmPassword" class="block text-sm font-medium txt-primary mb-2">
                Confirm Password
              </label>
              <input
                id="confirmPassword"
                v-model="confirmPassword"
                type="password"
                required
                class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
                placeholder="Confirm new password"
                data-testid="input-confirm-password"
              />
              <p v-if="password && confirmPassword && password !== confirmPassword" class="text-sm text-yellow-600 dark:text-yellow-400 mt-1" data-testid="text-password-mismatch">
                Passwords do not match
              </p>
            </div>

            <div v-if="error" class="alert-error" data-testid="alert-reset-error">
              <p class="text-sm alert-error-text">{{ error }}</p>
            </div>

            <Button
              type="submit"
              class="w-full btn-primary py-3 rounded-lg font-medium"
              :disabled="loading || password !== confirmPassword"
              data-testid="btn-reset-password"
            >
              <span v-if="loading">Resetting...</span>
              <span v-else>Reset Password</span>
            </Button>
          </form>
        </div>

        <div v-else class="text-center py-8" data-testid="section-reset-success">
          <div class="w-16 h-16 bg-green-100 dark:bg-green-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
          </div>
          <h2 class="text-2xl font-bold txt-primary mb-2">Password Reset!</h2>
          <p class="txt-secondary mb-6">Your password has been successfully reset.</p>
          <router-link to="/login" class="btn-primary px-6 py-3 rounded-lg inline-block" data-testid="link-reset-login">
            Go to Login
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useTheme } from '../composables/useTheme'
import { usePasswordValidation } from '../composables/usePasswordValidation'
import { authApi } from '@/services/api'
import Button from '../components/Button.vue'

const router = useRouter()
const route = useRoute()
const themeStore = useTheme()

const isDark = computed(() => {
  if (themeStore.theme.value === 'dark') return true
  if (themeStore.theme.value === 'light') return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})

const logoSrc = computed(() => isDark.value ? '/synaplan-light.svg' : '/synaplan-dark.svg')

const password = ref('')
const confirmPassword = ref('')
const loading = ref(false)
const error = ref('')
const success = ref(false)
const passwordErrors = ref<string[]>([])
const token = ref('')

onMounted(() => {
  token.value = route.query.token as string || ''
  if (!token.value) {
    error.value = 'Invalid or missing reset token'
  }
})

const handleReset = async () => {
  error.value = ''
  passwordErrors.value = []

  const validation = usePasswordValidation(password.value)
  if (!validation.isValid) {
    passwordErrors.value = validation.errors
    return
  }

  if (password.value !== confirmPassword.value) {
    return
  }

  loading.value = true

  try {
    await authApi.resetPassword(token.value, password.value)
    success.value = true
    setTimeout(() => router.push('/login'), 3000)
  } catch (err: any) {
    error.value = err.response?.data?.error || 'Password reset failed'
  } finally {
    loading.value = false
  }
}
</script>
