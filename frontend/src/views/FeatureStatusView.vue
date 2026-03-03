<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-feature-status"
    >
      <div class="max-w-6xl mx-auto space-y-6">
        <!-- Header -->
        <div class="surface-card p-6" data-testid="section-header">
          <div class="flex items-center gap-3 mb-2">
            <Icon icon="mdi:server-network" class="w-8 h-8 text-[var(--brand)]" />
            <h1 class="text-3xl font-bold txt-primary">{{ $t('settings.features.title') }}</h1>
          </div>
          <p class="txt-secondary">{{ $t('settings.features.subtitle') }}</p>
        </div>

        <!-- Loading State -->
        <div
          v-if="isLoadingFeatures"
          class="surface-card p-8 text-center"
          data-testid="state-features-loading"
        >
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto mb-4 txt-secondary" />
          <div class="txt-secondary">{{ $t('settings.features.loading') }}</div>
        </div>

        <!-- Dev-Only State -->
        <div
          v-else-if="isDevOnly"
          class="surface-card p-8 text-center"
          data-testid="state-features-dev-only"
        >
          <Icon icon="mdi:code-braces" class="w-12 h-12 mx-auto mb-4 txt-secondary" />
          <h2 class="text-xl font-semibold txt-primary mb-2">
            {{ $t('settings.features.devOnlyTitle') }}
          </h2>
          <p class="txt-secondary">{{ $t('settings.features.devOnlyMessage') }}</p>
        </div>

        <!-- Error State -->
        <div
          v-else-if="!featuresStatus || !featuresStatus.features"
          class="surface-card p-8 text-center"
          data-testid="state-features-error"
        >
          <div class="txt-secondary mb-4">{{ $t('common.error') }}</div>
          <button
            class="btn-primary px-6 py-2.5 rounded-lg"
            data-testid="btn-retry-features"
            @click="loadFeatures"
          >
            {{ $t('common.retry') }}
          </button>
        </div>

        <!-- Features List (grouped by category) -->
        <template v-else>
          <!-- Summary Card -->
          <div
            class="surface-card p-6 border-l-4"
            :class="
              featuresStatus.summary.all_ready
                ? 'border-[var(--status-success)]'
                : 'border-[var(--status-warning)]'
            "
            data-testid="section-features-summary"
          >
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-4">
                <div class="text-4xl">
                  {{ featuresStatus.summary.all_ready ? '✅' : '⚠️' }}
                </div>
                <div>
                  <h3 class="text-xl font-bold txt-primary mb-1">
                    {{
                      featuresStatus.summary.all_ready
                        ? $t('settings.features.allOperational')
                        : $t('settings.features.title')
                    }}
                  </h3>
                  <p class="txt-secondary text-sm">
                    {{
                      $t('settings.features.healthSummary', {
                        healthy: featuresStatus.summary.healthy,
                        total: featuresStatus.summary.total,
                      })
                    }}
                  </p>
                </div>
              </div>

              <div
                :class="[
                  'px-5 py-2.5 rounded-lg text-sm font-semibold shadow-sm',
                  featuresStatus.summary.all_ready
                    ? 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
                    : 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]',
                ]"
              >
                {{
                  featuresStatus.summary.all_ready
                    ? $t('settings.features.ready')
                    : $t('settings.features.issuesDetected')
                }}
              </div>
            </div>
          </div>

          <!-- Group features by category -->
          <div
            v-for="(category, categoryName) in featuresByCategory"
            :key="categoryName"
            class="space-y-3"
            data-testid="section-feature-category"
          >
            <!-- Category Header -->
            <div class="flex items-center gap-3 px-2 mb-4">
              <h2 class="text-xl font-semibold txt-primary">{{ categoryName }}</h2>
              <div class="h-px flex-1 bg-[var(--divider)]"></div>
            </div>

            <!-- Features in this category -->
            <div
              v-for="feature in category"
              :key="feature.id"
              class="surface-card p-5 hover:shadow-md transition-shadow"
              data-testid="item-feature"
            >
              <!-- Feature Header -->
              <div class="flex items-start justify-between gap-4 mb-3">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap mb-2">
                    <h3 class="text-base font-semibold txt-primary">{{ feature.name }}</h3>

                    <!-- Version Badge -->
                    <span
                      v-if="feature.version"
                      class="px-2.5 py-1 rounded-md text-xs font-mono font-semibold bg-[var(--status-info)] text-white shadow-sm"
                    >
                      v{{ feature.version }}
                    </span>

                    <!-- Models Count Badge -->
                    <span
                      v-if="feature.models_available !== undefined && feature.models_available > 0"
                      class="px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--brand)] text-white shadow-sm"
                    >
                      {{ feature.models_available }}
                      {{
                        feature.models_available !== 1
                          ? $t('settings.features.models')
                          : $t('settings.features.model')
                      }}
                    </span>
                  </div>

                  <!-- Feature Description -->
                  <p class="txt-secondary text-sm">{{ feature.message }}</p>

                  <!-- URL if available -->
                  <code
                    v-if="feature.url"
                    class="text-xs txt-secondary font-mono opacity-60 mt-1 inline-block"
                    >{{ feature.url }}</code
                  >
                </div>

                <!-- Status Badge -->
                <span
                  :class="[
                    'px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap flex-shrink-0',
                    getStatusClass(feature.status),
                  ]"
                >
                  {{ feature.status }}
                </span>
              </div>

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
                          : 'surface-chip txt-secondary',
                      ]"
                    >
                      {{
                        envVar.set ? $t('settings.features.set') : $t('settings.features.notSet')
                      }}
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
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import {
  getFeaturesStatus,
  DevOnlyFeatureError,
  type FeaturesStatus,
  type Feature,
} from '@/services/featuresService'
const featuresStatus = ref<FeaturesStatus | null>(null)
const isLoadingFeatures = ref(false)
const isDevOnly = ref(false)

// Group features by category with custom ordering
const featuresByCategory = computed(() => {
  if (!featuresStatus.value || !featuresStatus.value.features) return {}

  const grouped: Record<string, Feature[]> = {}

  Object.values(featuresStatus.value.features).forEach((feature) => {
    const category = feature.category || 'Other'
    if (!grouped[category]) {
      grouped[category] = []
    }
    grouped[category].push(feature)
  })

  // Define category order
  const categoryOrder = [
    'AI Features',
    'AI Providers',
    'Processing Services',
    'Infrastructure',
    'Other',
  ]

  // Sort grouped object by category order
  const sorted: Record<string, Feature[]> = {}
  categoryOrder.forEach((cat) => {
    if (grouped[cat]) {
      sorted[cat] = grouped[cat]
    }
  })

  // Add any remaining categories not in the order
  Object.keys(grouped).forEach((cat) => {
    if (!sorted[cat]) {
      sorted[cat] = grouped[cat]
    }
  })

  return sorted
})

const getStatusClass = (status: string) => {
  switch (status) {
    case 'healthy':
      return 'bg-[var(--status-success)] text-white shadow-sm'
    case 'active':
      return 'bg-[var(--status-info)] text-white shadow-sm'
    case 'unhealthy':
      return 'bg-[var(--status-error)] text-white shadow-sm'
    case 'disabled':
    default:
      return 'bg-[var(--status-neutral)] text-white shadow-sm'
  }
}

const loadFeatures = async () => {
  isLoadingFeatures.value = true
  featuresStatus.value = null
  isDevOnly.value = false

  try {
    const data = await getFeaturesStatus()
    featuresStatus.value = data
  } catch (error) {
    if (error instanceof DevOnlyFeatureError) {
      isDevOnly.value = true
    } else {
      console.error('Failed to load features:', error)
    }
    featuresStatus.value = null
  } finally {
    isLoadingFeatures.value = false
  }
}

onMounted(() => {
  loadFeatures()
})
</script>
