<template>
  <div class="relative">
    <button
      class="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 txt-secondary hover:text-[var(--brand)] transition-colors"
      :title="$t('files.moveTo')"
      @click.stop="$emit('toggle')"
    >
      <Icon icon="heroicons:folder-arrow-down" class="w-4 h-4" />
    </button>
    <Transition name="fade">
      <div
        v-if="open"
        class="absolute right-0 top-full mt-1 z-30 w-52 surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5 overflow-hidden"
      >
        <div class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider txt-secondary">
          {{ $t('files.moveTo') }}
        </div>
        <button
          v-for="folder in folders"
          :key="folder.name"
          class="w-full flex items-center gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left"
          :class="{
            'text-[var(--brand)] font-medium': folder.name === currentFolder,
          }"
          @click="$emit('move', folder.name)"
        >
          <Icon
            :icon="
              folder.name === currentFolder
                ? 'heroicons:folder-open-solid'
                : 'heroicons:folder-solid'
            "
            class="w-4 h-4 shrink-0"
          />
          <span class="truncate">{{ folder.name }}</span>
          <Icon
            v-if="folder.name === currentFolder"
            icon="heroicons:check"
            class="w-3.5 h-3.5 ml-auto text-[var(--brand)]"
          />
        </button>
        <div class="border-t border-light-border/20 dark:border-dark-border/10 mt-1.5 pt-1.5">
          <div class="flex items-center gap-1.5 px-3 py-1">
            <Icon icon="heroicons:folder-plus" class="w-4 h-4 text-[var(--brand)] shrink-0" />
            <input
              v-model="newTarget"
              type="text"
              class="flex-1 text-xs bg-transparent txt-primary placeholder:txt-secondary/50 focus:outline-none"
              :placeholder="$t('files.folderPicker.newPlaceholder')"
              @keyup.enter="$emit('move', newTarget.trim())"
              @click.stop
            />
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Icon } from '@iconify/vue'

defineProps<{
  open: boolean
  folders: Array<{ name: string; count: number }>
  currentFolder?: string
}>()

defineEmits<{
  toggle: []
  move: [folderName: string]
}>()

const newTarget = ref('')
</script>
