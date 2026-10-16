import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { IamGroup, IamGroupMember } from '@/services/api/iamApi'

const listMembers = vi.fn()
const listGroupShares = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listMembers: (...args: unknown[]) => listMembers(...args),
    listGroupShares: (...args: unknown[]) => listGroupShares(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import GroupDetailPanel from '@/components/people/GroupDetailPanel.vue'

function group(id: number, name: string): IamGroup {
  return {
    id,
    name,
    slug: name.toLowerCase(),
    description: '',
    kind: 'manual',
    memberCount: 1,
    created: 1,
    updated: 1,
  }
}

function member(userId: number, email: string): IamGroupMember {
  return {
    userId,
    email,
    displayName: email,
    role: 'member',
    source: 'manual',
    created: 1,
  }
}

function deferred<T>() {
  let resolve: (value: T) => void = () => {}
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

describe('GroupDetailPanel', () => {
  beforeEach(() => {
    listMembers.mockReset()
    listGroupShares.mockReset()
    listGroupShares.mockResolvedValue([])
  })

  it('drops a member response for a group that is no longer open', async () => {
    const first = deferred<IamGroupMember[]>()
    const second = deferred<IamGroupMember[]>()
    listMembers.mockImplementation((groupId: number) =>
      groupId === 1 ? first.promise : second.promise
    )

    const wrapper = mount(GroupDetailPanel, {
      props: { group: group(1, 'Sales') },
    })
    await flushPromises()
    expect(wrapper.text()).not.toContain('ada@example.com')

    await wrapper.setProps({ group: group(2, 'Support') })
    await flushPromises()
    expect(wrapper.text()).not.toContain('ada@example.com')

    second.resolve([member(8, 'bea@example.com')])
    await flushPromises()
    expect(wrapper.text()).toContain('bea@example.com')
    expect(wrapper.text()).not.toContain('ada@example.com')

    first.resolve([member(3, 'ada@example.com')])
    await flushPromises()
    expect(wrapper.text()).toContain('bea@example.com')
    expect(wrapper.text()).not.toContain('ada@example.com')
  })
})
