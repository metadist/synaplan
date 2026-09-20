import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfigField from '@/components/admin/ConfigField.vue'
import { i18n, type SupportedLanguage } from '@/i18n'
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

describe('ConfigField — locale overlay for backend schema copy', () => {
  const mountOverlay = (
    fieldKey: string,
    schema: ConfigFieldSchema,
    value: Partial<ConfigValue> = {}
  ) =>
    mount(ConfigField, {
      props: {
        fieldKey,
        schema,
        value: { value: 'true', isSet: true, isMasked: false, ...value } as ConfigValue,
      },
    })

  let previousLocale: SupportedLanguage

  beforeEach(() => {
    previousLocale = i18n.global.locale.value
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    i18n.global.locale.value = previousLocale
  })

  it('shows the translated description when a locale key exists', () => {
    const wrapper = mountOverlay('COMPUTE_ENABLED', {
      tab: 'processing',
      section: 'compute',
      type: 'boolean',
      sensitive: false,
      description: 'English schema fallback that must not appear.',
      default: 'false',
      source: 'database',
    })

    expect(wrapper.text()).toContain(
      'Let the assistant do short file work (Python or Node) on copies of files you chose.'
    )
    expect(wrapper.text()).not.toContain('English schema fallback that must not appear.')
  })

  it('falls back to the schema description when no locale key exists', () => {
    const wrapper = mountOverlay('REGISTRATION_ENABLED', booleanSchema, {
      value: 'true',
      isSet: false,
    })

    expect(wrapper.text()).toContain('Allow visitors to create their own account.')
  })

  it('translates select option labels when locale keys exist', () => {
    const wrapper = mountOverlay(
      'COMPUTE_REQUIRE_TIER',
      {
        tab: 'processing',
        section: 'compute',
        type: 'select',
        sensitive: false,
        description: 'Minimum isolation',
        default: 'docker',
        source: 'database',
        options: ['docker', 'gvisor', 'microvm'],
      },
      { value: 'docker' }
    )

    const labels = wrapper.findAll('option').map((opt) => opt.text())
    expect(labels).toEqual([
      'Standard (plain Docker)',
      'Strong isolation (gVisor)',
      'Virtual machine (microVM)',
    ])
  })
})
