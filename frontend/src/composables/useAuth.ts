// synaplan-ui/src/composables/useAuth.ts
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function useAuth() {
  const authStore = useAuthStore()

  return {
    // State
    user: computed(() => authStore.user),
    loading: computed(() => authStore.loading),
    error: computed(() => authStore.error),
    isAuthenticated: computed(() => authStore.isAuthenticated),
    userLevel: computed(() => authStore.userLevel),
    isPro: computed(() => authStore.isPro),
    isAdmin: computed(() => authStore.isAdmin),

    // Actions
    login: authStore.login,
    register: authStore.register,
    logout: authStore.logout,
    refreshUser: authStore.refreshUser,
    checkAuth: authStore.checkAuth,
    clearError: authStore.clearError,
  }
}
