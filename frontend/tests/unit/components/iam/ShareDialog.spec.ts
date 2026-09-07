import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ShareDialog from '@/components/iam/ShareDialog.vue'
import { iamApi } from '@/services/api/iamApi'
import type { ShareKind } from '@/utils/shareCopy'

const { showSuccess, showError } = vi.hoisted(() => ({
  showSuccess: vi.fn(),
  showError: vi.fn(),
}))

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listShares: vi.fn().mockResolvedValue([]),
    searchSubjects: vi.fn().mockResolvedValue([]),
    grantShare: vi.fn(),
    revokeShare: vi.fn(),
  },
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({
    confirm: vi.fn().mockResolvedValue(false),
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    error: showError,
    success: showSuccess,
  }),
}))

const mountDialog = (
  overrides: {
    isOpen?: boolean
    kind?: ShareKind
    resourceName?: string
    ownerName?: string
  } = {}
) =>
  mount(ShareDialog, {
    props: {
      isOpen: overrides.isOpen ?? true,
      kind: overrides.kind ?? 'conversation',
      resourceId: '1',
      resourceName: overrides.resourceName ?? 'Q3 playbook',
      ownerName: overrides.ownerName ?? 'Ada',
    },
    global: {
      stubs: {
        Teleport: true,
        Transition: false,
      },
    },
  })

describe('ShareDialog', () => {
  beforeEach(() => {
    showSuccess.mockReset()
    showError.mockReset()
    vi.mocked(iamApi.listShares).mockResolvedValue([])
    vi.mocked(iamApi.searchSubjects).mockResolvedValue([])
  })

  it('does not render when closed', () => {
    const wrapper = mountDialog({ isOpen: false })

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(false)
  })

  it('renders the one-row add pattern with owner and kind-specific copy', () => {
    const wrapper = mountDialog()

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="iam-share-add-row"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="iam-share-owner"]').text()).toContain('Ada')
    expect(wrapper.get('[data-testid="iam-share-consequence"]').text()).toContain(
      'continue this chat as their own copy'
    )
    expect(wrapper.get('[data-testid="iam-share-find"]').text()).toContain('Incoming chats')
    expect(wrapper.get('[data-testid="iam-share-empty"]').text()).toBe('Only you can see this.')
  })

  it('uses assistant copy and hides the public-link section', () => {
    const wrapper = mountDialog({ kind: 'assistant', resourceName: 'Contract review' })

    expect(wrapper.get('[data-testid="iam-share-consequence"]').text()).toContain(
      'start a chat with this assistant'
    )
    expect(wrapper.get('[data-testid="iam-share-find"]').text()).toContain('Shared with me')
    expect(wrapper.find('[data-testid="btn-iam-public-link"]').exists()).toBe(false)
  })

  it('offers the public-link action for conversations', () => {
    const wrapper = mountDialog()

    expect(wrapper.get('[data-testid="btn-iam-public-link"]').text()).toContain('public link')
  })

  it('shows a retry action when listing shares fails', async () => {
    vi.mocked(iamApi.listShares).mockRejectedValueOnce(new Error('network'))

    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="iam-share-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="iam-share-empty"]').exists()).toBe(false)

    vi.mocked(iamApi.listShares).mockResolvedValueOnce([])
    await wrapper.get('[data-testid="btn-iam-share-retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="iam-share-empty"]').text()).toBe('Only you can see this.')
  })

  it('names the recipient path after a successful share', async () => {
    vi.mocked(iamApi.searchSubjects).mockResolvedValue([
      { type: 'group', id: 1, name: 'Sales', pinned: false },
    ])
    vi.mocked(iamApi.grantShare).mockResolvedValue({
      id: 9,
      kind: 'conversation',
      resourceId: '1',
      subjectType: 'group',
      subjectId: 1,
      permission: 'use',
      name: 'Sales',
    })
    vi.mocked(iamApi.listShares)
      .mockResolvedValueOnce([])
      .mockResolvedValueOnce([
        {
          id: 9,
          kind: 'conversation',
          resourceId: '1',
          subjectType: 'group',
          subjectId: 1,
          permission: 'use',
          name: 'Sales',
        },
      ])

    vi.useFakeTimers()
    try {
      const wrapper = mountDialog()
      await vi.advanceTimersByTimeAsync(250)
      await flushPromises()

      await wrapper.get('[data-testid="input-iam-subject-search"]').trigger('focus')
      await wrapper.get('[data-testid="btn-iam-subject-group-1"]').trigger('click')
      await wrapper.get('[data-testid="btn-iam-share-confirm"]').trigger('click')
      await flushPromises()

      expect(showSuccess).toHaveBeenCalledWith(
        expect.stringMatching(/Shared with Sales.*Incoming chats/)
      )
      wrapper.unmount()
    } finally {
      vi.useRealTimers()
    }
  })

  it('saves an in-list permission change and restores the previous value on failure', async () => {
    vi.mocked(iamApi.listShares).mockResolvedValue([
      {
        id: 9,
        kind: 'conversation',
        resourceId: '1',
        subjectType: 'group',
        subjectId: 1,
        permission: 'use',
        name: 'Sales',
      },
    ])

    const wrapper = mountDialog()
    await flushPromises()

    const rowSelects = wrapper.findAll('[data-testid="iam-permission-select"]')
    expect(rowSelects.length).toBeGreaterThan(1)
    await rowSelects[1].get('[data-testid="btn-iam-permission"]').trigger('click')
    vi.mocked(iamApi.grantShare).mockResolvedValueOnce({
      id: 9,
      kind: 'conversation',
      resourceId: '1',
      subjectType: 'group',
      subjectId: 1,
      permission: 'read',
      name: 'Sales',
    })
    await rowSelects[1].get('[data-testid="btn-iam-permission-read"]').trigger('click')
    await flushPromises()

    expect(iamApi.grantShare).toHaveBeenCalledWith(
      expect.objectContaining({ permission: 'read', subjectType: 'group', subjectId: 1 })
    )

    vi.mocked(iamApi.grantShare).mockRejectedValueOnce(new Error('nope'))
    await rowSelects[1].get('[data-testid="btn-iam-permission"]').trigger('click')
    await rowSelects[1].get('[data-testid="btn-iam-permission-use"]').trigger('click')
    await flushPromises()

    expect(showError).toHaveBeenCalled()
    wrapper.unmount()
  })
})
