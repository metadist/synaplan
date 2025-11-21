<template>
  <Teleport to="body">
    <Transition name="dialog-fade">
      <div
        v-if="dialog.isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @click.self="handleBackdropClick"
        data-testid="modal-dialog-root"
      >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm" data-testid="modal-dialog-backdrop"></div>

        <!-- Dialog -->
        <div
          class="relative surface-card rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4 animate-scale-in"
          role="dialog"
          aria-modal="true"
          data-testid="modal-dialog"
        >
          <!-- Icon based on type/danger -->
          <div class="flex items-center gap-3">
            <div
              :class="[
                'flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center',
                dialog.danger
                  ? 'bg-red-500/10 text-red-500'
                  : dialog.type === 'confirm'
                  ? 'bg-blue-500/10 text-blue-500'
                  : 'bg-[var(--brand)]/10 text-[var(--brand)]'
              ]"
            >
              <ExclamationTriangleIcon v-if="dialog.danger" class="w-6 h-6" />
              <QuestionMarkCircleIcon v-else-if="dialog.type === 'confirm'" class="w-6 h-6" />
              <InformationCircleIcon v-else class="w-6 h-6" />
            </div>

            <!-- Title -->
            <h3 class="text-lg font-semibold txt-primary">
              {{ dialog.title }}
            </h3>
          </div>

          <!-- Message -->
          <p class="txt-secondary text-sm leading-relaxed">
            {{ dialog.message }}
          </p>

          <!-- Input for prompt -->
          <input
            v-if="dialog.type === 'prompt'"
            v-model="inputValue"
            type="text"
            :placeholder="dialog.placeholder"
            class="w-full px-4 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
            @keydown.enter="handleConfirm"
            @keydown.esc="handleCancel"
            ref="inputRef"
            data-testid="input-dialog-prompt"
          />

          <!-- Actions -->
          <div class="flex gap-3 justify-end pt-2">
            <button
              v-if="dialog.type !== 'alert'"
              @click="handleCancel"
              class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-sm font-medium"
              data-testid="btn-dialog-cancel"
            >
              {{ dialog.cancelText }}
            </button>
            <button
              @click="handleConfirm"
              :class="[
                'px-4 py-2 rounded-lg text-sm font-medium transition-all',
                dialog.danger
                  ? 'bg-red-500 hover:bg-red-600 text-white'
                  : 'btn-primary'
              ]"
              data-testid="btn-dialog-confirm"
            >
              {{ dialog.confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useDialog } from '@/composables/useDialog'
import {
  ExclamationTriangleIcon,
  QuestionMarkCircleIcon,
  InformationCircleIcon
} from '@heroicons/vue/24/outline'

const { dialog, close } = useDialog()
const inputValue = ref('')
const inputRef = ref<HTMLInputElement>()

// Reset input value when dialog opens
watch(() => dialog.value.isOpen, async (isOpen) => {
  if (isOpen) {
    inputValue.value = dialog.value.defaultValue || ''
    if (dialog.value.type === 'prompt') {
      await nextTick()
      inputRef.value?.focus()
    }
  }
})

const handleConfirm = () => {
  if (dialog.value.resolve) {
    if (dialog.value.type === 'confirm') {
      dialog.value.resolve(true)
    } else if (dialog.value.type === 'prompt') {
      dialog.value.resolve(inputValue.value || null)
    } else {
      dialog.value.resolve()
    }
  }
  dialog.value.isOpen = false
}

const handleCancel = () => {
  close()
}

const handleBackdropClick = () => {
  if (dialog.value.type !== 'alert') {
    handleCancel()
  }
}

const handleGlobalKeydown = (event: KeyboardEvent) => {
  if (!dialog.value.isOpen) return

  if (event.key === 'Enter' && dialog.value.type === 'confirm') {
    event.preventDefault()
    handleConfirm()
  }

  if (event.key === 'Escape' && dialog.value.type !== 'alert') {
    event.preventDefault()
    handleCancel()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleGlobalKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleGlobalKeydown)
})
</script>

<style scoped>
.dialog-fade-enter-active,
.dialog-fade-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-fade-enter-from,
.dialog-fade-leave-to {
  opacity: 0;
}

.animate-scale-in {
  animation: scale-in 0.2s ease-out;
}

@keyframes scale-in {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
