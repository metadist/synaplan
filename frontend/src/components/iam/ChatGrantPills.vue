<template>
  <ChatKindPill v-if="summary?.everyone" kind="everyone" :size="size" />
  <ChatKindPill
    v-for="name in summary?.groups ?? []"
    :key="name"
    kind="group"
    :label="name"
    :size="size"
  />
  <ChatKindPill
    v-if="(summary?.people ?? 0) > 0"
    kind="direct"
    :size="size"
    :text="t('iam.incoming.pill.sharedWithPeople', { count: summary?.people ?? 0 })"
  />
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ChatKindPill from '@/components/iam/ChatKindPill.vue'

defineProps<{
  summary?: {
    everyone: boolean
    people: number
    groups: string[]
  } | null
  size?: 'xs' | 'sm'
}>()

const { t } = useI18n()
</script>
