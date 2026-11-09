<template>
  <div class="text-center py-4" data-testid="register-closed">
    <p class="txt-secondary text-sm leading-relaxed mb-6 max-w-xs mx-auto">
      {{ paused ? $t('auth.signupBlocked.paused') : $t('auth.registrationClosed') }}
    </p>
    <Button class="w-full px-4 py-2.5 text-sm font-medium" @click="goToLogin">
      {{ $t('auth.signIn') }}
    </Button>
  </div>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/Button.vue'

defineProps<{ paused?: boolean }>()

const router = useRouter()
const route = useRoute()

const goToLogin = () => {
  void router.push({ name: 'login', query: route.query })
}
</script>
