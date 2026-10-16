import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ChatKindPill from '@/components/iam/ChatKindPill.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      iam: {
        incoming: {
          new: 'New',
          pill: {
            private: 'Owned',
            privateTitle: 'You own this chat. Sharing it does not change that.',
            group: 'Group',
            groupTitle: 'Shared with the group "{name}"',
            groupTitleGeneric: 'Shared with a group',
            everyone: 'Everyone',
            everyoneTitle: 'Shared with everyone',
            direct: 'Shared with you',
            directTitle: 'Shared with you personally',
            from: 'From {name}',
            widget: 'Widget',
            widgetTitle: 'A visitor conversation',
          },
        },
      },
    },
  },
})

type PillProps = InstanceType<typeof ChatKindPill>['$props']

const mountPill = (props: PillProps) =>
  mount(ChatKindPill, {
    props,
    global: { plugins: [i18n], stubs: { Icon: true } },
  })

describe('ChatKindPill', () => {
  it('labels my own chats as owned', () => {
    const wrapper = mountPill({ kind: 'private' })
    expect(wrapper.text()).toBe('Owned')
    expect(wrapper.attributes('data-testid')).toBe('pill-chat-kind-private')
    expect(wrapper.attributes('title')).toBe('You own this chat. Sharing it does not change that.')
  })

  it('shows the group name for a group share', () => {
    const wrapper = mountPill({ kind: 'group', label: 'Sales' })
    expect(wrapper.text()).toBe('Sales')
    expect(wrapper.attributes('title')).toBe('Shared with the group "Sales"')
  })

  it('falls back to a generic group label', () => {
    const wrapper = mountPill({ kind: 'group', label: '  ' })
    expect(wrapper.text()).toBe('Group')
    expect(wrapper.attributes('title')).toBe('Shared with a group')
  })

  it('names the sender for a direct share', () => {
    expect(mountPill({ kind: 'direct', label: 'Alice' }).text()).toBe('From Alice')
    expect(mountPill({ kind: 'direct' }).text()).toBe('Shared with you')
  })

  it('renders everyone and widget kinds', () => {
    expect(mountPill({ kind: 'everyone' }).text()).toBe('Everyone')
    expect(mountPill({ kind: 'widget' }).text()).toBe('Widget')
  })

  it('adds a red dot only for new items', () => {
    expect(
      mountPill({ kind: 'group', label: 'Sales', isNew: true })
        .find('[data-testid="pill-chat-kind-new"]')
        .exists()
    ).toBe(true)
    expect(
      mountPill({ kind: 'group', label: 'Sales' })
        .find('[data-testid="pill-chat-kind-new"]')
        .exists()
    ).toBe(false)
  })
})
