import { computed, ref, watch, type Component, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  AdjustmentsHorizontalIcon,
  ArrowRightIcon,
  ArrowTopRightOnSquareIcon,
  ClipboardDocumentIcon,
} from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
import type { SearchResult, SettingControl } from './types'

export interface PaletteAction {
  id: 'open' | 'newTab' | 'copyLink' | 'switch'
  label: string
  /** Keyboard shortcut that does the same from the list. */
  shortcut?: string
  icon: Component
  run: () => unknown
}

/**
 * What can be done with the highlighted result: shown in the Tab pane and
 * as buttons in the preview column. Every action also has a shortcut on the
 * list itself, so the pane only makes them discoverable.
 */
export function usePaletteActions(state: {
  active: Ref<SearchResult | undefined>
  query: Ref<string>
  select: (result: SearchResult, newTab: boolean) => void
  switchSetting: (control: SettingControl) => void
  settingValue: (control: SettingControl) => string
}) {
  const { t } = useI18n()
  const router = useRouter()
  const { success, error: showError } = useNotification()

  const isOpen = ref(false)
  const index = ref(0)

  const copyLink = async (route: string) => {
    try {
      await navigator.clipboard.writeText(
        new URL(router.resolve(route).href, window.location.origin).href
      )
      success(t('search.palette.actions.linkCopied'))
    } catch {
      showError(t('search.palette.actions.copyFailed'))
    }
  }

  const switchLabel = (control: SettingControl) => {
    if (control.type !== 'toggle') return t('search.palette.actions.change')
    return state.settingValue(control) === 'true'
      ? t('search.palette.actions.turnOff')
      : t('search.palette.actions.turnOn')
  }

  const actions = computed<PaletteAction[]>(() => {
    const result = state.active.value
    if (!result) return []
    const list: PaletteAction[] = [
      {
        id: 'open',
        label: t(
          result.route && !result.run ? 'search.palette.actions.open' : 'search.palette.actions.run'
        ),
        shortcut: '↵',
        icon: ArrowRightIcon,
        run: () => state.select(result, false),
      },
    ]
    const setting = result.setting
    if (setting && !setting.envPinned) {
      list.push({
        id: 'switch',
        label: switchLabel(setting),
        shortcut: '⇧↵',
        icon: AdjustmentsHorizontalIcon,
        run: () => state.switchSetting(setting),
      })
    }
    const route = result.route
    if (route && !result.run) {
      list.push(
        {
          id: 'newTab',
          label: t('search.palette.actions.newTab'),
          shortcut: 'Ctrl ↵',
          icon: ArrowTopRightOnSquareIcon,
          run: () => state.select(result, true),
        },
        {
          id: 'copyLink',
          label: t('search.palette.actions.copyLink'),
          icon: ClipboardDocumentIcon,
          run: () => copyLink(route),
        }
      )
    }
    return list
  })

  const close = () => {
    isOpen.value = false
  }

  /** Only "open" would be pointless: Enter already does that. */
  const open = () => {
    if (actions.value.length < 2) return
    index.value = 0
    isOpen.value = true
  }

  const runAt = (position: number) => {
    const action = actions.value[position]
    if (!action) return
    close()
    void action.run()
  }

  /** Keys while the pane is open; true when the pane handled the key. */
  const handle = (event: KeyboardEvent): boolean => {
    if (!isOpen.value) return false
    const total = actions.value.length
    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp':
        event.preventDefault()
        index.value = (index.value + (event.key === 'ArrowDown' ? 1 : -1) + total) % total
        return true
      case 'Enter':
        event.preventDefault()
        runAt(index.value)
        return true
      case 'Escape':
      case 'Tab':
      case 'ArrowLeft':
        event.preventDefault()
        close()
        return true
      default:
        return false
    }
  }

  watch([() => state.active.value?.id, state.query], close)

  return { actions, isOpen, index, open, close, runAt, handle }
}
