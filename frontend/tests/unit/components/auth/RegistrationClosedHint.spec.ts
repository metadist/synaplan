import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RegistrationClosedHint from '@/components/auth/RegistrationClosedHint.vue'

describe('RegistrationClosedHint', () => {
  it('tells visitors to ask the administrator, not to invent an account', () => {
    const wrapper = mount(RegistrationClosedHint)

    expect(wrapper.get('[data-testid="login-registration-closed"]').text()).toBe(
      'Registration is turned off. Please contact the administrator if you need an account.'
    )
  })

  it('shows the temporary pause message when paused is true', () => {
    const wrapper = mount(RegistrationClosedHint, { props: { paused: true } })

    expect(wrapper.get('[data-testid="login-registration-closed"]').text()).toBe(
      'Sign-up is paused for a short while. Please try again later.'
    )
  })
})
