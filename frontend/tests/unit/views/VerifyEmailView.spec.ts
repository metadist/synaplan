import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import VerifyEmailView from '@/views/VerifyEmailView.vue'
import { PostApiAuthResendVerificationResponseSchema } from '@/generated/api-schemas'
import { ApiError } from '@/services/api/httpClient'

const resendVerification = vi.fn()

vi.mock('@/services/api', () => ({
  authApi: {
    resendVerification: (...args: unknown[]) => resendVerification(...args),
  },
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    branding: { name: 'Synaplan', logoUrl: '', logoDarkUrl: '', iconUrl: '' },
  }),
}))

const notSent = {
  success: false,
  mailDelivered: false,
  message: 'No verification email was sent.',
  remainingAttempts: null,
  cooldownMinutes: null,
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/verify-email', component: VerifyEmailView },
      { path: '/login', component: { template: '<div />' } },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
  await router.push({ path: '/verify-email', query: { email: 'ada@example.com' } })
  await router.isReady()

  return mount(VerifyEmailView, { global: { plugins: [router] } })
}

describe('VerifyEmailView resend', () => {
  it('keeps mailDelivered on the generated response schema', () => {
    expect(PostApiAuthResendVerificationResponseSchema.parse(notSent).mailDelivered).toBe(false)
  })

  beforeEach(() => {
    resendVerification.mockReset()
  })

  it('says that no email was sent when mail is not set up, and hides resend', async () => {
    resendVerification.mockResolvedValue(notSent)
    const page = await mountPage()

    await page.get('[data-testid="btn-resend-email"]').trigger('click')
    await flushPromises()

    expect(page.get('[data-testid="alert-resend-unconfigured"]').text()).toContain(
      'No email was sent'
    )
    expect(page.find('[data-testid="alert-resend-success"]').exists()).toBe(false)
    expect(page.find('[data-testid="btn-resend-email"]').exists()).toBe(false)
    expect(page.find('[data-testid="link-footer-login"]').exists()).toBe(true)
  })

  it('shows a success notice when the email was sent', async () => {
    resendVerification.mockResolvedValue({
      success: true,
      mailDelivered: true,
      message: 'Verification email sent successfully',
      remainingAttempts: 4,
      cooldownMinutes: 2,
    })
    const page = await mountPage()

    await page.get('[data-testid="btn-resend-email"]').trigger('click')
    await flushPromises()

    expect(page.get('[data-testid="alert-resend-success"]').text()).toContain(
      'We sent another verification email'
    )
    expect(page.find('[data-testid="alert-resend-unconfigured"]').exists()).toBe(false)
    expect(page.get('[data-testid="text-remaining-attempts"]').text()).toContain('4 attempts left')
  })

  it('explains a used-up allowance without the server sentence', async () => {
    resendVerification.mockRejectedValue(
      new ApiError(429, 'Maximum verification attempts reached', undefined, {
        maxAttemptsReached: true,
      })
    )
    const page = await mountPage()

    await page.get('[data-testid="btn-resend-email"]').trigger('click')
    await flushPromises()

    expect(page.get('[data-testid="alert-resend-error"]').text()).toContain(
      'You have used all resend attempts'
    )
    expect(page.get('[data-testid="btn-resend-email"]').text()).toContain('No attempts left')
  })
})
