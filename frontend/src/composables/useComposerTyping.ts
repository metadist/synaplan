import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Bumped when the chat surface should put the caret in the composer:
 * refresh, opening Chats, or switching threads.
 */
const focusRequest = ref(0)

export function requestComposerFocus(): void {
  focusRequest.value += 1
}

const TEXT_ENTRY = 'input, textarea, select, [contenteditable="true"]'

/** True when the key would already land in a field the user is editing. */
export function isTextEntry(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable) return true
  return target.matches(TEXT_ENTRY) || Boolean(target.closest(TEXT_ENTRY))
}

function openLayerSelector(): string {
  return '[aria-modal="true"], .dropdown-up, [role="dialog"], [role="listbox"], [role="menu"]'
}

/** A dialog or open menu is on screen, so the composer must not take the keys. */
function overlayIsOpen(): boolean {
  return document.querySelector('[aria-modal="true"], .dropdown-up, [role="menu"]') !== null
}

function insideOpenLayer(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) return false
  return target.closest(openLayerSelector()) !== null
}

function isActivatable(target: EventTarget | null): boolean {
  if (!(target instanceof Element)) return false
  return (
    target.closest(
      'button, a, summary, [role="button"], [role="link"], [role="menuitem"], [role="option"], [role="tab"], [role="checkbox"], [role="switch"]'
    ) !== null
  )
}

/**
 * The character to drop into the composer, or null when this key belongs
 * to whatever is already focused (a field, a shortcut, a button's Space).
 */
export function typingKeyForComposer(event: KeyboardEvent): string | null {
  if (event.isComposing || event.metaKey || event.ctrlKey || event.altKey) return null
  const key = event.key
  if (!key || key.length !== 1) return null
  const target = event.target instanceof Element ? event.target : document.activeElement
  if (isTextEntry(target) || insideOpenLayer(target) || overlayIsOpen()) return null
  // Space activates the focused control. A letter still starts the message.
  if (key === ' ' && isActivatable(target)) return null
  return key
}

/** Move the caret unless the user is already in a field or a dialog is open. */
export function shouldFocusComposer(active: Element | null, composer: HTMLElement | null): boolean {
  if (!composer || composer.hasAttribute('disabled')) return false
  if (overlayIsOpen()) return false
  if (active === composer || composer.contains(active)) return false
  if (isTextEntry(active)) return false
  return true
}

/**
 * Focus the composer when the chat surface asks, and route a typed
 * character into it when focus is sitting on the page chrome.
 */
export function useComposerTyping(options: {
  getElement: () => HTMLTextAreaElement | null
  getText: () => string
  setText: (value: string) => void
}): void {
  const focusComposer = () => {
    const el = options.getElement()
    if (!el || !shouldFocusComposer(document.activeElement, el)) return
    el.focus({ preventScroll: true })
  }

  const onKeydown = (event: KeyboardEvent) => {
    const key = typingKeyForComposer(event)
    if (key === null) return
    const el = options.getElement()
    if (!el || el.disabled) return
    event.preventDefault()
    const next = options.getText() + key
    options.setText(next)
    el.focus({ preventScroll: true })
    el.value = next
    el.setSelectionRange(next.length, next.length)
  }

  watch(focusRequest, () => {
    focusComposer()
  })

  onMounted(() => {
    focusComposer()
    window.addEventListener('keydown', onKeydown, true)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown, true)
  })
}

/** Wire the chat textarea: focus on land, and typed keys when focus is on the chrome. */
export function bindComposerTyping(
  getComponent: () => { $el?: unknown } | null,
  getText: () => string,
  setText: (value: string) => void
): void {
  useComposerTyping({
    getElement: () => {
      const root = getComponent()?.$el
      return root instanceof HTMLTextAreaElement ? root : null
    },
    getText,
    setText,
  })
}
