<template>
  <div class="space-y-2" data-testid="iam-subject-picker">
    <label class="text-sm font-medium txt-primary" for="iam-subject-search">{{
      $t('iam.dialog.who')
    }}</label>
    <input
      id="iam-subject-search"
      v-model="query"
      type="search"
      class="w-full px-3 py-2 text-sm rounded-lg border border-light-border/30 dark:border-dark-border/8 bg-[var(--bg-card)] txt-primary placeholder:txt-secondary"
      :placeholder="$t('iam.dialog.searchPlaceholder')"
      data-testid="input-iam-subject-search"
    />
    <ul class="max-h-40 overflow-y-auto scroll-thin space-y-1">
      <li v-for="subject in subjects" :key="`${subject.type}-${subject.id}`">
        <button
          type="button"
          class="w-full text-left px-3 py-2 rounded-lg text-sm txt-primary hover:bg-black/5 dark:hover:bg-white/5"
          :class="isSelected(subject) ? 'bg-[var(--brand)]/10 text-[var(--brand)]' : ''"
          :data-testid="`btn-iam-subject-${subject.type}-${subject.id}`"
          @click="select(subject)"
        >
          <span class="font-medium">{{ label(subject) }}</span>
          <span v-if="subject.email" class="block text-xs txt-secondary">{{ subject.email }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iamApi, type IamSubject } from '@/services/api/iamApi'

const props = defineProps<{
  modelValue: IamSubject | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: IamSubject | null]
}>()

const { t } = useI18n()
const query = ref('')
const subjects = ref<IamSubject[]>([])
let timer: ReturnType<typeof setTimeout> | null = null

const load = async () => {
  subjects.value = await iamApi.searchSubjects(query.value)
}

watch(
  query,
  () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      void load()
    }, 250)
  },
  { immediate: true }
)

onUnmounted(() => {
  if (timer) clearTimeout(timer)
})

const label = (subject: IamSubject) => {
  if (subject.type === 'everyone') return t('iam.everyone')
  return subject.name || subject.email || String(subject.id)
}

const isSelected = (subject: IamSubject) =>
  props.modelValue?.type === subject.type && props.modelValue.id === subject.id

const select = (subject: IamSubject) => {
  emit('update:modelValue', subject)
}
</script>
