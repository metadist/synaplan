<template>
  <div data-testid="section-notion-activity">
    <p class="text-sm font-medium txt-primary">{{ $t('apps.notion.activity.title') }}</p>
    <p
      v-if="loadFailed"
      class="txt-secondary text-sm mt-2"
      data-testid="text-notion-activity-error"
    >
      {{ $t('apps.notion.activity.loadFailed') }}
    </p>
    <p
      v-else-if="items.length === 0"
      class="txt-secondary text-sm mt-2"
      data-testid="text-notion-activity-empty"
    >
      {{ $t('apps.notion.activity.empty') }}
    </p>
    <ul v-else class="mt-2 divide-y divide-light-border/30 dark:divide-dark-border/20">
      <li
        v-for="item in items"
        :key="item.id"
        class="py-2 flex items-center gap-3 min-w-0"
        data-testid="item-notion-activity"
      >
        <Icon
          :icon="icons[item.action]"
          class="w-4 h-4 shrink-0 txt-secondary"
          aria-hidden="true"
        />
        <span class="min-w-0 flex-1">
          <a
            v-if="item.url"
            :href="item.url"
            target="_blank"
            rel="noopener noreferrer"
            class="block txt-primary text-sm truncate hover:underline"
          >
            {{ item.title }}
          </a>
          <span v-else class="block txt-primary text-sm truncate">{{ item.title }}</span>
          <span class="block txt-secondary text-xs">
            {{ $t(`apps.notion.activity.action.${item.action}`) }} ·
            {{ formatRelativeTime(new Date(item.created_at * 1000)) }}
          </span>
        </span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useDateFormat } from '@/composables/useDateFormat'
import { notionAppApi, type NotionActivityItem } from '@/services/api/notionAppApi'

const { formatRelativeTime } = useDateFormat()

const icons: Record<NotionActivityItem['action'], string> = {
  created: 'heroicons:document-plus',
  updated: 'heroicons:pencil-square',
  file: 'heroicons:paper-clip',
}

const items = ref<NotionActivityItem[]>([])
const loadFailed = ref(false)

const load = async () => {
  try {
    items.value = await notionAppApi.activity()
    loadFailed.value = false
  } catch {
    loadFailed.value = true
  }
}

defineExpose({ load })

onMounted(() => {
  void load()
})
</script>
