import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SettingControl } from '@/composables/search/types'

const { mockConfirm, mockPush, mockError, mockUpdate, mockReload, knownKeys } = vi.hoisted(() => ({
  mockConfirm: vi.fn(),
  mockPush: vi.fn(),
  mockError: vi.fn(),
  mockUpdate: vi.fn(),
  mockReload: vi.fn(),
  knownKeys: new Set<string>(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string>) =>
      params ? `${key} ${JSON.stringify(params)}` : key,
    te: (key: string) => knownKeys.has(key),
  }),
}))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm: mockConfirm }) }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ push: mockPush, error: mockError }),
}))
vi.mock('@/services/api/adminConfigApi', () => ({ updateConfigValue: mockUpdate }))
vi.mock('@/stores/config', () => ({ useConfigStore: () => ({ reload: mockReload }) }))

const { useInlineSetting } = await import('@/composables/search/useInlineSetting')

const groups = (overrides: Partial<SettingControl> = {}): SettingControl => ({
  type: 'toggle',
  key: 'FEATURE_IAM_GROUPS_ENABLED',
  current: 'false',
  options: [],
  scope: 'system',
  envPinned: false,
  ...overrides,
})

describe('useInlineSetting', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    knownKeys.clear()
    mockConfirm.mockResolvedValue(true)
    mockUpdate.mockResolvedValue({ success: true })
    mockReload.mockResolvedValue(undefined)
  })

  it('writes nothing when the admin cancels the confirmation', async () => {
    mockConfirm.mockResolvedValue(false)
    const inline = useInlineSetting()
    await inline.apply(groups(), 'true')
    expect(mockConfirm).toHaveBeenCalledOnce()
    expect(mockConfirm.mock.calls[0]![0].message).toContain('search.palette.setting.consequence.on')
    expect(mockUpdate).not.toHaveBeenCalled()
  })

  it('names the consequence of this very setting when one is written for it', async () => {
    knownKeys.add('search.palette.setting.consequence.FEATURE_IAM_GROUPS_ENABLED.false')
    const inline = useInlineSetting()
    await inline.apply(groups({ current: 'true' }), 'false')
    expect(mockConfirm.mock.calls[0]![0].message).toBe(
      'search.palette.setting.consequence.FEATURE_IAM_GROUPS_ENABLED.false'
    )
  })

  it('saves through the admin config endpoint and offers Undo', async () => {
    const inline = useInlineSetting()
    const control = groups()
    await inline.apply(control, 'true')

    expect(mockUpdate).toHaveBeenCalledWith('FEATURE_IAM_GROUPS_ENABLED', 'true')
    expect(inline.valueOf(control)).toBe('true')
    expect(mockReload).toHaveBeenCalledOnce()
    const toast = mockPush.mock.calls[0]![0]
    expect(toast.type).toBe('success')
    expect(toast.action.label).toBe('search.palette.setting.undo')

    toast.action.onClick()
    await vi.waitFor(() =>
      expect(mockUpdate).toHaveBeenLastCalledWith('FEATURE_IAM_GROUPS_ENABLED', 'false')
    )
    await vi.waitFor(() => expect(inline.valueOf(control)).toBe('false'))
    expect(mockConfirm).toHaveBeenCalledOnce()
    expect(mockPush.mock.calls[1]![0].message).toContain('search.palette.setting.restored')
  })

  it('keeps the old value and says so when the save fails', async () => {
    mockUpdate.mockResolvedValue({ success: false, error: 'nope' })
    const inline = useInlineSetting()
    const control = groups()
    await inline.apply(control, 'true')

    expect(inline.valueOf(control)).toBe('false')
    expect(mockError).toHaveBeenCalledWith(expect.stringContaining('search.palette.setting.failed'))
    expect(mockError.mock.calls[0]![0]).toContain('common.disabled')
    expect(mockPush).not.toHaveBeenCalled()
  })

  it('treats a network error like a failed save', async () => {
    mockUpdate.mockRejectedValue(new Error('offline'))
    const inline = useInlineSetting()
    await inline.apply(groups(), 'true')
    expect(mockError).toHaveBeenCalledOnce()
  })

  it('never touches a setting the server pins', async () => {
    const inline = useInlineSetting()
    await inline.cycle(groups({ envPinned: true }))
    expect(mockConfirm).not.toHaveBeenCalled()
    expect(mockUpdate).not.toHaveBeenCalled()
  })

  it('moves a choice to the next option from the keyboard', async () => {
    const inline = useInlineSetting()
    const control = groups({
      type: 'select',
      key: 'CHAT_MODE',
      current: 'b',
      options: ['a', 'b', 'c'],
    })
    await inline.cycle(control)
    expect(mockUpdate).toHaveBeenCalledWith('CHAT_MODE', 'c')
    await inline.cycle(control)
    expect(mockUpdate).toHaveBeenLastCalledWith('CHAT_MODE', 'a')
  })
})
