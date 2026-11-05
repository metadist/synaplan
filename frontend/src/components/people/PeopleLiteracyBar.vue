<template>
  <section class="surface-card rounded-lg p-4 mb-6 space-y-3" data-testid="section-ai-literacy">
    <div class="flex items-start gap-3">
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        :aria-pressed="required"
        data-testid="toggle-ai-literacy"
        @click="onToggle"
      >
        {{ required ? t('people.literacy.confirmed') : t('people.literacy.notYet') }}
      </button>
      <span>
        <span class="block text-sm font-medium txt-primary">{{ t('people.literacy.switch') }}</span>
        <span class="block text-xs txt-secondary mt-1">{{ t('people.literacy.consequence') }}</span>
      </span>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <p class="text-sm txt-primary" data-testid="text-literacy-count">
        {{ t('people.literacy.count', { confirmed, total }) }}
      </p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-literacy-export"
        @click="download"
      >
        {{ t('people.literacy.export') }}
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  adminAiLiteracy,
  downloadAiLiteracyCsv,
  setAiLiteracyRequired,
  type LiteracyAdmin,
} from '@/services/api/aiLiteracyApi'

const props = defineProps<{ userIds: number[] }>()
const emit = defineEmits<{ loaded: [LiteracyAdmin] }>()
const { t } = useI18n()

const required = ref(false)
const confirmed = ref(0)
const total = ref(0)

async function load(): Promise<void> {
  const status = await adminAiLiteracy(props.userIds)
  required.value = status.required
  confirmed.value = status.confirmed
  total.value = status.total
  emit('loaded', status)
}

onMounted(() => {
  void load().catch(() => undefined)
})

watch(
  () => props.userIds.join(','),
  () => {
    void load().catch(() => undefined)
  }
)

async function onToggle(): Promise<void> {
  required.value = await setAiLiteracyRequired(!required.value)
  await load()
}

async function download(): Promise<void> {
  await downloadAiLiteracyCsv()
}
</script>
