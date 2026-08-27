import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SetupAdminStep from '@/components/setup/SetupAdminStep.vue'
import { ApiError } from '@/services/api/httpClient'

const createFirstAdmin = vi.fn()

vi.mock('@/services/api/setupApi', () => ({
  createFirstAdmin: (...args: unknown[]) => createFirstAdmin(...args),
}))

async function fillAndSubmit(
  wrapper: ReturnType<typeof mount>,
  email: string,
  password: string,
  confirmation: string
) {
  await wrapper.get('[data-testid="setup-admin-email"]').setValue(email)
  await wrapper.get('[data-testid="setup-admin-password"]').setValue(password)
  await wrapper.get('[data-testid="setup-admin-confirm"]').setValue(confirmation)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('SetupAdminStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    createFirstAdmin.mockResolvedValue({ success: true })
  })

  it('creates the administrator and hands over to the next step', async () => {
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, ' admin@example.com ', 'Sup3rSecret', 'Sup3rSecret')

    expect(createFirstAdmin).toHaveBeenCalledWith('admin@example.com', 'Sup3rSecret')
    expect(wrapper.emitted('created')).toHaveLength(1)
  })

  it('catches a mistyped confirmation before it costs a round trip', async () => {
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, 'admin@example.com', 'Sup3rSecret', 'Sup3rSecrat')

    expect(createFirstAdmin).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="setup-admin-error"]').exists()).toBe(true)
    expect(wrapper.emitted('created')).toBeUndefined()
  })

  it('rejects a password the server would reject anyway', async () => {
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, 'admin@example.com', 'alllowercase', 'alllowercase')

    expect(createFirstAdmin).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="setup-admin-error"]').exists()).toBe(true)
  })

  it('surfaces the server error and keeps the form usable', async () => {
    createFirstAdmin.mockRejectedValue(new Error('An administrator already exists'))
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, 'admin@example.com', 'Sup3rSecret', 'Sup3rSecret')

    expect(wrapper.get('[data-testid="setup-admin-error"]').text()).toContain(
      'An administrator already exists'
    )
    expect(wrapper.get('[data-testid="setup-admin-submit"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.emitted('created')).toBeUndefined()
  })

  /**
   * A second tab, or a second container in a rollout, submitting while this one
   * is being served. Retriable, and the backend's own wording is English-only,
   * so the visitor has to be told in their language that waiting is the answer.
   */
  it('explains a concurrent setup attempt instead of repeating the server prose', async () => {
    createFirstAdmin.mockRejectedValue(
      new ApiError(409, 'Setup is already in progress', 'SETUP_IN_PROGRESS')
    )
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, 'admin@example.com', 'Sup3rSecret', 'Sup3rSecret')

    const text = wrapper.get('[data-testid="setup-admin-error"]').text()
    expect(text).toContain('Wait a moment')
    expect(text).not.toContain('Setup is already in progress')
    expect(wrapper.emitted('stale')).toBeUndefined()
  })

  /**
   * The wizard is looking at a state the server has moved past. Asking the
   * parent to reload swaps this form for the "already set up, sign in" card —
   * the backend's own answer points at a console command, which is no use to
   * somebody sitting in a browser.
   */
  it('asks the parent to reload when the installation is already set up', async () => {
    createFirstAdmin.mockRejectedValue(
      new ApiError(
        409,
        'This instance already has accounts. Sign in, or reset an administrator password with `php bin/console app:admin:reset-password`.',
        'SETUP_ALREADY_COMPLETED'
      )
    )
    const wrapper = mount(SetupAdminStep)

    await fillAndSubmit(wrapper, 'admin@example.com', 'Sup3rSecret', 'Sup3rSecret')

    const text = wrapper.get('[data-testid="setup-admin-error"]').text()
    expect(text).toContain('Sign in instead')
    expect(text).not.toContain('bin/console')
    expect(wrapper.emitted('stale')).toHaveLength(1)
  })
})
