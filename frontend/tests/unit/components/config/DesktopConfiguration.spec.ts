import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import DesktopConfiguration from '@/components/config/DesktopConfiguration.vue'

const { mockListJobs, mockReload } = vi.hoisted(() => ({
  mockListJobs: vi.fn(),
  mockReload: vi.fn(),
}))

vi.mock('@/services/api/desktopApi', () => ({
  desktopApi: {
    listJobs: mockListJobs,
    listDevices: vi.fn().mockResolvedValue([]),
    createPairingCode: vi.fn(),
    revokeDevice: vi.fn(),
  },
}))

vi.mock('@/composables/useDesktopDevices', () => ({
  useDesktopDevices: () => ({ devices: ref([]), reload: mockReload }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

const REPO = 'https://github.com/metadist/synaplan-desktop'

const mountPage = async () => {
  const wrapper = mount(DesktopConfiguration, {
    global: {
      stubs: { Icon: true, Teleport: true, Transition: false },
    },
  })
  await flushPromises()
  return wrapper
}

describe('DesktopConfiguration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockListJobs.mockResolvedValue([])
    mockReload.mockResolvedValue(undefined)
  })

  it('links to the public desktop repository and its releases as a beta', async () => {
    const wrapper = await mountPage()
    const github = wrapper.get('[data-testid="link-desktop-github"]')
    expect(github.attributes('href')).toBe(REPO)
    expect(github.attributes('target')).toBe('_blank')
    expect(github.attributes('rel')).toContain('noopener')
    expect(wrapper.get('[data-testid="link-desktop-releases"]').attributes('href')).toBe(
      `${REPO}/releases`
    )
    expect(wrapper.get('[data-testid="badge-desktop-beta"]').text()).toBe('Beta')
  })

  it('names the three supported operating systems', async () => {
    const wrapper = await mountPage()
    const card = wrapper.get('[data-testid="card-get-desktop"]').text()
    for (const os of ['macOS', 'Windows', 'Linux']) {
      expect(card).toContain(os)
    }
  })

  it('explains how to connect in three steps and drops the old "not available" note', async () => {
    const wrapper = await mountPage()
    expect(wrapper.get('[data-testid="section-desktop-steps"]').findAll('li')).toHaveLength(3)
    expect(wrapper.find('[data-testid="note-not-available"]').exists()).toBe(false)
  })
})
