import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import DesktopConfiguration from '@/components/config/DesktopConfiguration.vue'

const { mockListJobs, mockReload, desktopOn, confirmMock, successMock, revokeDevice } = vi.hoisted(
  () => ({
    mockListJobs: vi.fn(),
    mockReload: vi.fn(),
    desktopOn: { value: true },
    confirmMock: vi.fn(),
    successMock: vi.fn(),
    revokeDevice: vi.fn(),
  })
)

const devicesRef = ref<
  Array<{
    id: number
    name: string
    status: string
    lastSeen: number
    created: number
    keyPrefix: string | null
    capabilities: string[]
  }>
>([])

vi.mock('@/services/api/desktopApi', () => ({
  desktopApi: {
    listJobs: mockListJobs,
    listDevices: vi.fn().mockResolvedValue([]),
    createPairingCode: vi.fn(),
    revokeDevice,
  },
}))

vi.mock('@/composables/useDesktopDevices', () => ({
  useDesktopDevices: () => ({ devices: devicesRef, reload: mockReload }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: successMock, error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: confirmMock }),
}))

vi.mock('@/composables/useDesktopAgentFeature', () => ({
  isDesktopAgentEnabled: () => desktopOn.value,
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
    desktopOn.value = true
    devicesRef.value = []
    mockListJobs.mockResolvedValue([])
    mockReload.mockResolvedValue(undefined)
    confirmMock.mockResolvedValue(false)
    revokeDevice.mockResolvedValue({ cancelledJobs: 0 })
  })

  it('is absent when desktop is off', async () => {
    desktopOn.value = false
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="page-config-desktop"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-pair"]').exists()).toBe(false)
    expect(mockReload).not.toHaveBeenCalled()
    expect(mockListJobs).not.toHaveBeenCalled()
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

  it('tells how many waiting tasks disconnect will cancel', async () => {
    devicesRef.value = [
      {
        id: 4,
        name: 'Studio Mac',
        status: 'active',
        lastSeen: 0,
        created: 1,
        keyPrefix: 'sk_abcd...',
        capabilities: [],
      },
    ]
    mockListJobs.mockResolvedValue([
      { id: 1, deviceId: 4, status: 'queued', skill: 'pptx', created: 1 },
      { id: 2, deviceId: 4, status: 'leased', skill: 'pptx', created: 2 },
      { id: 3, deviceId: 9, status: 'queued', skill: 'notes', created: 3 },
    ])
    confirmMock.mockResolvedValue(true)
    revokeDevice.mockResolvedValue({ cancelledJobs: 2 })

    const wrapper = await mountPage()
    expect(wrapper.get('[data-testid="item-device"]').text()).toContain('2')

    await wrapper.get('[data-testid="btn-disconnect"]').trigger('click')
    await flushPromises()

    const message = confirmMock.mock.calls[0][0].message as string
    expect(message).toContain('Studio Mac')
    expect(message).toContain('2 waiting tasks will be cancelled')
    expect(revokeDevice).toHaveBeenCalledWith(4)
    expect(successMock).toHaveBeenCalledWith(
      'This computer was disconnected. 2 waiting tasks were cancelled and will not run.'
    )
  })
})
