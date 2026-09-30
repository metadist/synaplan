import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import type { SearchResult, SettingControl } from './types'

/**
 * Keyboard model of the palette: arrows, Home/End, Enter (Ctrl/Cmd for a
 * new tab, Shift to switch the active setting), Tab for the action pane,
 * Esc, plus the global Ctrl/Cmd+K shortcut.
 */
export function usePaletteKeys(state: {
  query: Ref<string>
  results: Ref<SearchResult[]>
  activeIndex: Ref<number>
  activeSetting: Ref<SettingControl | null>
  select: (result: SearchResult, newTab: boolean) => void
  switchSetting: (control: SettingControl) => void
  close: () => void
  toggle: () => void
  isOpen: () => boolean
  canOpen: () => boolean
  /** The Tab action pane; it gets the keys first while it is open. */
  actions: { open: () => void; handle: (event: KeyboardEvent) => boolean }
}) {
  const move = (delta: number) => {
    const total = state.results.value.length
    if (total === 0) return
    state.activeIndex.value = (state.activeIndex.value + delta + total) % total
  }

  const jump = (event: KeyboardEvent, index: number) => {
    if (state.results.value.length === 0 || state.query.value !== '') return
    event.preventDefault()
    state.activeIndex.value = index
  }

  const onKeydown = (event: KeyboardEvent) => {
    // A confirmation opened by this key listens for Enter on document; the
    // same keystroke must not reach it, or it confirms itself unseen.
    if (state.actions.handle(event)) {
      event.stopPropagation()
      return
    }
    if (event.key === 'Enter') event.stopPropagation()
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        move(1)
        break
      case 'ArrowUp':
        event.preventDefault()
        move(-1)
        break
      case 'Home':
        jump(event, 0)
        break
      case 'End':
        jump(event, state.results.value.length - 1)
        break
      case 'Enter': {
        event.preventDefault()
        if (event.shiftKey) {
          if (state.activeSetting.value) state.switchSetting(state.activeSetting.value)
          break
        }
        const result = state.results.value[state.activeIndex.value]
        if (result) state.select(result, event.ctrlKey || event.metaKey)
        break
      }
      case 'Escape':
        event.preventDefault()
        state.close()
        break
      case 'Tab':
        event.preventDefault()
        if (!event.shiftKey && state.results.value[state.activeIndex.value]) state.actions.open()
        break
    }
  }

  const onGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key.toLowerCase() !== 'k' || event.altKey || event.shiftKey) return
    if (!(event.metaKey || event.ctrlKey)) return
    if (!state.isOpen() && !state.canOpen()) return
    event.preventDefault()
    state.toggle()
  }

  // Capture phase: inputs that stop propagation (composer palettes, editors)
  // must not swallow the global shortcut.
  onMounted(() => window.addEventListener('keydown', onGlobalKeydown, { capture: true }))
  onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown, { capture: true }))

  return { onKeydown }
}
