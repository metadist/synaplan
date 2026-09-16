import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SharedResourceBanner from '@/components/iam/SharedResourceBanner.vue'

describe('SharedResourceBanner', () => {
  it('names owner, group and kind-specific consequence for an assistant', () => {
    const wrapper = mount(SharedResourceBanner, {
      props: {
        kind: 'assistant',
        ownerName: 'Ada',
        sharedVia: { type: 'group', name: 'Legal' },
        permission: 'use',
      },
      global: { stubs: { Icon: true, ChatKindPill: true } },
    })

    expect(wrapper.get('[data-testid="text-shared-resource-source"]').text()).toBe(
      'This assistant belongs to Ada. It reached you through the group "Legal".'
    )
    expect(wrapper.get('[data-testid="text-shared-resource-owner"]').text()).toContain('Ada')
    expect(wrapper.get('[data-testid="text-shared-resource-permission"]').text()).toBe('Can use')
    expect(wrapper.get('[data-testid="text-shared-resource-consequence"]').text()).toContain(
      'start a chat with this assistant'
    )
  })

  it('explains a shared folder and a personal widget share', () => {
    expect(
      mount(SharedResourceBanner, {
        props: {
          kind: 'knowledge_folder',
          ownerName: 'Ada',
          sharedVia: { type: 'everyone', name: '' },
          permission: 'use',
        },
        global: { stubs: { Icon: true, ChatKindPill: true } },
      })
        .get('[data-testid="text-shared-resource-source"]')
        .text()
    ).toContain('everyone in this organization')

    expect(
      mount(SharedResourceBanner, {
        props: {
          kind: 'widget',
          ownerName: 'Ada',
          sharedVia: { type: 'user', name: 'Ada' },
          permission: 'read',
        },
        global: { stubs: { Icon: true, ChatKindPill: true } },
      })
        .get('[data-testid="text-shared-resource-consequence"]')
        .text()
    ).toContain('open the widget settings')
  })
})
