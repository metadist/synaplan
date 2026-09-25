import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { IamGroup } from '@/services/api/iamApi'

const listAdminGroups = vi.fn()
const listGroupShares = vi.fn()
const getGroupConfig = vi.fn()
const deleteGroup = vi.fn()
const confirmDelete = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listAdminGroups: (...args: unknown[]) => listAdminGroups(...args),
    listGroupShares: (...args: unknown[]) => listGroupShares(...args),
    getGroupConfig: (...args: unknown[]) => getGroupConfig(...args),
    deleteGroup: (...args: unknown[]) => deleteGroup(...args),
    createGroup: vi.fn(),
    updateGroup: vi.fn(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({
    confirm: (...args: unknown[]) => confirmDelete(...args),
    prompt: vi.fn(),
  }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import GroupsTab from '@/components/people/GroupsTab.vue'

const sales: IamGroup = {
  id: 4,
  name: 'Sales',
  slug: 'sales',
  description: '',
  kind: 'manual',
  memberCount: 2,
  created: 1,
  updated: 1,
}

describe('GroupsTab delete confirmation', () => {
  beforeEach(() => {
    listAdminGroups.mockReset()
    listGroupShares.mockReset()
    getGroupConfig.mockReset()
    deleteGroup.mockReset()
    confirmDelete.mockReset()
    listAdminGroups.mockResolvedValue([sales])
    confirmDelete.mockResolvedValue(false)
  })

  it('names how many shares end and how many policies are removed', async () => {
    listGroupShares.mockResolvedValue([{ id: '1' }, { id: '2' }])
    getGroupConfig.mockResolvedValue({
      settings: {
        'MODELS.ALLOWED': { value: ['chat'], source: 'group', locked: false },
        'DEFAULTMODEL.CHAT': { value: null, source: 'admin', locked: false },
        'MODELS.SORT': { value: null, source: null, locked: false },
      },
      conflicts: {},
    })

    const wrapper = mount(GroupsTab, {
      global: { stubs: { GroupDetailPanel: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="btn-delete-group-4"]').trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalledWith(
      expect.objectContaining({
        message:
          'Delete the group "Sales"? People stay in the instance. 2 shares to this group end, and 1 policy is removed.',
      })
    )
    expect(deleteGroup).not.toHaveBeenCalled()
  })

  it('uses the generic confirmation when the share list fails', async () => {
    listGroupShares.mockRejectedValue(new Error('shares unavailable'))
    getGroupConfig.mockResolvedValue({ settings: {}, conflicts: {} })

    const wrapper = mount(GroupsTab, {
      global: { stubs: { GroupDetailPanel: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="btn-delete-group-4"]').trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalledWith(
      expect.objectContaining({
        message:
          'Delete the group "Sales"? People stay in the instance. Shares to this group end, and the group\'s policies are removed.',
      })
    )
    expect(deleteGroup).not.toHaveBeenCalled()
  })

  it('uses the generic confirmation when the policy list fails', async () => {
    listGroupShares.mockResolvedValue([])
    getGroupConfig.mockRejectedValue(new Error('config unavailable'))

    const wrapper = mount(GroupsTab, {
      global: { stubs: { GroupDetailPanel: true } },
    })
    await flushPromises()
    await wrapper.get('[data-testid="btn-delete-group-4"]').trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalledWith(
      expect.objectContaining({
        message:
          'Delete the group "Sales"? People stay in the instance. Shares to this group end, and the group\'s policies are removed.',
      })
    )
    expect(deleteGroup).not.toHaveBeenCalled()
  })

  it('ignores a second delete click while the first confirmation is open', async () => {
    listGroupShares.mockResolvedValue([])
    getGroupConfig.mockResolvedValue({ settings: {}, conflicts: {} })
    let resolveConfirm: (value: boolean) => void = () => {}
    confirmDelete.mockImplementation(
      () =>
        new Promise<boolean>((resolve) => {
          resolveConfirm = resolve
        })
    )

    const wrapper = mount(GroupsTab, {
      global: { stubs: { GroupDetailPanel: true } },
    })
    await flushPromises()
    const button = wrapper.get('[data-testid="btn-delete-group-4"]')
    void button.trigger('click')
    void button.trigger('click')
    await flushPromises()

    expect(confirmDelete).toHaveBeenCalledTimes(1)
    resolveConfirm(false)
    await flushPromises()
    expect(deleteGroup).not.toHaveBeenCalled()
  })
})
