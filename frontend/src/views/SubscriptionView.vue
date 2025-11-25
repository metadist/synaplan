<template>
  <MainLayout data-testid="page-subscription">
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-6xl mx-auto space-y-8">
        
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
        </div>

        <!-- Subscription Management (if user has active subscription) -->
        <template v-else-if="subscriptionStatus?.hasSubscription && hasActivePlan">
          <div class="text-center mb-12">
            <h1 class="text-4xl font-bold txt-primary mb-3">{{ $t('subscription.manage.title') }}</h1>
            <p class="txt-secondary text-lg">{{ $t('subscription.manage.subtitle') }}</p>
          </div>

          <!-- Current Plan Card -->
          <div class="surface-card rounded-xl p-8 max-w-2xl mx-auto">
            <div class="flex items-start justify-between mb-6">
              <div>
                <h2 class="text-2xl font-bold txt-primary mb-2">{{ $t('subscription.manage.currentPlanLabel') }}</h2>
                <div class="flex items-center gap-3">
                  <span :class="getLevelBadgeClass(subscriptionStatus.plan)">
                    {{ subscriptionStatus.plan }}
                  </span>
                  <span :class="getStatusBadgeClass(subscriptionStatus.status || 'active')">
                    {{ getStatusText(subscriptionStatus.status || 'active') }}
                  </span>
                </div>
              </div>
              <Icon icon="mdi:crown" class="w-12 h-12 text-yellow-500" />
            </div>

            <!-- Billing Info -->
            <div class="space-y-4 mb-8">
              <div v-if="subscriptionStatus.nextBilling" class="flex justify-between items-center py-3 border-b border-light-border dark:border-dark-border">
                <span class="txt-secondary">{{ $t('subscription.manage.nextBilling') }}</span>
                <span class="txt-primary font-medium">{{ formatDate(subscriptionStatus.nextBilling) }}</span>
              </div>
              <div v-if="subscriptionStatus.cancelAt" class="flex justify-between items-center py-3 border-b border-light-border dark:border-dark-border">
                <span class="txt-secondary">{{ $t('subscription.manage.cancelDate') }}</span>
                <span class="txt-primary font-medium">{{ formatDate(subscriptionStatus.cancelAt) }}</span>
              </div>
            </div>

            <!-- Stripe Not Configured Warning -->
            <div v-if="!stripeConfigured" class="alert-warning mb-6">
              <div class="flex items-start gap-3">
                <Icon icon="mdi:alert-circle" class="w-6 h-6 flex-shrink-0" />
                <div>
                  <p class="font-semibold alert-warning-text mb-2">{{ $t('subscription.unavailable') }}</p>
                  <p class="text-sm alert-warning-text">{{ $t('subscription.unavailableDesc') }}</p>
                </div>
              </div>
            </div>

            <!-- Billing Portal Button -->
            <div class="info-box-blue mb-6">
              <div class="flex items-start gap-3">
                <Icon icon="mdi:credit-card-settings" class="w-6 h-6 info-box-blue-icon flex-shrink-0" />
                <div class="flex-1">
                  <p class="text-sm info-box-blue-title mb-1">{{ $t('subscription.manage.billingPortal') }}</p>
                  <p class="text-sm info-box-blue-text mb-3">{{ $t('subscription.manage.billingPortalDesc') }}</p>
                  <button
                    @click="openBillingPortal"
                    :disabled="isProcessing || !stripeConfigured"
                    class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
                    data-testid="btn-open-portal"
                  >
                    <Icon v-if="isProcessing" icon="mdi:loading" class="w-4 h-4 animate-spin inline mr-2" />
                    {{ isProcessing ? $t('subscription.manage.loadingPortal') : $t('subscription.manage.openPortal') }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- Plan Selection (if no active subscription) -->
        <template v-else>
          <!-- Header -->
          <div class="text-center mb-12">
            <h1 class="text-4xl font-bold txt-primary mb-3">{{ $t('subscription.chooseYourPlan') }}</h1>
            <p class="txt-secondary text-lg">{{ $t('subscription.subtitle') }}</p>
          </div>

          <!-- Stripe Not Configured Warning -->
          <div v-if="!stripeConfigured" class="alert-warning max-w-2xl mx-auto">
            <div class="flex items-start gap-3">
              <Icon icon="mdi:alert-circle" class="w-6 h-6 flex-shrink-0" />
              <div>
                <p class="font-semibold alert-warning-text mb-2">{{ $t('subscription.unavailable') }}</p>
                <p class="text-sm alert-warning-text">{{ $t('subscription.unavailableDesc') }}</p>
              </div>
            </div>
          </div>

          <!-- Plans Grid -->
          <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div
              v-for="plan in plans"
              :key="plan.id"
              class="surface-card rounded-xl p-8 flex flex-col hover:shadow-xl transition-shadow"
              :class="plan.id === 'TEAM' ? 'border-2 border-[var(--brand)] relative' : ''"
              data-testid="card-plan"
            >
              <!-- Recommended Badge -->
              <div
                v-if="plan.id === 'TEAM'"
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
                {{ isProcessing ? $t('subscription.processing') : $t('subscription.selectPlan') }}
              </button>
            </div>
          </div>

          <!-- Current Plan Info (for users with level but no Stripe subscription) -->
          <div v-if="currentLevel && currentLevel !== 'NEW'" class="surface-card rounded-lg p-6 text-center">
            <div class="flex items-center justify-center gap-3 mb-2">
              <Icon icon="mdi:information-outline" class="w-5 h-5 txt-secondary" />
              <span class="txt-secondary">
                {{ $t('subscription.currentPlan') }}: <strong class="txt-primary">{{ currentLevel }}</strong>
              </span>
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
