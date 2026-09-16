import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SetupAccessStep from '@/components/setup/SetupAccessStep.vue'

const completeSetup = vi.fn()

vi.mock('@/services/api/setupApi', () => ({
  completeSetup: (...args: unknown[]) => completeSetup(...args),
}))

const mountStep = (props: Partial<InstanceType<typeof SetupAccessStep>['$props']> = {}) =>
  mount(SetupAccessStep, {
    props: {
      registrationLocked: false,
      guestChatLocked: false,
      mailerConfigured: true,
      initialRegistrationEnabled: true,
      initialGuestChatEnabled: true,
      ...props,
    },
  })

describe('SetupAccessStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    completeSetup.mockResolvedValue({ success: true })
  })

  it('stores the chosen policy and finishes the wizard', async () => {
    const wrapper = mountStep()

    await wrapper.get('[data-testid="setup-access-guest"]').setValue(false)
    await wrapper.get('[data-testid="setup-access-submit"]').trigger('click')
    await flushPromises()

    expect(completeSetup).toHaveBeenCalledWith(true, false)
    expect(wrapper.emitted('completed')).toHaveLength(1)
  })

  it('warns that open registration without a mailer strands new accounts', () => {
    const wrapper = mountStep({ mailerConfigured: false })

    expect(wrapper.find('[data-testid="setup-access-mailer-warning"]').exists()).toBe(true)
  })

  it('drops the mailer warning once registration is off', async () => {
    const wrapper = mountStep({ mailerConfigured: false })

    await wrapper.get('[data-testid="setup-access-registration"]').setValue(false)

    expect(wrapper.find('[data-testid="setup-access-mailer-warning"]').exists()).toBe(false)
  })

  it('pins a switch the environment already decided and says so', () => {
    const wrapper = mountStep({ registrationLocked: true, initialRegistrationEnabled: false })

    const checkbox = wrapper.get('[data-testid="setup-access-registration"]')
    expect(checkbox.attributes('disabled')).toBeDefined()
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.get('[data-testid="setup-access-registration-locked"]').text()).toContain(
      'REGISTRATION_ENABLED'
    )
    expect(wrapper.find('[data-testid="setup-access-mailer-warning"]').exists()).toBe(false)
  })

  it('keeps the wizard on this step when storing the policy fails', async () => {
    completeSetup.mockRejectedValue(new Error('database is read-only'))
    const wrapper = mountStep()

    await wrapper.get('[data-testid="setup-access-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="setup-access-error"]').text()).toContain(
      'database is read-only'
    )
    expect(wrapper.emitted('completed')).toBeUndefined()
  })
})
