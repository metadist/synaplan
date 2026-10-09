<script setup lang="ts">
import { watch } from 'vue'
import { useRoute } from 'vue-router'
import { useTour } from '@/composables/useTour'

/** Starts the route's guided tour (`meta.tour`) once per account. */
const route = useRoute()
const { maybeAutoStart } = useTour()

watch(
  () => route.meta.tour,
  (tourId) => {
    if (typeof tourId === 'string') void maybeAutoStart(tourId)
  },
  { immediate: true }
)
</script>

<template>
  <span hidden />
</template>
