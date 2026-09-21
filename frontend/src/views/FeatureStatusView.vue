<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-feature-status"
    >
      <div class="max-w-6xl mx-auto space-y-6">
        <!-- Header -->
        <PageHeader
          :title="$t('settings.features.title')"
          :subtitle="$t('settings.features.subtitle')"
          icon="mdi:server-network"
          data-testid="section-header"
        />

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
            {{ $t('settings.features.adminOnlyTitle') }}
          </h2>
          <p class="txt-secondary">{{ $t('settings.features.adminOnlyMessage') }}</p>
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

          <!-- Declared feature modules (absent on a backend without the module registry) -->
          <FeatureModulesSection
            v-if="featuresStatus.modules && featuresStatus.modules.length > 0"
            :modules="featuresStatus.modules"
          />

          <!-- File-work compute sidecar (absent on a backend without the compute entry) -->
          <ComputeStatusCard
            v-if="featuresStatus.compute"
            :compute="featuresStatus.compute"
            @retry="loadFeatures"
          />

          <div
            v-if="featureCategoryItems.length > 1"
            class="flex items-center justify-between gap-3 flex-wrap"
          >
            <SectionJumpNav
              :items="featureCategoryItems"
              :nav-label="$t('admin.config.accordion.jumpTo')"
              @select="jumpToFeatureCategory"
            />
            <button
              type="button"
              class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
              data-testid="btn-features-accordion-toggle-all"
              @click="
                allFeatureCategoriesOpen
                  ? collapseAllFeatureCategories()
                  : expandAllFeatureCategories()
              "
            >
              {{
                allFeatureCategoriesOpen
                  ? $t('admin.config.accordion.collapseAll')
                  : $t('admin.config.accordion.expandAll')
              }}
            </button>
          </div>

          <!-- Group features by category -->
          <AccordionStack testid="features-accordion">
            <AccordionSection
              v-for="(category, categoryName) in featuresByCategory"
              :key="categoryName"
              :panel-id="`feature-category-${categorySlug(String(categoryName))}`"
              :title="String(categoryName)"
              :open="isFeatureCategoryOpen(String(categoryName))"
              :header-testid="`btn-feature-category-${categorySlug(String(categoryName))}`"
              @toggle="toggleFeatureCategory(String(categoryName))"
            >
              <div class="space-y-3" data-testid="section-feature-category">
                <div
                  v-for="feature in category"
                  :key="feature.id"
                  class="surface-card p-5 hover:shadow-md transition-shadow"
                  data-testid="item-feature"
                >
                  <div class="flex items-start justify-between gap-4 mb-3">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 flex-wrap mb-2">
                        <h4 class="text-base font-semibold txt-primary">{{ feature.name }}</h4>
                        <span
                          v-if="feature.version"
                          class="px-2.5 py-1 rounded-md text-xs font-mono font-semibold bg-[var(--status-info)] text-white shadow-sm"
                        >
                          v{{ feature.version }}
                        </span>
                        <span
                          v-if="
                            feature.models_available !== undefined && feature.models_available > 0
                          "
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
                      <p class="txt-secondary text-sm">{{ feature.message }}</p>
                      <code
                        v-if="feature.url"
                        class="text-xs txt-secondary font-mono opacity-60 mt-1 inline-block"
                        >{{ feature.url }}</code
                      >
                    </div>
                    <span
                      :class="[
                        'px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wide whitespace-nowrap flex-shrink-0',
                        getStatusClass(feature.status),
                      ]"
                    >
                      {{ feature.status }}
                    </span>
                  </div>
                  <div v-if="feature.setup_required && feature.env_vars" class="mt-4 space-y-3">
                    <div class="flex items-center gap-2 mb-3">
                      <div class="text-sm font-medium txt-primary">
                        {{ $t('settings.features.requiredConfig') }}
                      </div>
                    </div>
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
                            envVar.set
                              ? $t('settings.features.set')
                              : $t('settings.features.notSet')
                          }}
                        </span>
                      </div>
                      <p class="text-xs txt-secondary">{{ envVar.hint }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </AccordionSection>
          </AccordionStack>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import SectionJumpNav from '@/components/SectionJumpNav.vue'
import FeatureModulesSection from '@/components/admin/FeatureModulesSection.vue'
import ComputeStatusCard from '@/components/admin/ComputeStatusCard.vue'
import { useAccordion } from '@/composables/useAccordion'
import {
  getFeaturesStatus,
  FeatureStatusForbiddenError,
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

function categorySlug(name: string): string {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

const featureCategoryIds = computed(() => Object.keys(featuresByCategory.value))
const featureCategoryItems = computed(() =>
  featureCategoryIds.value.map((id) => ({ id, label: id }))
)
const {
  isOpen: isFeatureCategoryOpen,
  toggle: toggleFeatureCategory,
  open: openFeatureCategory,
  expandAll: expandAllFeatureCategories,
  collapseAll: collapseAllFeatureCategories,
  allOpen: allFeatureCategoriesOpen,
} = useAccordion(featureCategoryIds)

async function jumpToFeatureCategory(categoryName: string) {
  openFeatureCategory(categoryName)
  await nextTick()
  document
    .getElementById(`feature-category-${categorySlug(categoryName)}`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

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
    if (error instanceof FeatureStatusForbiddenError) {
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
