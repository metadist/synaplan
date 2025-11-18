<template>
  <div v-if="error" data-testid="comp-error-boundary">
    <ErrorView :error="errorInfo" :show-stack="true" />
  </div>
  <slot v-else />
</template>

<script setup lang="ts">
import { ref, onErrorCaptured } from 'vue'
import { useRouter } from 'vue-router'
import ErrorView from '@/views/ErrorView.vue'

const router = useRouter()
const error = ref(false)
const errorInfo = ref<{
  message?: string
  statusCode?: number
  stack?: string
}>({})

onErrorCaptured((err: any) => {
  console.error('Component error:', err)
  
  error.value = true
  errorInfo.value = {
    message: err.message || 'Unknown error',
    statusCode: err.statusCode || 500,
    stack: err.stack || ''
  }

  // Optionally redirect to error page instead
  // router.push({ name: 'error', params: { error: err.message } })

  // Prevent error from propagating
  return false
})
</script>

