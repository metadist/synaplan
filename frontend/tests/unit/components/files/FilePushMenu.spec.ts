import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import FilePushMenu from '@/components/files/FilePushMenu.vue'

const { mockPushWorkspace, mockSuccess, mockError } = vi.hoisted(() => ({
  mockPushWorkspace: vi.fn(),
  mockSuccess: vi.fn(),
  mockError: vi.fn(),
}))

vi.mock('@/services/cloudFolderPushService', async () => {
  const actual = await vi.importActual<typeof import('@/services/cloudFolderPushService')>(
    '@/services/cloudFolderPushService'
  )
  return {
    ...actual,
    pushWorkspaceFile: (...args: unknown[]) => mockPushWorkspace(...args),
    pushGeneratedFile: vi.fn(),
  }
})

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

const nextcloud = {
  id: 12,
  name: 'Office files',
  kind: 'nextcloud' as const,
  folder: 'Synaplan',
}

describe('FilePushMenu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPushWorkspace.mockResolvedValue({
      success: true,
      destination: 'webdav',
      reference: 'Synaplan/probe.txt',
    })
  })

  it('is absent when there is no Nextcloud or OpenCloud folder', () => {
    const wrapper = mount(FilePushMenu, {
      props: {
        targets: [],
        fileName: 'probe.txt',
        source: 'workspace',
        path: 'probe.txt',
      },
    })
    expect(wrapper.find('[data-testid="file-push-menu"]').exists()).toBe(false)
  })

  it('copies a workspace file through the chosen folder', async () => {
    const wrapper = mount(FilePushMenu, {
      attachTo: document.body,
      props: {
        targets: [nextcloud],
        fileName: 'probe.txt',
        source: 'workspace',
        path: 'probe.txt',
        size: 'row',
      },
    })

    const trigger = wrapper.find('[data-testid="btn-file-push"]')
    expect(trigger.attributes('aria-label')).toBeTruthy()
    expect(trigger.text()).toBe('')

    await trigger.trigger('click')
    await flushPromises()

    const choice = document.querySelector<HTMLButtonElement>(
      '[data-testid="btn-file-push-target-12"]'
    )
    expect(choice).not.toBeNull()
    choice?.click()
    await flushPromises()

    expect(mockPushWorkspace).toHaveBeenCalledWith('probe.txt', 12)
    expect(mockSuccess).toHaveBeenCalled()
    wrapper.unmount()
  })
})
