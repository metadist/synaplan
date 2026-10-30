<template>
  <div ref="rootRef" class="relative w-full" data-testid="comp-knowledge-folder-picker">
    <button
      type="button"
      class="plus-menu-row w-full"
      data-beside-row
      :aria-label="$t('chatInput.knowledgeGroup')"
      :title="selectedLabel"
      :aria-expanded="isOpen"
      aria-haspopup="menu"
      data-testid="btn-knowledge-folder"
      @click="onToggleClick"
      @mouseenter="onRowEnter"
      @mouseleave="onRowLeave"
      @keydown.escape="close"
      @keydown.right.prevent="onArrowRight"
      @keydown.down.prevent="onArrowRight"
    >
      <FolderIcon
        :class="['w-4 h-4 md:w-5 md:h-5 shrink-0', modelValue && 'text-[var(--brand)]']"
      />
      <span class="text-xs md:text-sm font-medium truncate min-w-0">
        {{ selectedLabel }}
      </span>
      <span class="flex-1" aria-hidden="true" />
      <ChevronLeftIcon v-if="opensLeft" class="w-4 h-4 shrink-0" />
      <ChevronRightIcon v-else class="w-4 h-4 shrink-0" />
    </button>

    <div
      v-if="isOpen"
      ref="panelRef"
      class="dropdown-up side-flyout"
      data-testid="dropdown-knowledge-folder"
      data-dropdown-placement="manual"
      role="menu"
      @mouseenter="onPanelEnter"
      @mouseleave="onPanelLeave"
      @pointerdown="onPanelPointerDown"
      @keydown.escape="close"
      @keydown.left.prevent="onArrowLeft"
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

      <button
        v-for="folder in sharedFolders"
        :key="folder.value"
        type="button"
        :class="['dropdown-item', modelValue === folder.value && 'dropdown-item--active']"
        data-testid="opt-knowledge-folder-shared"
        @click="select(folder.value)"
      >
        <FolderIcon class="w-5 h-5 flex-shrink-0" />
        <span class="flex-1 text-sm truncate">{{
          $t('iam.sharedFolder', { name: folder.name })
        }}</span>
        <CheckIcon
          v-if="modelValue === folder.value"
          class="w-5 h-5 flex-shrink-0 text-[var(--brand)]"
        />
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowTopRightOnSquareIcon,
  CheckIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  FolderIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { iamApi } from '@/services/api/iamApi'
import { useBesideMenu } from '@/composables/useBesideMenu'

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
const panelRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)
const {
  opensLeft,
  onRowEnter,
  onRowLeave,
  onPanelEnter,
  onPanelPointerDown,
  onPanelLeave,
  onRowClick,
  openFromKeyboard,
  close,
} = useBesideMenu('knowledge', isOpen, rootRef, panelRef)

const sharedFolders = ref<Array<{ name: string; value: string }>>([])

const selectedLabel = computed(() => {
  if (props.modelValue === '') return t('chatInput.knowledgeGroup')
  const shared = sharedFolders.value.find((folder) => folder.value === props.modelValue)
  return shared ? t('iam.sharedFolder', { name: shared.name }) : props.modelValue
})

const loadSharedFolders = async () => {
  if (!isIamSharingEnabled()) {
    sharedFolders.value = []
    return
  }
  try {
    const items = await iamApi.listSharedWithMe('knowledge_folder')
    sharedFolders.value = items
      .filter((item) => item.id !== '')
      .map((item) => ({ name: item.name, value: `shared:${item.id}` }))
  } catch {
    sharedFolders.value = []
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  void loadSharedFolders()
})

const onToggleClick = () => {
  if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    triggerHapticImpact('light')
  }
  onRowClick()
}

const onArrowRight = async () => {
  openFromKeyboard()
  await nextTick()
  panelRef.value?.querySelector('button')?.focus()
}

const onArrowLeft = () => {
  close()
  rootRef.value?.querySelector('button')?.focus()
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

onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside))
</script>
