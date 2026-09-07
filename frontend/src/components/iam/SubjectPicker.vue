<template>
  <div ref="root" class="relative min-w-0" data-testid="iam-subject-picker">
    <input
      id="iam-subject-search"
      v-model="query"
      type="search"
      autocomplete="off"
      class="w-full px-3 py-2 text-sm rounded-lg border border-light-border/30 dark:border-dark-border/8 bg-[var(--bg-card)] txt-primary placeholder:txt-secondary min-h-[42px]"
      :placeholder="$t('iam.dialog.searchPlaceholder')"
      :aria-expanded="panelOpen"
      aria-controls="iam-subject-results"
      data-testid="input-iam-subject-search"
      @focus="focused = true"
    />
    <div
      v-if="panelOpen"
      id="iam-subject-results"
      class="dropdown-panel absolute left-0 right-0 top-full mt-1 z-40 max-h-56 overflow-y-auto scroll-thin"
      data-testid="list-iam-subjects"
      @mousedown.prevent
    >
      <p
        v-if="searchError"
        class="px-3 py-2 text-sm txt-secondary"
        data-testid="text-iam-search-failed"
      >
        {{ $t('iam.dialog.searchFailed') }}
        <button
          type="button"
          class="btn-secondary ml-2 px-3 py-1.5 rounded-lg text-sm font-medium"
          data-testid="btn-iam-search-retry"
          @click="load"
        >
          {{ $t('iam.dialog.tryAgain') }}
        </button>
      </p>
      <p
        v-else-if="loaded && subjects.length === 0 && query.trim() !== ''"
        class="px-3 py-2 text-sm txt-secondary"
        data-testid="text-iam-no-matches"
      >
        {{ $t('iam.dialog.noMatches') }}
      </p>
      <ul v-else class="space-y-0.5">
        <li v-for="subject in subjects" :key="`${subject.type}-${subject.id}`">
          <button
            type="button"
            class="dropdown-item w-full"
            :class="isSelected(subject) ? 'dropdown-item--active' : ''"
            :data-testid="`btn-iam-subject-${subject.type}-${subject.id}`"
            @click="select(subject)"
          >
            <span class="min-w-0">
              <span class="font-medium block truncate">{{ label(subject) }}</span>
              <span v-if="subject.email" class="block text-xs txt-secondary truncate">{{
                subject.email
              }}</span>
            </span>
            <span v-if="subject.type !== 'everyone'" class="text-xs txt-secondary shrink-0 ml-auto">
              {{ $t(`iam.dialog.subjectType.${subject.type}`) }}
            </span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { iamApi, type IamSubject } from '@/services/api/iamApi'

const props = defineProps<{
  modelValue: IamSubject | null
  active?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: IamSubject | null]
}>()

const { t } = useI18n()
const query = ref('')
const subjects = ref<IamSubject[]>([])
const loaded = ref(false)
const searchError = ref(false)
const focused = ref(false)
const root = ref<HTMLElement | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null

const panelOpen = computed(() => focused.value && (props.active === undefined || props.active))

const load = async () => {
  try {
    subjects.value = await iamApi.searchSubjects(query.value)
    searchError.value = false
  } catch {
    subjects.value = []
    searchError.value = true
  } finally {
    loaded.value = true
  }
}

watch(
  () => [query.value, props.active],
  () => {
    if (props.active === false) return
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      void load()
    }, 250)
  },
  { immediate: true }
)

watch(query, (value) => {
  if (props.modelValue && value !== label(props.modelValue)) {
    emit('update:modelValue', null)
  }
})

watch(
  () => props.modelValue,
  (value) => {
    if (value) {
      query.value = label(value)
      focused.value = false
    }
  }
)

const onDocumentClick = (event: MouseEvent) => {
  if (root.value && !root.value.contains(event.target as Node)) {
    focused.value = false
  }
}

if (typeof document !== 'undefined') {
  document.addEventListener('mousedown', onDocumentClick)
}

onUnmounted(() => {
  if (timer) clearTimeout(timer)
  document.removeEventListener('mousedown', onDocumentClick)
})

const label = (subject: IamSubject) => {
  if (subject.type === 'everyone') return t('iam.everyone')
  return subject.name || subject.email || String(subject.id)
}

const isSelected = (subject: IamSubject) =>
  props.modelValue?.type === subject.type && props.modelValue.id === subject.id

const select = (subject: IamSubject) => {
  emit('update:modelValue', subject)
  query.value = label(subject)
  focused.value = false
}

const resetQuery = () => {
  query.value = ''
  focused.value = false
}

defineExpose({ resetQuery })
</script>
