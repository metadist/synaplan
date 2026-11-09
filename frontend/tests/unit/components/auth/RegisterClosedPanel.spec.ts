import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import RegisterClosedPanel from '@/components/auth/RegisterClosedPanel.vue'

async function mountPanel(paused = false, query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/register', name: 'register', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'register', query })
  await router.isReady()
  return mount(RegisterClosedPanel, { props: { paused }, global: { plugins: [router] } })
}

describe('RegisterClosedPanel', () => {
  it('offers one sentence and a sign-in action when accounts cannot be created', async () => {
    const wrapper = await mountPanel()

    expect(wrapper.get('[data-testid="register-closed"]').text()).toContain(
      'Registration is turned off. Please contact the administrator if you need an account.'
    )
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.vm.$router.currentRoute.value.name).toBe('login')
  })

  it('keeps a deep-link query on the way to sign-in', async () => {
    const wrapper = await mountPanel(false, { redirect: '/files' })
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.vm.$router.currentRoute.value.name).toBe('login')
    expect(wrapper.vm.$router.currentRoute.value.query).toEqual({ redirect: '/files' })
  })

  it('says the pause is temporary', async () => {
    const wrapper = await mountPanel(true)

    expect(wrapper.text()).toContain('Sign-up is paused for a short while.')
  })
})
