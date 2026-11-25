<template>
  <div class="app-screen flex items-start justify-center bg-chat px-4 py-10">
    <div
      class="w-full max-w-md my-auto surface-card rounded-2xl p-6 sm:p-8"
      data-testid="page-email-change"
    >
      <p
        v-if="phase === 'opening'"
        class="text-sm txt-primary"
        role="status"
        data-testid="text-opening"
      >
        {{ $t('auth.emailChangeOpening') }}
      </p>
      <template v-else-if="phase === 'done'">
        <p class="text-sm txt-primary" role="status" data-testid="text-done">
          {{ $t('auth.emailChangeDone', { email }) }}
        </p>
        <!-- A full load, so a signed-in tab shows the new address everywhere. -->
        <a
          href="/"
          class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
          data-testid="link-continue"
        >
          {{ $t('auth.emailChangeContinue') }}
        </a>
      </template>
      <template v-else-if="phase === 'failed'">
        <p class="text-sm text-red-600 dark:text-red-400" role="alert" data-testid="text-failed">
          {{ $t('auth.emailChangeFailed') }}
        </p>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium mt-4"
          data-testid="btn-retry"
          @click="confirm"
        >
          {{ $t('auth.emailChangeRetry') }}
        </button>
      </template>
      <template v-else>
        <p class="text-sm text-red-600 dark:text-red-400" role="alert" data-testid="text-invalid">
          {{ $t('auth.emailChangeInvalid') }}
        </p>
        <a
          href="/"
          class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex mt-4"
          data-testid="link-continue"
        >
          {{ $t('auth.emailChangeContinue') }}
        </a>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { confirmEmailChange } from '@/services/api/emailChangeApi'
import { ApiError } from '@/services/api/httpClient'

const route = useRoute()
const phase = ref<'opening' | 'done' | 'invalid' | 'failed'>('opening')
const email = ref('')

// Only the server's refusal means the link is dead. An outage leaves it usable.
const confirm = async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''
  if (token === '') {
    phase.value = 'invalid'
    return
  }
  phase.value = 'opening'
  try {
    const confirmed = await confirmEmailChange(token)
    email.value = confirmed.email
    phase.value = 'done'
  } catch (err: unknown) {
    phase.value = err instanceof ApiError && err.status < 500 ? 'invalid' : 'failed'
  }
}

onMounted(confirm)
</script>
