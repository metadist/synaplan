import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import SharedConversationBanner from '@/components/iam/SharedConversationBanner.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      iam: {
        owner: 'Owner',
        permission: { read: 'Can view', use: 'Can use' },
        continueAsCopy: 'Continue as my copy',
        readOnly: 'You can view this conversation.',
        incoming: {
          owner: 'from {name}',
          sourceGroup: 'This chat belongs to {owner}. It reached you through the group "{name}".',
          sourceEveryone:
            'This chat belongs to {owner}. It was shared with everyone in this organization.',
          sourceDirect: 'This chat belongs to {owner}. It was shared with you personally.',
          pill: {
            group: 'Group',
            groupTitle: 'Shared with the group "{name}"',
            everyone: 'Everyone',
            everyoneTitle: 'Shared with everyone',
            direct: 'Shared with you',
            directTitle: 'Shared with you personally',
            from: 'From {name}',
          },
        },
      },
    },
  },
})

type BannerProps = InstanceType<typeof SharedConversationBanner>['$props']

const mountBanner = (props: Partial<BannerProps> = {}) =>
  mount(SharedConversationBanner, {
    props: {
      ownerName: 'Alice',
      sharedVia: null,
      access: 'use',
      ...props,
    },
    global: { plugins: [i18n], stubs: { Icon: true, ChatKindPill: true } },
  })

describe('SharedConversationBanner', () => {
  it('names the group a shared chat came through', () => {
    const wrapper = mountBanner({
      sharedVia: { type: 'group', name: 'Sales' },
    })
    expect(wrapper.get('[data-testid="text-shared-conversation-source"]').text()).toBe(
      'This chat belongs to Alice. It reached you through the group "Sales".'
    )
    expect(wrapper.get('[data-testid="text-shared-conversation-owner"]').text()).toBe('from Alice')
    expect(wrapper.get('[data-testid="text-shared-conversation-permission"]').text()).toBe(
      'Can use'
    )
  })

  it('explains an everyone share and a personal share', () => {
    expect(
      mountBanner({ sharedVia: { type: 'everyone', name: '' } })
        .get('[data-testid="text-shared-conversation-source"]')
        .text()
    ).toContain('everyone in this organization')
    expect(
      mountBanner({ sharedVia: { type: 'user', name: 'Alice' } })
        .get('[data-testid="text-shared-conversation-source"]')
        .text()
    ).toContain('shared with you personally')
  })

  it('shows Continue as my copy only when the viewer may use the chat', async () => {
    expect(
      mountBanner({ canContinue: false }).find('[data-testid="btn-continue-as-copy"]').exists()
    ).toBe(false)
    const wrapper = mountBanner({ canContinue: true, access: 'use' })
    await wrapper.get('[data-testid="btn-continue-as-copy"]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
  })
})
