import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue'

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Keeps Tab and Shift+Tab inside an open modal panel, calls `onEscape` on
 * Escape, and gives focus back to the control that opened the modal.
 */
export function useModalFocus(
  panel: Ref<HTMLElement | null>,
  open: Ref<boolean>,
  onEscape: () => void
) {
  let opener: HTMLElement | null = null

  function focusable(): HTMLElement[] {
    return panel.value ? Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)) : []
  }

  function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
      event.preventDefault()
      onEscape()
      return
    }
    if (event.key !== 'Tab') return
    const nodes = focusable()
    if (!nodes.length) {
      event.preventDefault()
      return
    }
    const first = nodes[0]
    const last = nodes[nodes.length - 1]
    const active = document.activeElement
    const outside = !panel.value?.contains(active)
    if (event.shiftKey && (active === first || outside)) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && (active === last || outside)) {
      event.preventDefault()
      first.focus()
    }
  }

  function stop() {
    document.removeEventListener('keydown', onKeydown)
  }

  watch(open, (isOpen) => {
    if (isOpen) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
      document.addEventListener('keydown', onKeydown)
      return
    }
    stop()
    const restore = opener
    opener = null
    void nextTick(() => {
      if (restore?.isConnected) restore.focus()
    })
  })

  onBeforeUnmount(stop)
}
