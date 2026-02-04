<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="close"
      >
        <div
          class="surface-card rounded-2xl shadow-2xl max-w-md w-full max-h-[95vh] sm:max-h-[90vh] overflow-y-auto scroll-thin"
          @click.stop
        >
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <h3 class="text-base sm:text-lg font-semibold txt-primary">
              {{ t('memories.deleteDialog.title') }}
            </h3>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
              @click="close"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <div class="p-4 sm:p-6 space-y-4">
            <p class="txt-secondary text-sm">
              {{ t('memories.deleteDialog.message') }}
            </p>

            <div v-if="memory" class="surface-chip rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <Icon icon="mdi:information-outline" class="w-4 h-4 txt-secondary" />
                <span class="text-xs font-medium txt-secondary uppercase tracking-wide">
                  {{ t('memories.deleteDialog.memoryLabel') }}
                </span>
              </div>
              <div class="flex items-center gap-2 mb-1">
                <span class="pill text-xs">{{ memory.category }}</span>
                <span class="text-sm font-medium txt-primary">{{ memory.key }}</span>
              </div>
              <p class="txt-secondary text-sm">{{ memory.value }}</p>
            </div>

            <div class="flex items-center gap-3 pt-2">
              <button
                type="button"
                class="flex-1 btn-secondary px-4 py-2.5 rounded-xl font-medium transition-all"
                @click="close"
              >
                {{ t('memories.deleteDialog.undo') }}
              </button>
              <button
                type="button"
                class="flex-1 btn-danger px-4 py-2.5 rounded-xl font-medium transition-all"
                @click="confirm"
              >
                {{ t('memories.deleteDialog.confirm') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  isOpen: boolean
  memory: UserMemory | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'confirm', memory: UserMemory): void
}>()

const { t } = useI18n()

const close = () => emit('close')
const confirm = () => {
  if (props.memory) {
    emit('confirm', props.memory)
  }
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
