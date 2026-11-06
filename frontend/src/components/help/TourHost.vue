<script setup lang="ts">
import { watch } from 'vue'
import { useRoute } from 'vue-router'
import { useTour } from '@/composables/useTour'
import { useAuthStore } from '@/stores/auth'
import { resolveRouteTourId } from '@/tours'

/** Starts the route's guided tour (`meta.tour`) once per account. */
const route = useRoute()
const authStore = useAuthStore()
const { maybeAutoStart } = useTour()

watch(
  () => [route.meta.tour, authStore.isAuthenticated] as const,
  ([tourId]) => {
    if (typeof tourId === 'string') {
      void maybeAutoStart(resolveRouteTourId(tourId, authStore.isAuthenticated))
    }
  },
  { immediate: true }
)
</script>

<template>
  <span hidden />
</template>
