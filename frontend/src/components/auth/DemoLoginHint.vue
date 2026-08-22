<template>
  <div
    v-if="visible"
    class="mb-5 p-3.5 rounded-xl border border-[var(--brand)]/25 bg-[var(--brand)]/8 dark:bg-[var(--brand)]/12"
    data-testid="section-demo-login-hint"
  >
    <p class="text-sm font-medium txt-primary">{{ $t('auth.demoHint.title') }}</p>
    <p class="text-xs txt-secondary mt-1 leading-relaxed">{{ $t('auth.demoHint.text') }}</p>
    <p class="text-xs txt-secondary mt-2.5 flex flex-wrap items-center gap-x-1.5 gap-y-1">
      <span
        class="font-mono px-1.5 py-0.5 rounded-md surface-chip txt-primary"
        data-testid="demo-login-email"
        >{{ email }}</span
      >
      <span aria-hidden="true">/</span>
      <span
        class="font-mono px-1.5 py-0.5 rounded-md surface-chip txt-primary"
        data-testid="demo-login-password"
        >{{ password }}</span
      >
    </p>
    <button
      type="button"
      class="btn-primary mt-3 px-4 py-2 rounded-lg text-sm font-medium w-full"
      data-testid="btn-demo-login"
      @click="emit('continue')"
    >
      {{ $t('auth.demoHint.cta') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useConfigStore } from '@/stores/config'
import { FIRST_RUN_ADMIN_EMAIL, FIRST_RUN_ADMIN_PASSWORD } from '@/constants/firstRunAdmin'

const emit = defineEmits<{ continue: [] }>()

const config = useConfigStore()
const visible = computed(() => config.setup.demoLoginHint)
const email = FIRST_RUN_ADMIN_EMAIL
const password = FIRST_RUN_ADMIN_PASSWORD
</script>
