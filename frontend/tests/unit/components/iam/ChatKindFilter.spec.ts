import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ChatKindFilter from '@/components/iam/ChatKindFilter.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      iam: {
        incoming: {
          newCount: '{count} new incoming',
          filter: {
            label: 'Filter chats',
            all: 'All',
            private: 'Private',
            group: 'Group',
            widget: 'Widget',
          },
        },
      },
    },
  },
})

type FilterProps = InstanceType<typeof ChatKindFilter>['$props']

const mountFilter = (props: Partial<FilterProps>) =>
  mount(ChatKindFilter, {
    props: { modelValue: 'all' as const, ...props },
    global: { plugins: [i18n], stubs: { Icon: true } },
  })

describe('ChatKindFilter', () => {
  it('offers All / Private / Group by default and Widget on request', () => {
    const compact = mountFilter({})
    expect(compact.findAll('button').map((b) => b.attributes('data-testid'))).toEqual([
      'btn-chat-filter-all',
      'btn-chat-filter-private',
      'btn-chat-filter-group',
    ])

    const full = mountFilter({ showWidget: true })
    expect(full.find('[data-testid="btn-chat-filter-widget"]').exists()).toBe(true)
  })

  it('marks the selected filter as pressed and emits on click', async () => {
    const wrapper = mountFilter({ modelValue: 'private' })
    expect(wrapper.find('[data-testid="btn-chat-filter-private"]').attributes('aria-pressed')).toBe(
      'true'
    )
    expect(wrapper.find('[data-testid="btn-chat-filter-private"]').classes()).toContain(
      'pill--active'
    )

    await wrapper.find('[data-testid="btn-chat-filter-group"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['group']])
  })

  it('shows counts next to the labels', () => {
    const wrapper = mountFilter({ counts: { private: 4, group: 1 } })
    expect(wrapper.find('[data-testid="text-chat-filter-count-private"]').text()).toBe('4')
    expect(wrapper.find('[data-testid="text-chat-filter-count-group"]').text()).toBe('1')
    expect(wrapper.find('[data-testid="text-chat-filter-count-all"]').exists()).toBe(false)
  })

  it('puts a red dot on Group only while incoming chats are unseen', () => {
    expect(
      mountFilter({ newCount: 2 }).find('[data-testid="dot-chat-filter-group-new"]').exists()
    ).toBe(true)
    expect(
      mountFilter({ newCount: 0 }).find('[data-testid="dot-chat-filter-group-new"]').exists()
    ).toBe(false)
  })
})
