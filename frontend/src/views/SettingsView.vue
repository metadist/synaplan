<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin" data-testid="page-settings">
      <div class="max-w-4xl mx-auto space-y-6">
        
        <!-- Header -->
        <div class="surface-card p-6" data-testid="section-header">
          <h1 class="text-2xl font-semibold txt-primary mb-1">⚙️ {{ $t('settings.title') }}</h1>
          <p class="txt-secondary text-sm">{{ $t('settings.subtitle') }}</p>
        </div>

        <!-- General Settings Content -->
        <div class="space-y-6" data-testid="section-general-settings">
          <!-- App Mode -->
          <div class="surface-card p-6" data-testid="section-app-mode">
            <h2 class="text-lg font-semibold txt-primary mb-2">{{ $t('settings.appMode.title') }}</h2>
            <p class="txt-secondary text-sm mb-4">{{ $t('settings.appMode.description') }}</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <button
                @click="appModeStore.setMode('easy')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all text-left',
                  appModeStore.isEasyMode
                    ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                    : 'border-light-border/30 dark:border-dark-border/20 hover-surface'
                ]"
                data-testid="btn-mode-easy"
              >
                <div class="font-semibold txt-primary mb-1">{{ $t('settings.appMode.easy') }}</div>
                <div class="text-sm txt-secondary">{{ $t('settings.appMode.easyDesc') }}</div>
              </button>
              
              <button
                @click="appModeStore.setMode('advanced')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all text-left',
                  appModeStore.isAdvancedMode
                    ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                    : 'border-light-border/30 dark:border-dark-border/20 hover-surface'
                ]"
                data-testid="btn-mode-advanced"
              >
                <div class="font-semibold txt-primary mb-1">{{ $t('settings.appMode.advanced') }}</div>
                <div class="text-sm txt-secondary">{{ $t('settings.appMode.advancedDesc') }}</div>
              </button>
            </div>
          </div>

          <!-- Theme Settings -->
          <div class="surface-card p-6" data-testid="section-theme-settings">
            <h2 class="text-lg font-semibold txt-primary mb-2">{{ $t('settings.theme.title') }}</h2>
            <p class="txt-secondary text-sm mb-4">{{ $t('settings.theme.description') }}</p>
            
            <div class="grid grid-cols-3 gap-3">
              <button
                @click="setTheme('light')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'light'
                    ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                    : 'border-light-border/30 dark:border-dark-border/20 hover-surface'
                ]"
                data-testid="btn-theme-light"
              >
                <SunIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary text-center">{{ $t('settings.theme.light') }}</div>
              </button>
              
              <button
                @click="setTheme('dark')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'dark'
                    ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                    : 'border-light-border/30 dark:border-dark-border/20 hover-surface'
                ]"
                data-testid="btn-theme-dark"
              >
                <MoonIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary text-center">{{ $t('settings.theme.dark') }}</div>
              </button>
              
              <button
                @click="setTheme('system')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'system'
                    ? 'border-[var(--brand)] bg-[var(--brand-alpha-light)]'
                    : 'border-light-border/30 dark:border-dark-border/20 hover-surface'
                ]"
                data-testid="btn-theme-system"
              >
                <ComputerDesktopIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary text-center">{{ $t('settings.theme.system') }}</div>
              </button>
            </div>
          </div>

          <!-- Account Info -->
          <div class="surface-card p-6" data-testid="section-account-info">
            <h2 class="text-lg font-semibold txt-primary mb-4">{{ $t('settings.account.title') }}</h2>
            <div class="space-y-4">
              <div data-testid="text-account-email">
                <label class="block text-sm font-medium txt-secondary mb-1">{{ $t('settings.account.email') }}</label>
                <div class="txt-primary">{{ authStore.user?.email || 'Not logged in' }}</div>
              </div>
              <div data-testid="text-account-level">
                <label class="block text-sm font-medium txt-secondary mb-1">{{ $t('settings.account.userLevel') }}</label>
                <div class="txt-primary">{{ authStore.user?.level || 'N/A' }}</div>
              </div>
            </div>
          </div>

          <!-- Logout -->
          <div class="surface-card p-6" data-testid="section-logout">
            <button
              @click="handleLogout"
              class="btn-primary px-6 py-2.5 rounded-lg w-full"
              data-testid="btn-logout"
            >
              {{ $t('settings.logout') }}
            </button>
          </div>
        </div>

      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppModeStore } from '@/stores/appMode'
import { useTheme } from '@/composables/useTheme'
import MainLayout from '@/components/MainLayout.vue'
import { SunIcon, MoonIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const router = useRouter()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const { theme, setTheme } = useTheme()

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>
