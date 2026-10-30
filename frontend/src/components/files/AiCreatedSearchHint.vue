<template>
  <RouterLink
    v-if="total > 0"
    :to="{ path: '/files/generated', query: { search } }"
    class="mt-2 inline-flex items-center gap-2 text-sm txt-secondary hover:text-[var(--brand)]"
    data-testid="hint-files-ai-created-search"
  >
    {{ $t('files.aiCreated.searchHint', { count: total }) }}
    <span class="font-medium text-[var(--brand)]">{{
      $t('files.aiCreated.searchHintAction')
    }}</span>
  </RouterLink>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import filesService from '@/services/filesService'
import { AI_CREATED_SOURCE_QUERY } from '@/services/fileSources'

const props = defineProps<{ search: string }>()
const total = ref(0)
let timer: ReturnType<typeof setTimeout> | null = null
let seq = 0

watch(
  () => props.search.trim(),
  (query) => {
    if (timer) clearTimeout(timer)
    if (!query) {
      total.value = 0
      return
    }
    timer = setTimeout(async () => {
      const request = ++seq
      try {
        const list = await filesService.listFiles({
          source: AI_CREATED_SOURCE_QUERY,
          search: query,
          limit: 1,
        })
        if (request === seq) total.value = list.pagination.total
      } catch {
        if (request === seq) total.value = 0
      }
    }, 300)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})
</script>
