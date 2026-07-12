<template>
  <div ref="rootRef" class="relative" data-testid="comp-knowledge-folder-picker">
    <button
      type="button"
      :class="['pill', modelValue && 'pill--active', isOpen && 'pill--active']"
      :aria-label="$t('chatInput.knowledgeGroup')"
      :title="$t('chatInput.knowledgeGroup')"
      data-testid="btn-knowledge-folder"
      @click="toggleOpen"
      @keydown.escape="close"
    >
      <FolderIcon class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" />
      <span class="text-xs md:text-sm font-medium truncate max-w-[28vw] md:max-w-[160px]">
        {{ selectedLabel }}
      </span>
      <ChevronUpIcon class="w-4 h-4 flex-shrink-0" />
    </button>

    <div
      v-if="isOpen"
      class="dropdown-up left-0 w-[calc(100vw-2rem)] sm:w-72 max-h-[50vh] overflow-y-auto scroll-thin"
      data-testid="dropdown-knowledge-folder"
      @keydown.escape="close"
    >
      <button
        type="button"
        :class="['dropdown-item', !modelValue && 'dropdown-item--active']"
        data-testid="opt-knowledge-folder-none"
        @click="select('')"
      >
        <Icon icon="mdi:folder-off-outline" class="w-5 h-5 flex-shrink-0" />
        <span class="flex-1 text-sm">{{ $t('chatInput.knowledgeGroupNone') }}</span>
        <CheckIcon v-if="!modelValue" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
      </button>

      <button
        v-for="g in groups"
        :key="g.name"
        type="button"
        :class="['dropdown-item', modelValue === g.name && 'dropdown-item--active']"
        data-testid="opt-knowledge-folder"
        @click="select(g.name)"
      >
        <FolderIcon class="w-5 h-5 flex-shrink-0" />
        <span class="flex-1 text-sm truncate">{{ g.name }}</span>
        <span class="text-xs txt-secondary flex-shrink-0">{{ g.count }}</span>
        <CheckIcon v-if="modelValue === g.name" class="w-5 h-5 flex-shrink-0 text-[var(--brand)]" />
      </button>

      <!-- §4.7 #1: the only way from the action row to the Files page lives
           INSIDE the picker, clearly marked as a link row — never as a
           sibling pill. -->
      <div class="border-t border-light-border/20 dark:border-dark-border/20 my-1" />
      <button
        type="button"
        class="dropdown-item"
        data-testid="link-manage-knowledge-folders"
        @click="goToFiles"
      >
        <Icon icon="mdi:folder-cog-outline" class="w-5 h-5 flex-shrink-0" />
        <span class="flex-1 text-sm">{{ $t('chatInput.manageKnowledgeGroups') }}</span>
        <ArrowTopRightOnSquareIcon class="w-4 h-4 flex-shrink-0 txt-secondary" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowTopRightOnSquareIcon,
  CheckIcon,
  ChevronUpIcon,
  FolderIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'

interface Props {
  /** Selected knowledge-folder (RAG group) key; '' = none. */
  modelValue: string
  groups: Array<{ name: string; count: number }>
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const router = useRouter()

const rootRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)

const selectedLabel = computed(() =>
  props.modelValue !== '' ? props.modelValue : t('chatInput.knowledgeGroup')
)

const toggleOpen = () => {
  triggerHapticImpact('light')
  isOpen.value = !isOpen.value
}

const close = () => {
  isOpen.value = false
}

const select = (name: string) => {
  emit('update:modelValue', name)
  close()
}

const goToFiles = () => {
  close()
  router.push('/files')
}

const handleClickOutside = (e: MouseEvent) => {
  if (!isOpen.value) return
  if (rootRef.value && rootRef.value.contains(e.target as Node)) return
  close()
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))
</script>
