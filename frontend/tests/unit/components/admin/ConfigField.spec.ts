import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfigField from '@/components/admin/ConfigField.vue'
import type { ConfigFieldSchema, ConfigValue } from '@/services/api/adminConfigApi'

const booleanSchema: ConfigFieldSchema = {
  tab: 'auth',
  section: 'access',
  type: 'boolean',
  sensitive: false,
  description: 'Allow visitors to create their own account.',
  default: 'true',
  source: 'database',
}

const mountField = (value: Partial<ConfigValue> = {}) =>
  mount(ConfigField, {
    props: {
      fieldKey: 'REGISTRATION_ENABLED',
      schema: booleanSchema,
      value: { value: 'true', isSet: false, isMasked: false, ...value } as ConfigValue,
    },
  })

const toggle = (wrapper: ReturnType<typeof mountField>) => wrapper.get('button[role="switch"]')

describe('ConfigField — boolean pinned by an environment variable', () => {
  it('lets an unpinned switch be toggled and reports the change', async () => {
    const wrapper = mountField()

    expect(toggle(wrapper).attributes('disabled')).toBeUndefined()
    await toggle(wrapper).trigger('click')

    expect(wrapper.emitted('update')).toEqual([['REGISTRATION_ENABLED', 'false']])
    expect(wrapper.find('[data-testid="config-field-env-override-hint"]').exists()).toBe(false)
  })

  it('locks the switch and names the variable that pins it', () => {
    const wrapper = mountField({ envOverride: true, effectiveValue: 'false' })

    expect(toggle(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="config-field-env-override-hint"]').text()).toContain(
      'REGISTRATION_ENABLED'
    )
  })

  /**
   * The stored row usually still holds the shipped default, so showing it would
   * put an "Enabled" switch directly above a hint reading "Currently: Disabled".
   */
  it('shows what the instance actually does, not the inert stored value', () => {
    const wrapper = mountField({ value: 'true', envOverride: true, effectiveValue: 'false' })

    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
    expect(wrapper.text()).not.toContain('common.enabled')
  })

  it('keeps showing the stored value while nothing pins the field', () => {
    const wrapper = mountField({ value: 'false' })

    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
  })
})
