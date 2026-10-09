import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EmptyState from '@/components/common/EmptyState.vue'

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="to" data-router-link><slot /></a>',
}

const mountEmpty = (props: { title: string } & Record<string, unknown>) =>
  mount(EmptyState, { props, global: { stubs: { RouterLink: RouterLinkStub } } })

describe('EmptyState', () => {
  it('renders the sentence and hint', () => {
    const wrapper = mountEmpty({ title: 'Nothing here yet.', hint: 'Add the first one.' })
    expect(wrapper.get('[data-testid="empty-state"]').text()).toContain('Nothing here yet.')
    expect(wrapper.text()).toContain('Add the first one.')
  })

  it('renders no action without a label', () => {
    const wrapper = mountEmpty({ title: 'Nothing here yet.' })
    expect(wrapper.find('[data-testid="btn-empty-state-action"]').exists()).toBe(false)
  })

  it('renders a link when `to` is given', () => {
    const wrapper = mountEmpty({ title: 'Empty', actionLabel: 'Browse apps', to: '/apps' })
    const action = wrapper.get('[data-testid="btn-empty-state-action"]')
    expect(action.attributes('href')).toBe('/apps')
    expect(action.text()).toBe('Browse apps')
  })

  it('emits action for a button', async () => {
    const wrapper = mountEmpty({ title: 'Empty', actionLabel: 'New task' })
    await wrapper.get('[data-testid="btn-empty-state-action"]').trigger('click')
    expect(wrapper.emitted('action')).toHaveLength(1)
  })

  it('uses a custom test id', () => {
    const wrapper = mountEmpty({ title: 'Empty', testId: 'empty-tasks' })
    expect(wrapper.find('[data-testid="empty-tasks"]').exists()).toBe(true)
  })
})
