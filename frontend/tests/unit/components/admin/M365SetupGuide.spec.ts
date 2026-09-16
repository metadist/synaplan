import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import M365SetupGuide from '@/components/admin/M365SetupGuide.vue'

const { mockStatus } = vi.hoisted(() => ({ mockStatus: vi.fn() }))

vi.mock('@/services/api/m365Api', () => ({
  m365Api: { status: mockStatus },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const mountGuide = async () => {
  const wrapper = mount(M365SetupGuide, { global: { stubs: { Icon: true } } })
  await flushPromises()
  return wrapper
}

describe('M365SetupGuide', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockStatus.mockResolvedValue({
      available: false,
      redirectUri: 'https://synaplan.example/api/v1/connections/m365/callback',
    })
  })

  it('shows the exact redirect URI Azure has to match', async () => {
    const wrapper = await mountGuide()
    expect(wrapper.get('[data-testid="m365-redirect-uri"]').text()).toBe(
      'https://synaplan.example/api/v1/connections/m365/callback'
    )
  })

  it('falls back to the current origin when the status call fails', async () => {
    mockStatus.mockRejectedValue(new Error('offline'))
    const wrapper = await mountGuide()
    expect(wrapper.get('[data-testid="m365-redirect-uri"]').text()).toContain(
      '/api/v1/connections/m365/callback'
    )
  })

  it('tells the admin whether the connector is live yet', async () => {
    expect((await mountGuide()).get('[data-testid="m365-readiness"]').text()).toContain(
      'Not active yet'
    )

    mockStatus.mockResolvedValue({ available: true, redirectUri: 'https://x/callback' })
    expect((await mountGuide()).get('[data-testid="m365-readiness"]').text()).toContain('Ready')
  })

  it('lists offline_access among the permissions to add', async () => {
    expect((await mountGuide()).text()).toContain('offline_access')
  })
})
