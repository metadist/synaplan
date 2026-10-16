import { nextTick, onBeforeUnmount, onMounted, watch, type Ref } from 'vue'

/** Where focus may go while the palette is open: dialogs and the Undo toasts. */
const FOCUS_ALLOWED = '[role="dialog"], [data-testid="comp-notification-container"]'

/**
 * Modal focus for the palette: the field is focused on open, focus returns
 * to where it was on close, and a view that autofocuses after mounting (the
 * chat composer) cannot pull the keyboard behind the open palette.
 */
export function usePaletteFocus(
  isOpen: Ref<boolean>,
  input: Ref<HTMLInputElement | null>,
  onOpen: () => void
) {
  let previouslyFocused: HTMLElement | null = null

  watch(isOpen, async (open) => {
    if (open) {
      previouslyFocused =
        document.activeElement instanceof HTMLElement ? document.activeElement : null
      onOpen()
      await nextTick()
      input.value?.focus()
      input.value?.select()
      return
    }
    const restore = previouslyFocused
    previouslyFocused = null
    await nextTick()
    restore?.focus()
  })

  const onFocusIn = (event: FocusEvent) => {
    if (!isOpen.value) return
    if (event.target instanceof Element && event.target.closest(FOCUS_ALLOWED)) return
    input.value?.focus()
  }

  onMounted(() => document.addEventListener('focusin', onFocusIn))
  onBeforeUnmount(() => document.removeEventListener('focusin', onFocusIn))
}
