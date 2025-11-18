// synaplan-ui/src/stores/auth.ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/authService'

export interface User {
  id: number
  email: string
  level: string
  roles?: string[]
  created?: string
}

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const token = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  // Computed
  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const userLevel = computed(() => user.value?.level || 'NEW')
  const isPro = computed(() => ['PRO', 'TEAM', 'BUSINESS'].includes(userLevel.value))

  // Actions
  async function login(email: string, password: string, recaptchaToken?: string): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.login(email, password, recaptchaToken)
      
      if (result.success) {
        token.value = authService.getToken()
        user.value = authService.getUser().value
        return true
      } else {
        error.value = result.error || 'Login failed'
        return false
      }
    } catch (err) {
      error.value = 'Network error'
      return false
    } finally {
      loading.value = false
    }
  }

  async function register(email: string, password: string, recaptchaToken?: string): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.register(email, password, recaptchaToken)
      
      if (result.success) {
        token.value = authService.getToken()
        user.value = authService.getUser().value
        return true
      } else {
        error.value = result.error || 'Registration failed'
        return false
      }
    } catch (err) {
      error.value = 'Network error'
      return false
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    loading.value = true
    
    try {
      await authService.logout()
    } finally {
      token.value = null
      user.value = null
      loading.value = false
      error.value = null
    }
  }

  async function refreshUser(): Promise<void> {
    if (!token.value) return

    loading.value = true
    try {
      const currentUser = await authService.getCurrentUser()
      if (currentUser) {
        user.value = currentUser
      } else {
        // Token invalid, logout
        await logout()
      }
    } catch (err) {
      console.error('Failed to refresh user:', err)
      await logout()
    } finally {
      loading.value = false
    }
  }

  async function checkAuth(): Promise<void> {
    const storedToken = localStorage.getItem('auth_token')
    const storedUser = localStorage.getItem('auth_user')

    if (storedToken && storedUser && storedUser !== 'undefined' && storedUser !== 'null') {
      token.value = storedToken
      try {
        user.value = JSON.parse(storedUser)
        // Verify token is still valid
        await refreshUser()
      } catch (err) {
        console.error('Failed to parse stored user:', err)
        localStorage.removeItem('auth_user')
        localStorage.removeItem('auth_token')
        await logout()
      }
    }
  }

  function clearError(): void {
    error.value = null
  }

  // Initialize on store creation
  checkAuth()

  return {
    // State
    user,
    token,
    loading,
    error,
    // Computed
    isAuthenticated,
    userLevel,
    isPro,
    // Actions
    login,
    register,
    logout,
    refreshUser,
    checkAuth,
    clearError,
  }
})

