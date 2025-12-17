<template>
  <MainLayout>
    <div class="min-h-screen bg-chat flex items-center justify-center p-4">
      <div class="max-w-lg w-full surface-card rounded-xl p-8 text-center">
        <div
          class="w-16 h-16 mx-auto mb-6 rounded-full bg-yellow-500/10 flex items-center justify-center"
        >
          <Icon icon="mdi:alert-circle-outline" class="w-10 h-10 text-yellow-500" />
        </div>

        <h1 class="text-3xl font-bold txt-primary mb-3">Payment Canceled</h1>
        <p class="txt-secondary mb-8">Your payment was canceled. You can try again anytime.</p>

        <div class="flex gap-3 justify-center">
          <button
            class="btn-primary px-6 py-3 rounded-lg font-semibold"
            @click="router.push('/subscription')"
          >
            View Plans
          </button>
          <button
            class="btn-secondary px-6 py-3 rounded-lg font-semibold"
            @click="router.push('/')"
          >
            Back to Home
          </button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'

const router = useRouter()
</script>
