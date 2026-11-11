import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import AccountDoorsCard from '@/components/admin/AccountDoorsCard.vue'
import type { DoorValue } from '@/utils/accountDoors'

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/admin/config', component: { template: '<div />' } },
    { path: '/admin/people', component: { template: '<div />' } },
  ],
})

const mountCard = (props: {
  values?: Record<string, DoorValue>
  loading?: boolean
  failed?: boolean
}) =>
  mount(AccountDoorsCard, {
    props: { values: {}, loading: false, failed: false, ...props },
    global: { plugins: [router], stubs: { Icon: true } },
  })

describe('AccountDoorsCard', () => {
  it('shows the closed sentence and the environment pin', async () => {
    const wrapper = mountCard({
      values: {
        REGISTRATION_ENABLED: {
          value: 'true',
          isSet: true,
          envOverride: true,
          effectiveValue: 'false',
        },
      },
    })
    const row = wrapper.get('[data-testid="account-door-selfSignup"]')
    expect(row.attributes('data-state')).toBe('closed')
    expect(row.text()).toContain(
      'New people can no longer sign up with email, Google, GitHub or Apple'
    )
    expect(row.text()).toContain('People who already have an account still sign in')
    expect(row.text()).toContain('Pinned by REGISTRATION_ENABLED')
  })

  it('shows the loading and error states without a door list', () => {
    expect(
      mountCard({ loading: true }).find('[data-testid="account-doors-loading"]').exists()
    ).toBe(true)
    expect(mountCard({ failed: true }).find('[data-testid="account-doors-error"]').exists()).toBe(
      true
    )
    expect(
      mountCard({ failed: true }).find('[data-testid="account-door-selfSignup"]').exists()
    ).toBe(false)
  })

  it('points the administrator row at Users', () => {
    const wrapper = mountCard({})
    expect(wrapper.get('[data-testid="account-door-operator-people"]').attributes('href')).toBe(
      '/admin/people'
    )
    expect(wrapper.get('[data-testid="account-door-channel"]').text()).toContain(
      'do not create an account'
    )
  })

  it('links a row to the existing switch on this page', async () => {
    await router.push({ path: '/admin/config', query: { tab: 'auth' } })
    await router.isReady()
    const replace = vi.spyOn(router, 'replace')
    const wrapper = mountCard({
      values: { REGISTRATION_ENABLED: { value: 'true', isSet: true } },
    })
    await wrapper.get('[data-testid="account-door-selfSignup-switch"]').trigger('click')
    await flushPromises()
    expect(replace).toHaveBeenCalled()
    expect(router.currentRoute.value.query).toMatchObject({
      tab: 'auth',
      section: 'access',
      highlight: 'REGISTRATION_ENABLED',
    })
    replace.mockRestore()
  })

  it('opens a collapsed section when the address already points at that switch', async () => {
    await router.push({
      path: '/admin/config',
      query: { tab: 'auth', section: 'access', highlight: 'REGISTRATION_ENABLED' },
    })
    await router.isReady()
    const panel = document.createElement('section')
    panel.id = 'config-section-access'
    panel.dataset.open = 'false'
    const button = document.createElement('button')
    button.dataset.testid = 'btn-config-section-access'
    button.addEventListener('click', () => {
      panel.dataset.open = 'true'
    })
    const field = document.createElement('div')
    field.dataset.configField = 'REGISTRATION_ENABLED'
    field.scrollIntoView = vi.fn()
    panel.append(button, field)
    document.body.append(panel)

    const wrapper = mountCard({
      values: { REGISTRATION_ENABLED: { value: 'true', isSet: true } },
    })
    await wrapper.get('[data-testid="account-door-selfSignup-switch"]').trigger('click')
    await flushPromises()

    expect(panel.dataset.open).toBe('true')
    expect(field.scrollIntoView).toHaveBeenCalled()
    expect(router.currentRoute.value.query.highlight).toBe('REGISTRATION_ENABLED')
    panel.remove()
  })
})
