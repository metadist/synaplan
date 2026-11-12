import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/services/api/httpClient'

const requestCodeSignup = vi.fn()
const consumeCodeSignup = vi.fn()
const resetPrincipalClientState = vi.fn()
const getChallengeToken = vi.fn()

vi.mock('@/services/api/codeSignupApi', () => ({
  requestCodeSignup: (...args: unknown[]) => requestCodeSignup(...args),
  consumeCodeSignup: (...args: unknown[]) => consumeCodeSignup(...args),
}))

vi.mock('@/services/resetPrincipalClientState', () => ({
  resetPrincipalClientState: () => resetPrincipalClientState(),
}))

vi.mock('@/composables/useSignupChallenge', () => ({
  useSignupChallenge: () => ({ getToken: getChallengeToken }),
}))

vi.mock('@/services/api/httpClient', async () => {
  const actual = await vi.importActual<typeof import('@/services/api/httpClient')>(
    '@/services/api/httpClient'
  )
  return { ...actual, reloadConfig: vi.fn() }
})

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    branding: { name: 'Synaplan' },
    auth: configAuth,
    reload: vi.fn(),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ refreshUser: vi.fn(), adoptCurrentSession: vi.fn() }),
}))

vi.mock('@/services/authService', () => ({
  authService: { adoptSession: vi.fn() },
}))

vi.mock('@/services/sessionHint', () => ({
  setSessionHint: vi.fn(),
}))

vi.mock('@/composables/useBrandLogo', () => ({
  useBrandLogo: () => ({ logoSrc: '' }),
}))

vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({
    theme: { value: 'light' },
    isDark: { value: false },
    cycleTheme: vi.fn(),
  }),
}))

import CodeSignupView from '@/views/CodeSignupView.vue'

const configAuth = { registrationEnabled: true, signupPaused: false }

async function mountView(path = '/start/code') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/start/code', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
      { path: '/', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  return mount(CodeSignupView, { global: { plugins: [router] } })
}

describe('CodeSignupView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    configAuth.registrationEnabled = true
    configAuth.signupPaused = false
    requestCodeSignup.mockReset()
    consumeCodeSignup.mockReset()
    resetPrincipalClientState.mockReset()
    getChallengeToken.mockReset()
    getChallengeToken.mockResolvedValue('human-token')
  })

  it('says when the code cannot be used', async () => {
    requestCodeSignup.mockRejectedValue(
      new ApiError(400, 'That invitation code cannot be used.', 'SIGNUP_CODE_INVALID')
    )
    const wrapper = await mountView()
    await wrapper.get('[data-testid="input-email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="input-code"]').setValue('NOPE')
    await wrapper.get('[data-testid="form-code-signup"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="text-error"]').text()).toBe(
      'That invitation code cannot be used.'
    )
    expect(wrapper.find('[data-testid="text-sent"]').exists()).toBe(false)
  })

  it('tells the person to check the mailbox they typed', async () => {
    requestCodeSignup.mockResolvedValue({ success: true })
    const wrapper = await mountView()
    await wrapper.get('[data-testid="input-email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="input-code"]').setValue('ABCD2345EFGH')
    await wrapper.get('[data-testid="form-code-signup"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="text-sent"]').text()).toContain('ada@example.com')
    expect(requestCodeSignup).toHaveBeenCalledWith('ada@example.com', 'ABCD2345EFGH', 'human-token')
  })

  it('signs in from the link and says whether the account is new', async () => {
    consumeCodeSignup.mockResolvedValue({
      success: true,
      created: true,
      user: { id: 7, email: 'ada@example.com', level: 'PRO', isAdmin: false, emailVerified: true },
    })
    const wrapper = await mountView('/start/code?token=abc')
    await flushPromises()

    expect(wrapper.get('[data-testid="text-ready"]').text()).toContain('Your account is ready.')
    expect(wrapper.get('[data-testid="link-chat"]').attributes('href')).toBe('/')
    expect(resetPrincipalClientState).toHaveBeenCalledOnce()
  })

  it('does not claim nothing was created when the link request never returns', async () => {
    consumeCodeSignup.mockRejectedValue(new Error('network'))
    const wrapper = await mountView('/start/code?token=abc')
    await flushPromises()

    expect(wrapper.get('[data-testid="text-error"]').text()).toContain('could not tell')
    expect(wrapper.get('[data-testid="link-sign-in"]').attributes('href')).toBe('/login')
  })

  it('points to sign-in instead of register when code signup is off and registration is closed', async () => {
    configAuth.registrationEnabled = false
    requestCodeSignup.mockRejectedValue(new ApiError(404, 'Not found.', 'CODE_SIGNUP_OFF'))
    const wrapper = await mountView()
    await wrapper.get('[data-testid="input-email"]').setValue('ada@example.com')
    await wrapper.get('[data-testid="input-code"]').setValue('ABCD2345EFGH')
    await wrapper.get('[data-testid="form-code-signup"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="link-register"]').attributes('href')).toBe('/login')
  })

  it('shows the public-edge refusal and a sign-in link instead of claiming the link expired', async () => {
    consumeCodeSignup.mockRejectedValue(
      new ApiError(
        403,
        'New accounts can only be created from the public website. If you already have an account, sign in.',
        'SIGNUP_EDGE_REQUIRED'
      )
    )
    const wrapper = await mountView('/start/code?token=abc')
    await flushPromises()

    expect(wrapper.get('[data-testid="text-error"]').text()).toContain('public website')
    expect(wrapper.get('[data-testid="link-sign-in"]').attributes('href')).toBe('/login')
  })
})
