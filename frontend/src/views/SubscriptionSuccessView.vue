<template>
  <MainLayout>
    <div class="min-h-screen bg-chat flex items-center justify-center p-4">
      <div class="max-w-lg w-full surface-card rounded-xl p-8 text-center">
        <div class="w-16 h-16 mx-auto mb-6 rounded-full bg-green-500/10 flex items-center justify-center">
          <Icon icon="mdi:check-circle" class="w-10 h-10 text-green-500" />
        </div>
        
        <h1 class="text-3xl font-bold txt-primary mb-3">Payment Successful!</h1>
        <p class="txt-secondary mb-8">
          Your subscription has been activated. Thank you for upgrading!
        </p>

        <button
          @click="router.push('/chat')"
          class="btn-primary px-8 py-3 rounded-lg font-semibold"
        >
          Start Using Your Plan
        </button>
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

