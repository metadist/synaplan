import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import OAuthCallback from '@/components/auth/OAuthCallback.vue'

describe('OAuthCallback closed sign-up', () => {
  it('shows the sentence for an unknown address when new accounts cannot be created', async () => {
    setActivePinia(createPinia())
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/auth/callback', component: OAuthCallback },
        { path: '/login', name: 'login', component: { template: '<div />' } },
      ],
    })
    window.history.pushState({}, '', '/auth/callback?error_code=signup_closed&provider=google')
    await router.push('/auth/callback')
    await router.isReady()

    const wrapper = mount(OAuthCallback, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain(
      'There is no account for this address here, and new accounts cannot be created.'
    )
  })

  it('shows the suspended sentence without asking the person to try again', async () => {
    setActivePinia(createPinia())
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/auth/callback', component: OAuthCallback },
        { path: '/login', name: 'login', component: { template: '<div />' } },
      ],
    })
    window.history.pushState({}, '', '/auth/callback?error_code=account_suspended&provider=google')
    await router.push('/auth/callback')
    await router.isReady()

    const wrapper = mount(OAuthCallback, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('This account has been suspended. Please contact support.')
    expect(wrapper.text()).not.toContain('Please try again')
  })

  it('tells a person whose sign-in belongs to another browser to start again', async () => {
    setActivePinia(createPinia())
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/auth/callback', component: OAuthCallback },
        { path: '/login', name: 'login', component: { template: '<div />' } },
      ],
    })
    window.history.pushState(
      {},
      '',
      '/auth/callback?error=Invalid+state+parameter&error_code=login_restart&provider=google'
    )
    await router.push('/auth/callback')
    await router.isReady()

    const wrapper = mount(OAuthCallback, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('It expired or was started in another browser.')
    expect(wrapper.text()).not.toContain('Invalid state parameter')
  })
})
