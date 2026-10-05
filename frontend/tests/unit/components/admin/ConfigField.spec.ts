import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { mockRoute } = vi.hoisted(() => ({
  mockRoute: { query: {} as Record<string, string> },
}))

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
}))
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

describe('ConfigField — clearing a saved value', () => {
  const textSchema: ConfigFieldSchema = {
    tab: 'branding',
    section: 'colors',
    type: 'text',
    sensitive: false,
    description: 'Primary accent color as a hex value.',
    default: '#003fc7',
    source: 'database',
  }

  const mountText = (value: Partial<ConfigValue> = {}) =>
    mount(ConfigField, {
      props: {
        fieldKey: 'BRAND_PRIMARY_COLOR',
        schema: textSchema,
        value: { value: '#7ec8ff', isSet: true, isMasked: false, ...value } as ConfigValue,
      },
    })

  let previousLocale: SupportedLanguage

  beforeEach(() => {
    previousLocale = i18n.global.locale.value as SupportedLanguage
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    i18n.global.locale.value = previousLocale
  })

  it('offers a clear action that removes the saved value', async () => {
    const wrapper = mountText()

    await wrapper.get('[data-testid="config-field-clear"]').trigger('click')

    expect(wrapper.emitted('update')).toEqual([['BRAND_PRIMARY_COLOR', '']])
  })

  it('hides clear when nothing has been saved', () => {
    const wrapper = mountText({ value: '#003fc7', isSet: false })

    expect(wrapper.find('[data-testid="config-field-clear"]').exists()).toBe(false)
  })

  it('treats saving an emptied field as a clear', async () => {
    const wrapper = mountText()

    await wrapper.get('input').setValue('')
    await wrapper.get('button.btn-primary').trigger('click')

    expect(wrapper.emitted('update')).toEqual([['BRAND_PRIMARY_COLOR', '']])
  })

  it('does not offer clear on a boolean switch', () => {
    const wrapper = mountField({ value: 'true', isSet: true })

    expect(wrapper.find('[data-testid="config-field-clear"]').exists()).toBe(false)
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
    previousLocale = i18n.global.locale.value as SupportedLanguage
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

  it('names a feature switch in words and keeps the config key', () => {
    const wrapper = mountOverlay('FEATURE_DESKTOP_AGENT_ENABLED', {
      tab: 'features',
      section: 'platforms',
      type: 'boolean',
      sensitive: false,
      description: 'English schema fallback that must not appear.',
      default: 'true',
      source: 'database',
    })

    expect(wrapper.get('[data-testid="config-field-title"]').text()).toBe('Synaplan Desktop')
    expect(wrapper.text()).toContain('FEATURE_DESKTOP_AGENT_ENABLED')
    expect(wrapper.text()).toContain('waiting tasks are not picked up')
    expect(wrapper.text()).toContain('There is no installer yet')
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

describe('ConfigField — search deep link', () => {
  afterEach(() => {
    mockRoute.query = {}
  })

  it('rings the field a search result points at', () => {
    mockRoute.query = { highlight: 'REGISTRATION_ENABLED' }

    expect(mountField().find('[data-testid="config-field-highlighted"]').exists()).toBe(true)
  })

  it('leaves every other field unmarked', () => {
    mockRoute.query = { highlight: 'MAILER_DSN' }

    expect(mountField().find('[data-testid="config-field-highlighted"]').exists()).toBe(false)
  })
})
