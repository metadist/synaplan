import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { usePaletteActions } from '@/composables/search/usePaletteActions'
import type { SearchResult, SettingControl } from '@/composables/search/types'

const success = vi.fn()
const error = vi.fn()

vi.mock('@/composables/useNotification', () => ({ useNotification: () => ({ success, error }) }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ resolve: (route: string) => ({ href: route }) }),
}))

const page: SearchResult = {
  id: 'page:/files',
  kind: 'page',
  title: 'Files',
  route: '/files',
  icon: FolderIcon,
  matchedBy: 'local',
}
const toggle: SettingControl = {
  type: 'toggle',
  key: 'FEATURE_IAM_GROUPS_ENABLED',
  current: 'true',
  options: [],
  scope: 'system',
  envPinned: false,
}

function setup(active: SearchResult) {
  const state = {
    active: ref<SearchResult | undefined>(active),
    query: ref('files'),
    select: vi.fn(),
    switchSetting: vi.fn(),
    settingValue: (control: SettingControl) => control.current,
  }
  let actions!: ReturnType<typeof usePaletteActions>
  const wrapper = mount(
    defineComponent({
      setup() {
        actions = usePaletteActions(state)
        return () => h('div')
      },
    })
  )
  const key = (name: string) => new KeyboardEvent('keydown', { key: name, cancelable: true })
  return { state, actions, key, wrapper }
}

describe('usePaletteActions', () => {
  beforeEach(() => vi.clearAllMocks())

  it('offers open, new tab and copy link for a page', () => {
    const { actions } = setup(page)
    expect(actions.actions.value.map((action) => action.id)).toEqual(['open', 'newTab', 'copyLink'])
  })

  it('offers the switch with its direction for a setting, and nothing extra for a pinned one', () => {
    const { actions, state } = setup({ ...page, id: 'setting:x', kind: 'setting', setting: toggle })
    const ids = actions.actions.value.map((action) => action.id)
    expect(ids).toEqual(['open', 'switch', 'newTab', 'copyLink'])
    expect(actions.actions.value[1]?.label).toBe('Turn off')

    state.active.value = { ...page, setting: { ...toggle, envPinned: true } }
    expect(actions.actions.value.map((action) => action.id)).not.toContain('switch')
  })

  it('does not open for a command that can only run', () => {
    const { actions } = setup({ ...page, route: undefined, run: () => {} })
    actions.open()
    expect(actions.isOpen.value).toBe(false)
  })

  it('moves, runs and closes with the keyboard', () => {
    const { actions, state, key } = setup(page)
    expect(actions.handle(key('ArrowDown'))).toBe(false)
    actions.open()
    actions.handle(key('ArrowDown'))
    expect(actions.index.value).toBe(1)
    actions.handle(key('Enter'))
    expect(state.select).toHaveBeenCalledWith(page, true)
    expect(actions.isOpen.value).toBe(false)

    actions.open()
    expect(actions.handle(key('Escape'))).toBe(true)
    expect(actions.isOpen.value).toBe(false)
  })

  it('closes when the highlighted result or the query changes', async () => {
    const { actions, state } = setup(page)
    actions.open()
    state.query.value = 'fil'
    await nextTick()
    expect(actions.isOpen.value).toBe(false)
  })

  it('copies an absolute link and says when the browser refuses', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const { actions } = setup(page)
    await actions.actions.value.find((action) => action.id === 'copyLink')?.run()
    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/files`)
    expect(success).toHaveBeenCalledWith('Link copied.')

    writeText.mockRejectedValue(new Error('denied'))
    await actions.actions.value.find((action) => action.id === 'copyLink')?.run()
    expect(error).toHaveBeenCalledWith(
      'The link could not be copied because the browser blocked the clipboard.'
    )
  })
})
