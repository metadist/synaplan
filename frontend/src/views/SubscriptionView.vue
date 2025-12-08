<template>
  <MainLayout data-testid="page-subscription">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-6xl mx-auto space-y-8">
        
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
        </div>

        <!-- Main Content -->
        <template v-else>
          <!-- Header -->
          <div class="text-center mb-12">
            <h1 class="text-4xl font-bold txt-primary mb-3">
              {{ hasActivePlan ? $t('subscription.manage.title') : $t('subscription.chooseYourPlan') }}
            </h1>
            <p class="txt-secondary text-lg">
              {{ hasActivePlan ? $t('subscription.manage.subtitle') : $t('subscription.subtitle') }}
            </p>
          </div>

          <!-- Current Subscription Info (if active) -->
          <div v-if="hasActivePlan" class="surface-card rounded-xl p-6 max-w-2xl mx-auto mb-8">
            <div class="flex items-center justify-between flex-wrap gap-4">
              <div class="flex items-center gap-4">
                <Icon icon="mdi:crown" class="w-10 h-10 text-yellow-500" />
                <div>
                  <div class="flex items-center gap-3 mb-1 flex-wrap">
                    <span class="text-lg font-bold txt-primary">{{ $t('subscription.manage.currentPlanLabel') }}</span>
                    <span :class="getLevelBadgeClass(currentLevel || 'NEW')">{{ currentLevel }}</span>
                    <span v-if="subscriptionStatus?.hasSubscription" :class="getStatusBadgeClass(subscriptionStatus.status || 'active')">
                      {{ getStatusText(subscriptionStatus.status || 'active') }}
                    </span>
                  </div>
                  <p v-if="subscriptionStatus?.cancelAt" class="text-amber-500 text-sm font-medium">
                    <Icon icon="mdi:alert" class="w-4 h-4 inline mr-1" />
                    {{ $t('subscription.manage.cancelDate') }}: {{ formatDate(subscriptionStatus.cancelAt) }}
                  </p>
                  <p v-else-if="subscriptionStatus?.nextBilling" class="txt-secondary text-sm">
                    {{ $t('subscription.manage.nextBilling') }}: {{ formatDate(subscriptionStatus.nextBilling) }}
                  </p>
                  <p v-if="isHighestPlan && !subscriptionStatus?.cancelAt" class="txt-secondary text-sm mt-1">
                    {{ $t('subscription.highestPlan') }}
                  </p>
                </div>
              </div>
              <button
                v-if="subscriptionStatus?.hasSubscription"
                @click="openBillingPortal"
                :disabled="isProcessing || !stripeConfigured"
                class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium"
                data-testid="btn-open-portal"
              >
                <Icon v-if="isProcessing" icon="mdi:loading" class="w-4 h-4 animate-spin inline mr-2" />
                {{ $t('subscription.manage.openPortal') }}
              </button>
            </div>
          </div>

          <!-- Stripe Not Configured Warning -->
          <div v-if="!stripeConfigured" class="alert-warning max-w-2xl mx-auto mb-8">
            <div class="flex items-start gap-3">
              <Icon icon="mdi:alert-circle" class="w-6 h-6 flex-shrink-0" />
              <div>
                <p class="font-semibold alert-warning-text mb-2">{{ $t('subscription.unavailable') }}</p>
                <p class="text-sm alert-warning-text">{{ $t('subscription.unavailableDesc') }}</p>
              </div>
            </div>
          </div>

          <!-- Plans Grid -->
          <div v-if="stripeConfigured" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div
              v-for="plan in plans"
              :key="plan.id"
              class="surface-card rounded-xl p-8 flex flex-col transition-shadow"
              :class="[
                plan.id === 'TEAM' && !isCurrentPlan(plan.id) ? 'border-2 border-[var(--brand)] relative hover:shadow-xl' : '',
                isCurrentPlan(plan.id) ? 'ring-2 ring-green-500 relative' : 'hover:shadow-xl',
                isLowerPlan(plan.id) ? 'opacity-60' : ''
              ]"
              data-testid="card-plan"
            >
              <!-- Current Plan Badge -->
              <div
                v-if="isCurrentPlan(plan.id)"
                class="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-green-500 text-white px-4 py-1 rounded-full text-sm font-semibold shadow-lg"
              >
                {{ $t('subscription.currentPlan') }}
              </div>
              <!-- Recommended Badge (only if not current and not lower) -->
              <div
                v-else-if="plan.id === 'TEAM' && !isLowerPlan(plan.id)"
                class="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-1 rounded-full text-sm font-semibold shadow-lg"
              >
                {{ $t('subscription.recommended') }}
              </div>

              <!-- Plan Header -->
              <div class="mb-6">
                <h3 class="text-2xl font-bold txt-primary mb-2">{{ $t(`subscription.plans.${plan.id.toLowerCase()}`) }}</h3>
                <div class="flex items-baseline gap-1">
                  <span class="text-4xl font-bold txt-primary">€{{ plan.price }}</span>
                  <span class="txt-secondary">/{{ $t(`subscription.per${capitalize(plan.interval)}`) }}</span>
                </div>
              </div>

              <!-- Features List -->
              <ul class="space-y-3 mb-8 flex-grow">
                <li
                  v-for="(feature, index) in plan.features"
                  :key="index"
                  class="flex items-start gap-3"
                >
                  <Icon icon="mdi:check-circle" class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                  <span class="txt-secondary text-sm">{{ feature }}</span>
                </li>
              </ul>

              <!-- CTA Button -->
              <button
                v-if="isCurrentPlan(plan.id)"
                disabled
                class="w-full py-3 rounded-lg font-semibold bg-green-500/20 text-green-600 dark:text-green-400 cursor-default"
              >
                <Icon icon="mdi:check" class="w-5 h-5 inline mr-2" />
                {{ $t('subscription.currentPlan') }}
              </button>
              <button
                v-else-if="isLowerPlan(plan.id)"
                disabled
                class="w-full py-3 rounded-lg font-semibold surface-chip txt-secondary cursor-not-allowed"
              >
                {{ $t('subscription.includedInCurrent') }}
              </button>
              <button
                v-else
                @click="selectPlan(plan.id)"
                :disabled="isProcessing"
                :class="[
                  'w-full py-3 rounded-lg font-semibold transition-all',
                  plan.id === 'TEAM'
                    ? 'bg-gradient-to-r from-blue-600 to-purple-600 text-white hover:shadow-lg'
                    : 'btn-primary'
                ]"
                :data-testid="`btn-select-${plan.id.toLowerCase()}`"
              >
                {{ isProcessing ? $t('subscription.processing') : (hasActivePlan ? $t('subscription.upgrade') : $t('subscription.selectPlan')) }}
              </button>
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
import { useI18n } from 'vue-i18n'
import { subscriptionApi, type SubscriptionPlan, type SubscriptionStatus } from '@/services/api/subscriptionApi'
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/components/MainLayout.vue'

const { t } = useI18n()
const authStore = useAuthStore()
const plans = ref<SubscriptionPlan[]>([])
const loading = ref(false)
const isProcessing = ref(false)
const stripeConfigured = ref(true)
const subscriptionStatus = ref<SubscriptionStatus | null>(null)

const currentLevel = computed(() => authStore.user?.level)
const hasActivePlan = computed(() => {
  return currentLevel.value && ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'].includes(currentLevel.value)
})

// Plan hierarchy for upgrade logic (ADMIN is special - unlimited, not a purchasable plan)
const planHierarchy = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN']

const isHighestPlan = computed(() => {
  return currentLevel.value === 'BUSINESS' || currentLevel.value === 'ADMIN'
})

function isCurrentPlan(planId: string): boolean {
  return currentLevel.value === planId
}

function isLowerPlan(planId: string): boolean {
  if (!currentLevel.value) return false
  const currentIndex = planHierarchy.indexOf(currentLevel.value)
  const planIndex = planHierarchy.indexOf(planId)
  return planIndex < currentIndex
}

async function loadPlans() {
  loading.value = true
  try {
    const response = await subscriptionApi.getPlans()
    plans.value = response.plans
    stripeConfigured.value = response.stripeConfigured
  } catch (error) {
    console.error('Failed to load plans:', error)
  } finally {
    loading.value = false
  }
}

async function loadSubscriptionStatus() {
  try {
    subscriptionStatus.value = await subscriptionApi.getSubscriptionStatus()
  } catch (error) {
    console.error('Failed to load subscription status:', error)
  }
}

async function selectPlan(planId: string) {
  if (!stripeConfigured.value) {
    alert(t('subscription.serviceNotConfigured'))
    return
  }

  isProcessing.value = true
  try {
    const response = await subscriptionApi.createCheckoutSession(planId)
    // Redirect to Stripe Checkout
    window.location.href = response.url
  } catch (error: any) {
    console.error('Failed to create checkout session:', error)
    
    // Show user-friendly error
    if (error.message?.includes('unavailable') || error.message?.includes('STRIPE_NOT_CONFIGURED')) {
      alert(t('subscription.serviceNotConfigured'))
    } else {
      alert(t('subscription.checkoutFailed'))
    }
    
    isProcessing.value = false
  }
}

async function openBillingPortal() {
  if (!stripeConfigured.value) {
    alert(t('subscription.serviceNotConfigured'))
    return
  }

  isProcessing.value = true
  try {
    const response = await subscriptionApi.createPortalSession()
    // Redirect to Stripe Customer Portal
    window.location.href = response.url
  } catch (error: any) {
    console.error('Failed to open billing portal:', error)
    alert(t('subscription.checkoutFailed'))
    isProcessing.value = false
  }
}

function getLevelBadgeClass(level: string): string {
  const classes: Record<string, string> = {
    'NEW': 'badge-level badge-new',
    'PRO': 'badge-level badge-pro',
    'TEAM': 'badge-level badge-team',
    'BUSINESS': 'badge-level badge-business',
    'ADMIN': 'badge-level badge-admin',
  }
  return classes[level] || classes['NEW']
}

function getStatusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    'active': 'badge-level badge-business',
    'canceled': 'badge-level badge-new',
    'past_due': 'badge-level badge-admin',
    'trialing': 'badge-level badge-pro',
  }
  return classes[status] || classes['active']
}

function getStatusText(status: string): string {
  return t(`subscription.manage.status${capitalize(status)}`) || status
}

function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1)
}

function formatDate(timestamp: string | number): string {
  try {
    const date = typeof timestamp === 'number' 
      ? new Date(timestamp * 1000) 
      : new Date(timestamp)
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } catch {
    return String(timestamp)
  }
}

onMounted(async () => {
  await loadPlans()
  await loadSubscriptionStatus()
})
</script>
