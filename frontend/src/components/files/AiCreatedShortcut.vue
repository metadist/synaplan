<template>
  <RouterLink
    v-if="count > 0"
    to="/files/generated"
    class="flex items-center gap-3 p-3 sm:p-4 rounded-xl sm:rounded-2xl border border-light-border/20 dark:border-dark-border/7 hover:border-[var(--brand)]/30 hover:bg-[var(--brand)]/[0.03] transition-colors mb-4"
    data-testid="card-files-ai-created"
  >
    <div class="flex -space-x-2 shrink-0">
      <span
        v-for="file in visualPreviews"
        :key="file.id"
        class="w-9 h-9 sm:w-10 sm:h-10 rounded-lg overflow-hidden border border-light-border/30 dark:border-dark-border/20 bg-[var(--bg-chip)]"
      >
        <img
          :src="thumb(file)!"
          :alt="file.filename"
          class="w-full h-full object-cover"
          loading="lazy"
        />
      </span>
      <span
        v-if="visualPreviews.length === 0"
        class="w-9 h-9 sm:w-10 sm:h-10 rounded-lg bg-[var(--brand)]/10 flex items-center justify-center"
      >
        <SparklesIcon class="w-4 h-4 text-[var(--brand)]" />
      </span>
    </div>
    <span class="min-w-0">
      <span class="block text-sm font-medium txt-primary truncate">
        {{ $t('files.aiCreated.title') }}
      </span>
      <span class="block text-xs txt-secondary">
        {{ $t('files.aiCreated.count', { count }) }}
      </span>
    </span>
  </RouterLink>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import filesService, { type FileItem } from '@/services/filesService'
import { AI_CREATED_SOURCE_QUERY } from '@/services/fileSources'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useMediaSrc } from '@/services/api/mediaAuth'

const { mediaSrc } = useMediaSrc()
const count = ref(0)
const previews = ref<FileItem[]>([])
const visualPreviews = computed(() => previews.value.filter((file) => thumb(file)))

const thumb = (file: FileItem): string | null => {
  const base = getApiBaseUrl()
  if (file.thumb_url) return mediaSrc(`${base}/api/v1/files/${file.id}/thumb`)
  const type = file.file_type.toLowerCase()
  if (type.includes('image') || /jpg|jpeg|png|gif|webp/.test(type)) {
    return mediaSrc(`${base}/api/v1/files/${file.id}/download`)
  }
  return null
}

onMounted(async () => {
  try {
    const [facets, list] = await Promise.all([
      filesService.getFacets(),
      filesService.listFiles({
        source: AI_CREATED_SOURCE_QUERY,
        originKind: 'image,video',
        sort: 'date_desc',
        limit: 3,
      }),
    ])
    count.value = (facets.source.generated ?? 0) + (facets.source.compute ?? 0)
    previews.value = list.files.slice(0, 3)
  } catch {
    count.value = 0
  }
})
</script>
