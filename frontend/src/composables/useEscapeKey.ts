import { onMounted, onBeforeUnmount, watchEffect, type Ref } from 'vue'

/**
 * Register an Escape-key handler that auto-cleans up.
 *
 * For always-mounted modals (component-based):
 *   useEscapeKey(() => emit('close'))
 *
 * For conditionally-shown inline modals (controlled by a ref):
 *   useEscapeKey(() => (showModal.value = false), showModal)
 */
export function useEscapeKey(callback: () => void, isActive?: Ref<boolean>): void {
  const handler = (e: KeyboardEvent) => {
    if (e.key === 'Escape') callback()
  }

  if (isActive) {
    watchEffect((onCleanup) => {
      if (isActive.value) {
        document.addEventListener('keydown', handler)
        onCleanup(() => document.removeEventListener('keydown', handler))
      }
    })
  } else {
    onMounted(() => document.addEventListener('keydown', handler))
    onBeforeUnmount(() => document.removeEventListener('keydown', handler))
  }
}
