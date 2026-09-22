import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import FeatureModulesSection from '@/components/admin/FeatureModulesSection.vue'
import type { FeatureModule } from '@/services/featuresService'

const en = loadAllMessages('en')

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })

const module = (
  overrides: Partial<FeatureModule> & Pick<FeatureModule, 'id' | 'state'>
): FeatureModule => ({
  label_key: `modules.${overrides.id}.label`,
  configured: overrides.state !== 'absent',
  healthy: overrides.state === 'available',
  message: `${overrides.id} message`,
  details: {},
  configured_by: { env: [], bconfig: [], providers: [], plugs: [] },
  capabilities: [],
  docs_anchor: `modules/${overrides.id}`,
  mobile_class: 'backend-only',
  ...overrides,
})

const mountSection = (modules: FeatureModule[]) =>
  mount(FeatureModulesSection, {
    props: { modules },
    global: { plugins: [i18n], stubs: { Icon: true } },
  })

describe('FeatureModulesSection', () => {
  it('renders one row per module, needs-setup first, then available, then absent', () => {
    const wrapper = mountSection([
      module({ id: 'whatsapp', state: 'absent' }),
      module({ id: 'tika', state: 'available' }),
      module({ id: 'stripe_billing', state: 'needs_setup' }),
      module({ id: 'docling', state: 'available' }),
    ])

    const rows = wrapper.findAll('[data-testid="item-module"]')
    expect(rows.map((r) => r.attributes('data-module'))).toEqual([
      'stripe_billing',
      'docling',
      'tika',
      'whatsapp',
    ])
    expect(rows.map((r) => r.attributes('data-state'))).toEqual([
      'needs_setup',
      'available',
      'available',
      'absent',
    ])
  })

  it('counts configured modules in the summary', () => {
    const wrapper = mountSection([
      module({ id: 'whatsapp', state: 'absent' }),
      module({ id: 'tika', state: 'available' }),
      module({ id: 'stripe_billing', state: 'needs_setup' }),
    ])

    expect(wrapper.get('[data-testid="text-modules-summary"]').text()).toBe('2 of 3 configured')
  })

  it('shows translated label, state badge, message, configured-by keys and docs link', () => {
    const wrapper = mountSection([
      module({
        id: 'stripe_billing',
        state: 'needs_setup',
        message: 'Stripe price id is missing',
        configured_by: {
          env: ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET'],
          bconfig: [],
          providers: [],
          plugs: [],
        },
        docs_anchor: 'modules/stripe-billing',
      }),
    ])

    const row = wrapper.get('[data-testid="item-module"]')
    expect(row.text()).toContain('Stripe billing')
    const badge = row.get('[data-testid="badge-module-state"]')
    expect(badge.text()).toBe('Needs setup')
    expect(badge.classes().join(' ')).toContain('status-warning-muted')
    expect(badge.classes().join(' ')).not.toContain('text-white')
    expect(row.text()).toContain('Stripe price id is missing')
    expect(row.findAll('[data-testid="chip-configured-by"]').map((c) => c.text())).toEqual([
      'STRIPE_SECRET_KEY',
      'STRIPE_WEBHOOK_SECRET',
    ])
    expect(row.get('[data-testid="link-module-docs"]').attributes('href')).toBe(
      'https://docs.synaplan.com/modules/stripe-billing'
    )
  })

  it('falls back to the id when a label key is unknown', () => {
    const wrapper = mountSection([
      module({ id: 'future', state: 'absent', label_key: 'modules.future.label' }),
    ])

    const header = wrapper.get('[data-testid="btn-module-future"]')
    expect(header.text()).toContain('future')
    expect(header.text()).not.toContain('modules.future.label')
    expect(wrapper.get('[data-testid="item-module"]').attributes('data-open')).toBe('false')
    expect(wrapper.get('[data-testid="badge-module-state"]').text()).toBe('Not installed')
  })

  it('translates the compute module line from sidecar state, not the backend sentence', () => {
    const running = mountSection([
      module({
        id: 'compute',
        state: 'available',
        message: 'Compute sidecar is running.',
        details: { enabled: true },
      }),
    ])
    expect(running.get('[data-testid="item-module"]').text()).toContain('File work is running.')
    expect(running.get('[data-testid="item-module"]').text()).not.toContain(
      'Compute sidecar is running.'
    )

    const off = mountSection([
      module({
        id: 'compute',
        state: 'available',
        message: 'Compute sidecar is running.',
        details: { enabled: false },
      }),
    ])
    expect(off.get('[data-testid="item-module"]').text()).toContain(
      'The sidecar is running; file work is turned off in System configuration.'
    )

    const down = mountSection([
      module({
        id: 'compute',
        state: 'needs_setup',
        healthy: false,
        message: 'Compute sidecar is not reachable.',
      }),
    ])
    expect(down.get('[data-testid="item-module"]').text()).toContain(
      'The file-work sidecar is not reachable.'
    )

    const unset = mountSection([
      module({
        id: 'compute',
        state: 'absent',
        message: 'COMPUTE_URL or COMPUTE_TOKEN is not set.',
      }),
    ])
    expect(unset.get('[data-testid="item-module"]').text()).toContain(
      'COMPUTE_URL or COMPUTE_TOKEN is not set.'
    )
  })
})
