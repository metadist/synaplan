import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

const mailerConfigured = vi.fn(() => true)

vi.mock('@/utils/mailerConfigured', () => ({
  isMailerConfigured: () => mailerConfigured(),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ branding: { name: 'Synaplan' } }),
}))

vi.mock('@/composables/useBrandLogo', () => ({
  useBrandLogo: () => ({ logoSrc: '' }),
}))

vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({
    theme: { value: 'light' },
    isDark: { value: false },
    setTheme: vi.fn(),
  }),
}))

vi.mock('@/services/api', () => ({
  authApi: { forgotPassword: vi.fn() },
}))

import ForgotPasswordView from '@/views/ForgotPasswordView.vue'

function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/forgot-password', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
  return mount(ForgotPasswordView, {
    global: {
      plugins: [router],
      stubs: { Button: { template: '<button><slot /></button>' } },
    },
  })
}

describe('ForgotPasswordView mailer hint', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mailerConfigured.mockReturnValue(true)
  })

  it('keeps the CLI reset off when mail can be delivered', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="text-no-mailer-hint"]').exists()).toBe(false)
  })

  it('shows the CLI reset when this server cannot send mail', async () => {
    mailerConfigured.mockReturnValue(false)
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="text-no-mailer-hint"]').text()).toContain(
      'an administrator with server access can set a new one'
    )
    expect(wrapper.get('[data-testid="text-no-mailer-command"]').text()).toBe(
      'php bin/console app:admin:reset-password you@example.com --generate'
    )
  })
})
