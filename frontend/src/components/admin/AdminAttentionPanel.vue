<template>
  <section class="space-y-4" data-testid="section-admin-attention">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <RouterLink
        v-for="card in cards"
        :key="card.id"
        :to="card.to"
        class="surface-card rounded-2xl p-5 flex flex-col gap-1 hover:ring-2 hover:ring-[var(--brand)]/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] transition"
        :data-testid="`card-admin-${card.id}`"
      >
        <span class="flex items-center justify-between text-sm txt-secondary">
          {{ card.label }}
          <component :is="card.icon" class="w-5 h-5" aria-hidden="true" />
        </span>
        <span class="text-3xl font-bold txt-primary">{{ card.value ?? '–' }}</span>
        <span class="text-xs txt-secondary">{{ card.hint }}</span>
      </RouterLink>
    </div>

    <div class="surface-card rounded-2xl p-5" data-testid="section-needs-attention">
      <h2 class="text-lg font-semibold txt-primary mb-3">
        {{ $t('admin.attention.title') }}
      </h2>
      <div
        v-if="loadFailed"
        class="flex flex-wrap items-center justify-between gap-3"
        data-testid="attention-load-error"
      >
        <p class="text-sm txt-secondary">{{ $t('admin.attention.loadFailed') }}</p>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 text-sm font-medium"
          data-testid="btn-attention-retry"
          @click="load"
        >
          {{ $t('common.retry') }}
        </button>
      </div>
      <p v-else-if="loading" class="text-sm txt-secondary">{{ $t('common.loading') }}</p>
      <p
        v-else-if="problems.length === 0"
        class="text-sm txt-secondary flex items-center gap-2"
        data-testid="attention-all-good"
      >
        <CheckCircleIcon class="w-5 h-5 text-green-600 dark:text-green-400" aria-hidden="true" />
        {{ $t('admin.attention.allGood') }}
      </p>
      <ul v-else class="divide-y divide-light-border/20 dark:divide-dark-border/10">
        <li
          v-for="problem in problems"
          :key="problem.id"
          class="flex flex-wrap items-center justify-between gap-3 py-3"
          data-testid="item-needs-attention"
        >
          <span class="flex items-start gap-2 text-sm txt-primary min-w-0">
            <ExclamationTriangleIcon
              class="w-5 h-5 shrink-0 text-amber-600 dark:text-amber-400"
              aria-hidden="true"
            />
            <span class="min-w-0 break-words">{{ problem.text }}</span>
          </span>
          <RouterLink
            :to="problem.to"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            data-testid="link-needs-attention-fix"
          >
            {{ problem.action }}
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  CheckCircleIcon,
  CpuChipIcon,
  ExclamationTriangleIcon,
  PowerIcon,
  UserGroupIcon,
  UserPlusIcon,
} from '@heroicons/vue/24/outline'
import { getFeaturesStatus, type FeaturesStatus } from '@/services/featuresService'
import { modelStatusApi } from '@/services/api/adminModelStatusApi'
import { setModelsNeedingAttention } from '@/composables/useNavItems'

const props = defineProps<{
  totalUsers: number | null
  /** Sign-ups in the period the registration chart shows. */
  signups: number | null
}>()

interface Card {
  id: string
  label: string
  value: number | null
  hint: string
  to: string
  icon: Component
}

interface Problem {
  id: string
  text: string
  action: string
  to: string
}

const FEATURES_PATH = '/admin/features'
const HEALTH_PATH = '/admin/setup?tab=health'

const { t, te } = useI18n()

const features = ref<FeaturesStatus | null>(null)
const modelsNeedingAttention = ref<number | null>(null)
const loading = ref(false)
const loadFailed = ref(false)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    const [featureStatus, modelStatus] = await Promise.all([
      getFeaturesStatus(),
      modelStatusApi.getStatus(),
    ])
    features.value = featureStatus
    modelsNeedingAttention.value = modelStatus.summary.needsAttention
    setModelsNeedingAttention(modelStatus.summary.needsAttention)
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

const disabledFeatures = computed(() =>
  features.value ? Object.values(features.value.features).filter((f) => !f.enabled).length : null
)

const cards = computed<Card[]>(() => [
  {
    id: 'people',
    label: t('admin.attention.cards.people'),
    value: props.totalUsers,
    hint: t('admin.attention.cards.peopleHint'),
    to: '/admin/people',
    icon: UserGroupIcon,
  },
  {
    id: 'signups',
    label: t('admin.attention.cards.signups'),
    value: props.signups,
    hint: t('admin.attention.cards.signupsHint'),
    to: '/admin/people',
    icon: UserPlusIcon,
  },
  {
    id: 'features',
    label: t('admin.attention.cards.features'),
    value: disabledFeatures.value,
    hint: t('admin.attention.cards.featuresHint'),
    to: FEATURES_PATH,
    icon: PowerIcon,
  },
  {
    id: 'models',
    label: t('admin.attention.cards.models'),
    value: modelsNeedingAttention.value,
    hint: t('admin.attention.cards.modelsHint'),
    to: HEALTH_PATH,
    icon: CpuChipIcon,
  },
])

const problems = computed<Problem[]>(() => {
  const list: Problem[] = []
  if (modelsNeedingAttention.value) {
    list.push({
      id: 'models',
      text: t('admin.attention.models', { count: modelsNeedingAttention.value }),
      action: t('admin.attention.openModelHealth'),
      to: HEALTH_PATH,
    })
  }
  for (const feature of Object.values(features.value?.features ?? {})) {
    if (feature.enabled && feature.status === 'unhealthy') {
      list.push({
        id: `feature-${feature.id}`,
        text: t('admin.attention.unhealthy', { name: feature.name }),
        action: t('admin.attention.openStatus'),
        to: FEATURES_PATH,
      })
    }
  }
  // Sidecar feature rows are derived from their module (`office_convert` → `office-convert`).
  const listedFeatures = new Set(list.map((problem) => problem.id))
  for (const module of features.value?.modules ?? []) {
    if (module.state !== 'needs_setup') continue
    if (listedFeatures.has(`feature-${module.id.replaceAll('_', '-')}`)) continue
    const name = te(module.label_key) ? t(module.label_key) : module.id
    list.push({
      id: `module-${module.id}`,
      text: t('admin.attention.needsSetup', { name }),
      action: t('admin.attention.openStatus'),
      to: FEATURES_PATH,
    })
  }
  return list
})

onMounted(load)
</script>
