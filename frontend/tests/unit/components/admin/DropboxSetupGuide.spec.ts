import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import DropboxSetupGuide from '@/components/admin/DropboxSetupGuide.vue'

const { mockStatus, mockReset, mockConfirm, mockSuccess } = vi.hoisted(() => ({
  mockStatus: vi.fn(),
  mockReset: vi.fn(),
  mockConfirm: vi.fn(),
  mockSuccess: vi.fn(),
}))

vi.mock('@/services/api/dropboxApi', () => ({
  dropboxApi: { status: mockStatus, resetAllConnections: mockReset },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: mockConfirm }),
}))

const mountGuide = async () => {
  const wrapper = mount(DropboxSetupGuide, { global: { stubs: { Icon: true } } })
  await flushPromises()
  return wrapper
}

describe('DropboxSetupGuide', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockStatus.mockResolvedValue({
      available: false,
      redirectUri: 'https://synaplan.example/api/v1/connections/dropbox/callback',
    })
  })

  it('shows the exact redirect URI the Dropbox app has to match', async () => {
    const wrapper = await mountGuide()
    expect(wrapper.get('[data-testid="dropbox-redirect-uri"]').text()).toBe(
      'https://synaplan.example/api/v1/connections/dropbox/callback'
    )
  })

  it('falls back to the current origin when the status call fails', async () => {
    mockStatus.mockRejectedValue(new Error('offline'))
    const wrapper = await mountGuide()
    expect(wrapper.get('[data-testid="dropbox-redirect-uri"]').text()).toContain(
      '/api/v1/connections/dropbox/callback'
    )
  })

  it('tells the admin whether the connector is live yet', async () => {
    expect((await mountGuide()).get('[data-testid="dropbox-readiness"]').text()).toContain(
      'Not active yet'
    )

    mockStatus.mockResolvedValue({ available: true, redirectUri: 'https://x/callback' })
    expect((await mountGuide()).get('[data-testid="dropbox-readiness"]').text()).toContain('Ready')
  })

  it('lists files.content.write among the permissions to enable', async () => {
    expect((await mountGuide()).text()).toContain('files.content.write')
  })

  it('resets all Dropbox connections only after a confirmed danger dialog', async () => {
    mockConfirm.mockResolvedValue(true)
    mockReset.mockResolvedValue(3)

    const wrapper = await mountGuide()
    await wrapper.get('[data-testid="btn-reset-dropbox-connections"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledWith(expect.objectContaining({ danger: true }))
    expect(mockReset).toHaveBeenCalledTimes(1)
    expect(mockSuccess).toHaveBeenCalledWith(expect.stringContaining('3'))
  })

  it('does not touch any connection when the admin cancels the dialog', async () => {
    mockConfirm.mockResolvedValue(false)

    const wrapper = await mountGuide()
    await wrapper.get('[data-testid="btn-reset-dropbox-connections"]').trigger('click')
    await flushPromises()

    expect(mockReset).not.toHaveBeenCalled()
  })
})
