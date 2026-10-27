import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UnverifiedLoginHint from '@/components/verification/UnverifiedLoginHint.vue'

const mailerConfigured = vi.fn(() => true)

vi.mock('@/utils/mailerConfigured', () => ({
  isMailerConfigured: () => mailerConfigured(),
}))

async function mountHint(email: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', component: { template: '<div />' } },
      { path: '/verify-email', component: { template: '<div />' } },
    ],
  })
  await router.push('/login')
  await router.isReady()

  return mount(UnverifiedLoginHint, { props: { email }, global: { plugins: [router] } })
}

describe('UnverifiedLoginHint', () => {
  beforeEach(() => {
    mailerConfigured.mockReturnValue(true)
  })

  it('links to the resend page with the typed address', async () => {
    const hint = await mountHint(' ada@example.com ')

    expect(hint.text()).toContain('Confirm your email address before you sign in')
    expect(hint.get('[data-testid="link-login-resend-verification"]').attributes('href')).toBe(
      '/verify-email?email=ada@example.com'
    )
  })

  it('names the administrator and offers no resend when mail is not set up', async () => {
    mailerConfigured.mockReturnValue(false)
    const hint = await mountHint('ada@example.com')

    expect(hint.text()).toContain('an administrator has to confirm your account')
    expect(hint.find('[data-testid="link-login-resend-verification"]').exists()).toBe(false)
  })
})
