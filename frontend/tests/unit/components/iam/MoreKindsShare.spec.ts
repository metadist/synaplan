import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ShareDialog from '@/components/iam/ShareDialog.vue'

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
    error: vi.fn(),
    success: vi.fn(),
  }),
}))

describe('ShareDialog more kinds', () => {
  it('opens for an assistant without a public-link section', () => {
    const wrapper = mount(ShareDialog, {
      props: {
        isOpen: true,
        kind: 'assistant',
        resourceId: '12',
        resourceName: 'Sales Helper',
      },
      global: {
        stubs: { Teleport: true, Transition: false },
      },
    })

    expect(wrapper.find('[data-testid="modal-iam-share"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-iam-public-link"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="iam-share-find"]').text()).toContain('Shared with me')
  })
})
