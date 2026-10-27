import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import McpServerAuthFields from '@/components/config/McpServerAuthFields.vue'

const mountFields = (props: Partial<Record<string, unknown>> = {}) =>
  mount(McpServerAuthFields, {
    props: {
      mode: 'bearer',
      authHeader: '',
      authToken: '',
      oauthEnabled: true,
      locked: false,
      hasSavedToken: false,
      ...props,
    },
  })

describe('McpServerAuthFields', () => {
  it('offers three methods while sign-in connectors are on', () => {
    const wrapper = mountFields()

    expect(wrapper.find('[data-testid="radio-mcp-auth-none"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="radio-mcp-auth-bearer"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="radio-mcp-auth-oauth"]').exists()).toBe(true)
  })

  it('leaves sign-in out entirely while the connectors are off', () => {
    const wrapper = mountFields({ oauthEnabled: false })

    expect(wrapper.find('[data-testid="radio-mcp-auth-oauth"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Sign in with your account')
  })

  it('shows the key fields only for an access key', async () => {
    const wrapper = mountFields()
    expect(wrapper.find('[data-testid="input-mcp-auth-token"]').exists()).toBe(true)

    await wrapper.setProps({ mode: 'oauth' })
    expect(wrapper.find('[data-testid="input-mcp-auth-token"]').exists()).toBe(false)

    await wrapper.setProps({ mode: 'none' })
    expect(wrapper.find('[data-testid="input-mcp-auth-header"]').exists()).toBe(false)
  })

  it('reports the chosen method', async () => {
    const wrapper = mountFields()

    await wrapper.get('[data-testid="radio-mcp-auth-oauth"]').setValue(true)

    expect(wrapper.emitted('update:mode')?.[0]).toEqual(['oauth'])
  })

  it('explains a sign-in server whose method an administrator turned off', () => {
    const wrapper = mountFields({ mode: 'oauth', oauthEnabled: false, locked: true })

    expect(wrapper.find('[data-testid="mcp-auth-locked"]').text()).toContain(
      'An administrator has turned sign-in off'
    )
    expect(wrapper.find('input[type="radio"]').exists()).toBe(false)
  })
})
