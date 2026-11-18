<template>
  <router-link
    v-if="project"
    :to="`/projects/${project.id}`"
    class="inline-flex max-w-full items-center gap-1.5 rounded-full surface-chip px-3 py-1 text-xs font-medium txt-secondary hover:txt-primary"
    :title="$t('projects.composer.title', { name: project.name })"
    data-testid="badge-chat-project"
  >
    <FolderIcon class="h-3.5 w-3.5 flex-shrink-0" aria-hidden="true" />
    <span class="truncate">{{ $t('projects.composer.inProject', { name: project.name }) }}</span>
  </router-link>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { useProjectsStore } from '@/stores/projects'

const props = defineProps<{ chatId: number | null | undefined }>()

const store = useProjectsStore()
const project = computed(() => (store.available ? store.projectOfChat(props.chatId) : undefined))

onMounted(() => void store.loadOnce())
</script>
