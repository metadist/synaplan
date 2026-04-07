<template>
  <div v-if="error" data-testid="comp-error-boundary">
    <ErrorView :error="errorInfo" :show-stack="true" />
  </div>
  <slot v-else />
</template>

<script setup lang="ts">
import { ref, onErrorCaptured } from 'vue'
import ErrorView from '@/views/ErrorView.vue'
import { getErrorMessage } from '@/utils/errorMessage'

const error = ref(false)
const errorInfo = ref<{
  message?: string
  statusCode?: number
  stack?: string
}>({})

onErrorCaptured((err: unknown) => {
  console.error('Component error:', err)

  error.value = true
  const statusFromErr =
    err &&
    typeof err === 'object' &&
    'statusCode' in err &&
    typeof (err as { statusCode: unknown }).statusCode === 'number'
      ? (err as { statusCode: number }).statusCode
      : 500
  errorInfo.value = {
    message: getErrorMessage(err) ?? undefined,
    statusCode: statusFromErr,
    stack: err instanceof Error ? err.stack || '' : '',
  }

  // Optionally redirect to error page instead
  // router.push({ name: 'error', params: { error: err.message } })

  // Prevent error from propagating
  return false
})
</script>
