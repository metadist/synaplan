<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin" data-testid="page-settings">
      <div class="max-w-4xl mx-auto space-y-6">
        
        <!-- Header -->
        <div class="surface-card p-6" data-testid="section-header">
          <h1 class="text-2xl font-semibold txt-primary mb-1">⚙️ {{ $t('settings.title') }}</h1>
          <p class="txt-secondary text-sm">{{ $t('settings.subtitle') }}</p>
        </div>

        <!-- Tabs -->
        <div class="surface-card p-2" data-testid="section-tabs">
          <div class="flex gap-2">
            <button
              @click="activeTab = 'general'"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all',
                activeTab === 'general'
                  ? 'bg-[var(--brand)] text-white'
                  : 'txt-secondary hover-surface'
              ]"
              data-testid="tab-general"
            >
              {{ $t('settings.tabs.general') }}
            </button>
            <!-- Features Tab: nur in Development Mode -->
            <button
              v-if="isDevelopment"
              @click="activeTab = 'features'"
              :class="[
                'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all relative',
                activeTab === 'features'
                  ? 'bg-[var(--brand)] text-white'
                  : 'txt-secondary hover-surface'
              ]"
              data-testid="tab-features"
            >
              {{ $t('settings.tabs.features') }}
              <span 
                v-if="disabledFeaturesCount > 0"
                class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-yellow-500 text-white text-xs flex items-center justify-center"
              >
                {{ disabledFeaturesCount }}
              </span>
            </button>
          </div>
        </div>

        <!-- General Tab Content -->
        <div v-if="activeTab === 'general'" class="space-y-6" data-testid="section-general-tab">
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
                <div class="txt-primary">{{ authStore.user?.userLevel || 'N/A' }}</div>
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

        <!-- Features Tab Content -->
        <div v-if="activeTab === 'features'" class="space-y-6" data-testid="section-features-tab">
          <!-- Loading State -->
          <div v-if="isLoadingFeatures" class="surface-card p-8 text-center" data-testid="state-features-loading">
            <div class="txt-secondary">{{ $t('settings.features.loading') }}</div>
          </div>

          <!-- Error State -->
          <div v-else-if="!featuresStatus || !featuresStatus.features" class="surface-card p-8 text-center" data-testid="state-features-error">
            <div class="txt-secondary mb-4">{{ $t('common.error') }}</div>
            <button @click="loadFeatures" class="btn-primary px-6 py-2.5 rounded-lg" data-testid="btn-retry-features">
              {{ $t('common.retry') }}
            </button>
          </div>

          <!-- Features List (grouped by category) -->
          <template v-else>
            <!-- Summary Card -->
            <div class="surface-card p-6" data-testid="section-features-summary">
              <div class="flex items-center justify-between">
                <div>
                  <h3 class="text-lg font-semibold txt-primary mb-1">System Status</h3>
                  <p class="txt-secondary text-sm">
                    {{ featuresStatus.summary.healthy }} of {{ featuresStatus.summary.total }} services are healthy
                  </p>
                </div>
                <div 
                  :class="[
                    'px-4 py-2 rounded-full text-sm font-medium',
                    featuresStatus.summary.all_ready
                      ? 'bg-[var(--brand-alpha-light)] text-[var(--brand)]'
                      : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200'
                  ]"
                >
                  {{ featuresStatus.summary.all_ready ? '✓ All Systems Operational' : '⚠ Some Issues Detected' }}
                </div>
              </div>
            </div>

            <!-- Group features by category -->
            <div v-for="(category, categoryName) in featuresByCategory" :key="categoryName" class="space-y-4" data-testid="section-feature-category">
              <!-- Category Header -->
              <h2 class="text-xl font-semibold txt-primary px-2">{{ categoryName }}</h2>
              
              <!-- Features in this category -->
              <div
                v-for="feature in category"
                :key="feature.id"
                class="surface-card p-6"
                data-testid="item-feature"
              >
                <!-- Feature Header -->
                <div class="flex items-start justify-between mb-3">
                  <div class="flex items-center gap-3">
                    <h3 class="text-lg font-semibold txt-primary">{{ feature.name }}</h3>
                    <span
                      :class="[
                        'px-3 py-1 rounded-full text-xs font-medium',
                        getStatusClass(feature.status)
                      ]"
                    >
                      {{ feature.status }}
                    </span>
                  </div>
                  
                  <!-- URL if available -->
                  <code v-if="feature.url" class="text-xs txt-secondary">{{ feature.url }}</code>
                </div>
                
                <!-- Feature Description -->
                <p class="txt-secondary text-sm mb-4">{{ feature.message }}</p>

                <!-- Setup Instructions (nur wenn Setup erforderlich) -->
                <div v-if="feature.setup_required && feature.env_vars" class="mt-4 space-y-3">
                  <div class="flex items-center gap-2 mb-3">
                    <div class="text-sm font-medium txt-primary">
                      {{ $t('settings.features.requiredConfig') }}
                    </div>
                  </div>
                  
                  <!-- ENV Variables -->
                  <div
                    v-for="(envVar, key) in feature.env_vars"
                    :key="key"
                    class="surface-elevated p-4 space-y-2"
                    data-testid="item-env-var"
                  >
                    <div class="flex items-center justify-between gap-3">
                      <code class="text-sm font-mono txt-primary">{{ key }}</code>
                      <span
                        :class="[
                          'px-2 py-1 rounded-full text-xs font-medium',
                          envVar.set 
                            ? 'bg-[var(--brand-alpha-light)] text-[var(--brand)]' 
                            : 'surface-chip txt-secondary'
                        ]"
                      >
                        {{ envVar.set ? $t('settings.features.set') : $t('settings.features.notSet') }}
                      </span>
                    </div>
                    <p class="text-xs txt-secondary">{{ envVar.hint }}</p>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppModeStore } from '@/stores/appMode'
import { useTheme } from '@/composables/useTheme'
import { getFeaturesStatus, type FeaturesStatus, type Feature } from '@/services/featuresService'
import MainLayout from '@/components/MainLayout.vue'
import { SunIcon, MoonIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const { theme, setTheme } = useTheme()

// Check if in development mode
const isDevelopment = import.meta.env.DEV

// Tabs
const activeTab = ref<string>('general')
const featuresStatus = ref<FeaturesStatus | null>(null)
const isLoadingFeatures = ref(false)

const disabledFeaturesCount = computed(() => {
  if (!featuresStatus.value || !featuresStatus.value.features) return 0
  return Object.values(featuresStatus.value.features).filter(f => !f.enabled).length
})

// Group features by category
const featuresByCategory = computed(() => {
  if (!featuresStatus.value || !featuresStatus.value.features) return {}
  
  const grouped: Record<string, Feature[]> = {}
  
  Object.values(featuresStatus.value.features).forEach(feature => {
    const category = feature.category || 'Other'
    if (!grouped[category]) {
      grouped[category] = []
    }
    grouped[category].push(feature)
  })
  
  return grouped
})

// Get status class based on status
const getStatusClass = (status: string) => {
  switch (status) {
    case 'healthy':
    case 'active':
      return 'bg-[var(--brand-alpha-light)] text-[var(--brand)]'
    case 'unhealthy':
      return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200'
    case 'disabled':
    default:
      return 'surface-chip txt-secondary'
  }
}

// Load features when tab changes or on mount
const loadFeatures = async () => {
  // Reset to allow retry
  isLoadingFeatures.value = true
  featuresStatus.value = null
  
  try {
    const data = await getFeaturesStatus()
    console.log('Features loaded:', data)
    featuresStatus.value = data
  } catch (error) {
    console.error('Failed to load features:', error)
    featuresStatus.value = null
  } finally {
    isLoadingFeatures.value = false
  }
}

// Watch for tab query parameter
watch(() => route.query.tab, (newTab) => {
  if (newTab === 'features') {
    activeTab.value = 'features'
    if (!featuresStatus.value && !isLoadingFeatures.value) {
      loadFeatures()
    }
  }
}, { immediate: true })

// Watch for active tab changes
watch(activeTab, (newTab) => {
  if (newTab === 'features' && !featuresStatus.value && !isLoadingFeatures.value) {
    loadFeatures()
  }
})

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>
