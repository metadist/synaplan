<template>
  <MainLayout>
    <div class="min-h-screen bg-chat flex items-center justify-center p-4">
      <div class="max-w-lg w-full surface-card rounded-xl p-8 text-center">
        <!-- Loading State -->
        <template v-if="syncing">
          <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-blue-500/10 flex items-center justify-center">
            <Icon icon="mdi:loading" class="w-10 h-10 text-blue-500 animate-spin" />
          </div>
          <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('subscription.success.activating') }}</h1>
          <p class="txt-secondary mb-8">{{ $t('subscription.success.activatingDesc') }}</p>
        </template>

        <!-- Success State -->
        <template v-else-if="syncSuccess">
          <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-green-500/10 flex items-center justify-center">
            <Icon icon="mdi:check-circle" class="w-10 h-10 text-green-500" />
          </div>
          <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('subscription.success.title') }}</h1>
          <p class="txt-secondary mb-4">{{ $t('subscription.success.description') }}</p>
          <p v-if="newLevel && newLevel !== 'NEW'" class="text-lg font-semibold text-green-500 mb-8">
            {{ $t('subscription.success.newPlan') }}: {{ newLevel }}
          </p>
          <button
            @click="goHome"
            class="btn-primary px-8 py-3 rounded-lg font-semibold"
          >
            {{ $t('subscription.success.startUsing') }}
          </button>
        </template>

        <!-- Error State -->
        <template v-else>
          <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-amber-500/10 flex items-center justify-center">
            <Icon icon="mdi:alert" class="w-10 h-10 text-amber-500" />
          </div>
          <h1 class="text-3xl font-bold txt-primary mb-3">{{ $t('subscription.success.almostDone') }}</h1>
          <p class="txt-secondary mb-4">{{ $t('subscription.success.processing') }}</p>
          <p v-if="errorMessage" class="text-sm text-red-500 mb-4">{{ errorMessage }}</p>
          <div class="flex flex-col gap-3">
            <button
              @click="retrySync"
              :disabled="syncing"
              class="btn-primary px-8 py-3 rounded-lg font-semibold"
            >
              <Icon v-if="syncing" icon="mdi:loading" class="w-5 h-5 animate-spin inline mr-2" />
              {{ $t('subscription.success.retry') }}
            </button>
            <button
              @click="goHome"
              class="btn-secondary px-8 py-3 rounded-lg font-semibold"
            >
              {{ $t('subscription.success.continueAnyway') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const syncing = ref(true)
const syncSuccess = ref(false)
const newLevel = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

async function syncSubscription() {
  syncing.value = true
  errorMessage.value = null
  
  try {
    const result = await subscriptionApi.syncFromStripe()
    
    if (result.success && result.level && result.level !== 'NEW') {
      syncSuccess.value = true
      newLevel.value = result.level
      // Refresh user data to get new level
      await authStore.refreshUser()
    } else if (result.success && result.level === 'NEW') {
      // Subscription not found yet, might need retry
      errorMessage.value = result.message || 'Subscription not yet active. Please retry.'
      syncSuccess.value = false
    } else {
      syncSuccess.value = false
      errorMessage.value = 'Could not activate subscription.'
    }
  } catch (error: any) {
    console.error('Sync failed:', error)
    syncSuccess.value = false
    errorMessage.value = error.message || 'Failed to sync subscription'
  } finally {
    syncing.value = false
  }
}

async function retrySync() {
  await syncSubscription()
}

function goHome() {
  router.push('/')
}

onMounted(() => {
  // Auto-sync on page load
  syncSubscription()
})
</script>

