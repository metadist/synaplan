<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EyeIcon } from '@heroicons/vue/24/outline'
import MessageText from '@/components/MessageText.vue'
import { httpClient } from '@/services/api/httpClient'
import { GetApiRagChunkResponseSchema } from '@/generated/api-schemas'
import type { RagSourceRef } from '@/stores/history'

withDefaults(
  defineProps<{
    sources: RagSourceRef[]
    showLibraryLink?: boolean
  }>(),
  { showLibraryLink: true }
)

const { t } = useI18n()
const openId = ref<string | null>(null)
const passage = ref('')
const loading = ref(false)
const failed = ref(false)

const toggle = async (source: RagSourceRef) => {
  if (openId.value === source.chunkId) {
    openId.value = null
    passage.value = ''
    failed.value = false
    return
  }
  openId.value = source.chunkId
  passage.value = ''
  failed.value = false
  loading.value = true
  try {
    const data = await httpClient(`/api/v1/rag/chunks/${encodeURIComponent(source.chunkId)}`, {
      schema: GetApiRagChunkResponseSchema,
    })
    passage.value = data.text
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    v-if="sources.length > 0"
    class="mt-4 pt-3 border-t border-light-border/20 dark:border-dark-border/20 space-y-2"
    data-testid="knowledge-sources"
  >
    <p class="text-sm font-medium txt-secondary">{{ t('knowledgeSources.title') }}</p>
    <ul class="space-y-2">
      <li v-for="source in sources" :key="source.chunkId" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs txt-secondary">[{{ source.n }}]</span>
          <span class="text-sm txt-primary">{{ source.fileName || source.groupKey }}</span>
          <span v-if="source.startLine > 0" class="text-xs txt-secondary">
            {{ t('knowledgeSources.lines', { start: source.startLine, end: source.endLine }) }}
          </span>
          <button
            type="button"
            class="btn-secondary inline-flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-medium"
            data-testid="knowledge-source-open"
            @click="toggle(source)"
          >
            <EyeIcon class="w-4 h-4" />
            {{
              openId === source.chunkId
                ? t('knowledgeSources.hidePassage')
                : t('knowledgeSources.showPassage')
            }}
          </button>
          <RouterLink
            v-if="showLibraryLink && source.fileId > 0"
            :to="{ path: '/files', query: { file: String(source.fileId) } }"
            class="btn-secondary inline-flex items-center px-3 py-2 rounded-xl text-xs font-medium"
          >
            {{ t('knowledgeSources.openInLibrary') }}
          </RouterLink>
        </div>
        <div
          v-if="openId === source.chunkId"
          class="surface-card p-3 rounded-xl"
          data-testid="knowledge-source-passage"
        >
          <p v-if="loading" class="text-sm txt-secondary">…</p>
          <p v-else-if="failed" class="text-sm text-red-600 dark:text-red-400">
            {{ t('knowledgeSources.unavailable') }}
          </p>
          <MessageText v-else-if="passage" :content="passage" readonly />
        </div>
      </li>
    </ul>
  </div>
</template>
