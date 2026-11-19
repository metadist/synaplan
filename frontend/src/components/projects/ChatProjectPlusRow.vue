<template>
  <button
    v-if="visible"
    type="button"
    class="plus-menu-row text-xs"
    data-testid="btn-plus-project"
    @click="emit('pick')"
  >
    <FolderIcon class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
    <span class="font-medium">{{ $t('projects.picker.row') }}</span>
    <span class="ml-auto min-w-0 truncate txt-secondary">{{ name }}</span>
    <ChevronRightIcon class="h-4 w-4 flex-shrink-0 txt-secondary" aria-hidden="true" />
  </button>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronRightIcon, FolderIcon } from '@heroicons/vue/24/outline'
import { useProjectsStore } from '@/stores/projects'
import { useChatsStore } from '@/stores/chats'

/** Phone-width entry for the project list, which lives in the plus menu there. */
const props = defineProps<{ enabled: boolean }>()
const emit = defineEmits<{ pick: [] }>()

const { t } = useI18n()
const store = useProjectsStore()
const chats = useChatsStore()

// The control bar shows the picker from the md breakpoint up; only a phone needs this row.
const phone = ref(false)
const query =
  typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 767px)') : null
const sync = (): void => {
  phone.value = query?.matches ?? false
}
onMounted(() => {
  sync()
  query?.addEventListener('change', sync)
})
onUnmounted(() => query?.removeEventListener('change', sync))

const visible = computed(() => props.enabled && phone.value && store.available && store.loaded)
const name = computed(
  () => store.projectOfChat(chats.activeChatId)?.name ?? t('projects.picker.none')
)
</script>
