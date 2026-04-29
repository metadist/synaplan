// synaplan-ui/src/composables/useAuth.ts
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

export function useAuth() {
  const authStore = useAuthStore()

  return {
    // State
    user: computed(() => authStore.user),
    impersonator: computed(() => authStore.impersonator),
    loading: computed(() => authStore.loading),
    error: computed(() => authStore.error),
    isAuthenticated: computed(() => authStore.isAuthenticated),
    userLevel: computed(() => authStore.userLevel),
    isPro: computed(() => authStore.isPro),
    isTeam: computed(() => authStore.isTeam),
    isAdmin: computed(() => authStore.isAdmin),
    isImpersonating: computed(() => authStore.isImpersonating),

    // Actions
    login: authStore.login,
    register: authStore.register,
    logout: authStore.logout,
    refreshUser: authStore.refreshUser,
    checkAuth: authStore.checkAuth,
    startImpersonation: authStore.startImpersonation,
    stopImpersonation: authStore.stopImpersonation,
    clearError: authStore.clearError,
  }
}
