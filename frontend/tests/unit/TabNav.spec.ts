import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import TabNav from '@/components/TabNav.vue'

describe('TabNav', () => {
  it('marks the active tab with the shared active class', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: { template: '<div />' } }],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(TabNav, {
      props: {
        modelValue: 'list',
        tabs: [
          { id: 'choice', label: 'Model Choice', testid: 'tab-choice' },
          { id: 'list', label: 'List Of All', testid: 'tab-list' },
        ],
        ariaLabel: 'Models',
      },
      global: { plugins: [router] },
    })

    const active = wrapper.find('[data-testid="tab-list"]')
    expect(active.classes()).toContain('tab-nav-item--active')
    const inactive = wrapper.find('[data-testid="tab-choice"]')
    expect(inactive.classes()).not.toContain('tab-nav-item--active')
  })

  it('emits update:modelValue when a tab is clicked', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: { template: '<div />' } }],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(TabNav, {
      props: {
        modelValue: 'choice',
        tabs: [
          { id: 'choice', label: 'Model Choice', testid: 'tab-choice' },
          { id: 'list', label: 'List Of All', testid: 'tab-list' },
        ],
      },
      global: { plugins: [router] },
    })

    await wrapper.find('[data-testid="tab-list"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['list'])
  })
})
