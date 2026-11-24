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

const forgotPassword = vi.fn()
const getToken = vi.fn(async () => 'challenge-token')

vi.mock('@/services/api', () => ({
  authApi: { forgotPassword: (...args: unknown[]) => forgotPassword(...args) },
}))

vi.mock('@/composables/useSignupChallenge', () => ({
  useSignupChallenge: () => ({ getToken }),
}))

import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import { ApiError } from '@/services/api/httpClient'

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
      stubs: {
        Button: { template: '<button v-bind="$attrs"><slot /></button>', inheritAttrs: false },
      },
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

describe('ForgotPasswordView request', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mailerConfigured.mockReturnValue(true)
    forgotPassword.mockReset()
    getToken.mockClear()
  })

  async function submit(wrapper: ReturnType<typeof mountView>) {
    await wrapper.get('[data-testid="input-email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="comp-forgot-form"]').trigger('submit')
    await flushPromises()
  }

  it('sends the human-check token for the password_reset action', async () => {
    forgotPassword.mockResolvedValue({ success: true })
    const wrapper = mountView()
    await submit(wrapper)

    expect(getToken).toHaveBeenCalledWith('password_reset')
    expect(forgotPassword).toHaveBeenCalledWith('ada@example.com', 'challenge-token')
    expect(wrapper.find('[data-testid="section-email-sent"]').exists()).toBe(true)
  })

  it('says to wait when the server limits the requests', async () => {
    forgotPassword.mockRejectedValue(new ApiError(429, 'Too many', 'PASSWORD_RESET_RATE_LIMITED'))
    const wrapper = mountView()
    await submit(wrapper)

    expect(wrapper.find('[data-testid="section-email-sent"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-forgot-error"]').text()).toContain('wait a few minutes')
  })

  it('asks to try again when the human check fails, and clears it on typing', async () => {
    forgotPassword.mockRejectedValue(new ApiError(400, 'No', 'SIGNUP_CHALLENGE_FAILED'))
    const wrapper = mountView()
    await submit(wrapper)

    expect(wrapper.get('[data-testid="text-forgot-error"]').text()).toContain(
      'could not confirm that you are human'
    )

    await wrapper.get('[data-testid="input-email"]').setValue('ada@example.org')
    expect(wrapper.find('[data-testid="text-forgot-error"]').exists()).toBe(false)
  })
})
