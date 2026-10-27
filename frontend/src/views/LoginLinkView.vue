<template>
  <div class="min-h-screen flex items-center justify-center bg-chat px-4 py-10">
    <div class="w-full max-w-md surface-card rounded-2xl p-6 sm:p-8" data-testid="page-login-link">
      <p
        v-if="phase === 'opening'"
        class="text-sm txt-primary"
        role="status"
        data-testid="text-opening"
      >
        {{ $t('auth.loginLinkOpening') }}
      </p>
      <template v-else-if="phase === 'ready'">
        <p class="text-sm txt-primary" role="status" data-testid="text-ready">
          {{ $t('auth.loginLinkReady') }}
        </p>
        <router-link
          to="/"
          class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
          data-testid="link-chat"
        >
          {{ $t('auth.loginLinkGoToChat') }}
        </router-link>
      </template>
      <template v-else>
        <p class="text-sm text-red-600 dark:text-red-400" role="alert" data-testid="text-invalid">
          {{ $t('auth.loginLinkInvalid') }}
        </p>
        <router-link
          to="/login"
          class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
          data-testid="link-back-to-login"
        >
          {{ $t('auth.login') }}
        </router-link>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { consumeEmailSignInLink } from '@/services/api/emailLinkApi'
import { authService } from '@/services/authService'
import { resetPrincipalClientState } from '@/services/resetPrincipalClientState'
import { setSessionHint } from '@/services/sessionHint'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'

const route = useRoute()
const phase = ref<'opening' | 'ready' | 'invalid'>('opening')

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''
  if (token === '') {
    phase.value = 'invalid'
    return
  }
  try {
    await consumeEmailSignInLink(token)
    await resetPrincipalClientState()
    const me = await authService.getCurrentUser()
    if (!me) {
      phase.value = 'invalid'
      return
    }
    setSessionHint()
    await useAuthStore().refreshUser()
    await useConfigStore().reload()
    phase.value = 'ready'
  } catch {
    phase.value = 'invalid'
  }
})
</script>
