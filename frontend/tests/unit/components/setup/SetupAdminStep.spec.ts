import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SetupAdminStep from '@/components/setup/SetupAdminStep.vue'

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
})
