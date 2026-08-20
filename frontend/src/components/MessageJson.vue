<template>
  <div
    class="my-3 overflow-hidden surface-card border border-light-border/30 dark:border-dark-border/20"
    data-testid="section-message-json"
  >
    <div
      class="flex items-center justify-between gap-2 border-b border-light-border/30 px-4 py-2.5 dark:border-dark-border/20 bg-black/5 dark:bg-white/5"
    >
      <div class="min-w-0">
        <p class="text-sm font-semibold txt-primary truncate">{{ headerTitle }}</p>
        <p v-if="headerSubtitle" class="text-xs txt-secondary">{{ headerSubtitle }}</p>
        <p v-if="isTruncated" class="text-xs txt-secondary" data-testid="json-truncated-hint">
          {{ $t('message.jsonViewer.truncatedHint') }}
        </p>
      </div>
      <button
        type="button"
        class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium txt-secondary hover-surface"
        :aria-label="$t('commands.copy')"
        data-testid="btn-copy-json"
        @click="copyJson"
      >
        {{ copied ? $t('commands.copied') : $t('commands.copy') }}
      </button>
    </div>

    <div
      v-if="recordViews.length > 0"
      class="divide-y divide-light-border/20 dark:divide-dark-border/15"
    >
      <article
        v-for="(row, index) in visibleRecords"
        :key="row.id ?? index"
        class="px-4 py-3"
        data-testid="json-record-row"
      >
        <h4 class="text-sm font-medium txt-primary break-words">
          {{ row.title || $t('message.jsonViewer.idLabel', { id: row.id ?? index + 1 }) }}
        </h4>
        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs txt-secondary">
          <span v-if="row.source" class="surface-chip px-2 py-0.5 rounded">{{ row.source }}</span>
          <span v-if="row.count !== null">{{
            $t('message.jsonViewer.messagesCount', { count: row.count })
          }}</span>
          <span v-if="row.date">{{ row.date }}</span>
          <span v-for="extra in row.extras" :key="extra.key">{{ extra.value }}</span>
        </div>
      </article>
      <button
        v-if="hiddenCount > 0 && !showAll"
        type="button"
        class="w-full px-4 py-2 text-xs font-medium txt-secondary hover-surface"
        data-testid="btn-json-show-more"
        @click="showAll = true"
      >
        {{ $t('message.jsonViewer.showMore', { count: hiddenCount }) }}
      </button>
      <button
        v-else-if="showAll && hiddenCount > 0"
        type="button"
        class="w-full px-4 py-2 text-xs font-medium txt-secondary hover-surface"
        data-testid="btn-json-show-less"
        @click="showAll = false"
      >
        {{ $t('message.jsonViewer.showLess') }}
      </button>
    </div>

    <div v-else-if="parsed !== null" class="px-4 py-3 overflow-x-auto scroll-thin">
      <ul class="m-0 list-none font-mono text-[13px] leading-6" data-testid="json-tree">
        <JsonTreeNode :value="parsed" :depth="0" />
      </ul>
    </div>

    <pre
      v-else
      class="m-0 overflow-x-auto p-4 text-sm txt-primary scroll-thin"
      data-testid="json-fallback"
      >{{ content }}</pre>

    <div
      v-if="recordViews.length > 0"
      class="border-t border-light-border/30 dark:border-dark-border/20"
    >
      <button
        type="button"
        class="flex w-full items-center justify-between px-4 py-2 text-xs font-medium txt-secondary hover-surface"
        data-testid="btn-toggle-json"
        :aria-expanded="showRaw"
        @click="showRaw = !showRaw"
      >
        {{ showRaw ? $t('message.jsonViewer.hideJson') : $t('message.jsonViewer.showJson') }}
        <span aria-hidden="true">{{ showRaw ? '▾' : '▸' }}</span>
      </button>
      <ul
        v-if="showRaw && parsed !== null"
        class="m-0 list-none overflow-x-auto border-t border-light-border/20 px-4 py-3 font-mono text-[13px] leading-6 scroll-thin dark:border-dark-border/15"
        data-testid="json-tree"
      >
        <JsonTreeNode :value="parsed" :depth="0" />
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import JsonTreeNode from './JsonTreeNode.vue'
import {
  extractRecordList,
  isKnownCollectionKey,
  parseJsonPayload,
  presentRecord,
} from '@/utils/jsonResult'

const VISIBLE_LIMIT = 20

const props = defineProps<{
  content: string
}>()

const { t, locale } = useI18n()
const copied = ref(false)
const showRaw = ref(false)
const showAll = ref(false)

const payload = computed(() => parseJsonPayload(props.content))
const parsed = computed(() => payload.value?.value ?? null)
const isTruncated = computed(() => payload.value?.truncated === true)

const recordList = computed(() => {
  if (parsed.value === null) {
    return null
  }
  return extractRecordList(parsed.value)
})

const recordViews = computed(() => {
  if (!recordList.value) {
    return []
  }
  return recordList.value.records.map((record) => presentRecord(record, locale.value))
})

const hiddenCount = computed(() => Math.max(0, recordViews.value.length - VISIBLE_LIMIT))

const visibleRecords = computed(() =>
  showAll.value ? recordViews.value : recordViews.value.slice(0, VISIBLE_LIMIT)
)

const headerTitle = computed(() => {
  const list = recordList.value
  if (!list) {
    return t('message.jsonViewer.title')
  }
  const count = list.total ?? list.records.length
  const key = list.collectionKey
  if (key && isKnownCollectionKey(key)) {
    return t(`message.jsonViewer.${key}`, { count })
  }
  return t('message.jsonViewer.itemCount', { count })
})

const headerSubtitle = computed(() => {
  const list = recordList.value
  if (!list || list.total === null) {
    return ''
  }
  const shown = visibleRecords.value.length
  if (shown === list.total) {
    return ''
  }
  return t('message.jsonViewer.shownOf', { shown, total: list.total })
})

const copyJson = async () => {
  const pretty = parsed.value !== null ? JSON.stringify(parsed.value, null, 2) : props.content
  try {
    await navigator.clipboard.writeText(pretty)
    copied.value = true
    window.setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch {
    copied.value = false
  }
}
</script>
