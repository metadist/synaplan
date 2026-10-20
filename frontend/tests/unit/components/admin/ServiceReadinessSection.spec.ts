import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import ServiceReadinessSection from '@/components/admin/ServiceReadinessSection.vue'
import type { ServiceReadiness } from '@/services/featuresService'

const i18nFor = (locale: 'en' | 'de') =>
  createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: {
      en: asI18nSchema(loadAllMessages('en')),
      de: asI18nSchema(loadAllMessages('de')),
    },
  })

const service = (
  overrides: Partial<ServiceReadiness> & Pick<ServiceReadiness, 'id' | 'state'>
): ServiceReadiness => ({
  name: `${overrides.id} (backend)`,
  unlocks: 'backend unlocks',
  effect: overrides.state === 'ready' ? null : 'backend effect',
  recovery: null,
  since: null,
  percent: null,
  model: null,
  minutes_left: null,
  profile: null,
  docs_anchor: null,
  ...overrides,
})

const mountSection = (services: ServiceReadiness[], locale: 'en' | 'de' = 'en') =>
  mount(ServiceReadinessSection, {
    props: { services },
    global: { plugins: [i18nFor(locale)] },
  })

describe('ServiceReadinessSection', () => {
  it('lists problems first, then progress, then ready and off rows', () => {
    const wrapper = mountSection([
      service({ id: 'collabora', state: 'off' }),
      service({ id: 'tika', state: 'ready' }),
      service({ id: 'ollama', state: 'downloading' }),
      service({ id: 'qdrant', state: 'unavailable' }),
    ])

    const rows = wrapper.findAll('[data-testid="item-service"]')
    expect(rows.map((r) => r.attributes('data-service'))).toEqual([
      'qdrant',
      'ollama',
      'tika',
      'collabora',
    ])
    expect(wrapper.get('[data-testid="text-services-summary"]').text()).toBe(
      '1 of 3 running services ready'
    )
  })

  it('shows the translated effect and the commands verbatim for a service that stopped', () => {
    const wrapper = mountSection(
      [
        service({
          id: 'qdrant',
          state: 'unavailable',
          recovery:
            'Run `docker compose logs qdrant` to see why; `docker compose restart qdrant` brings it back.',
        }),
      ],
      'de'
    )

    const row = wrapper.get('[data-testid="item-service"]')
    expect(row.text()).toContain('Qdrant (Erinnerungen)')
    expect(row.get('[data-testid="text-service-effect"]').text()).toBe(
      'Erinnerungen sind pausiert.'
    )
    expect(row.get('[data-testid="badge-service-state"]').text()).toBe('Nicht erreichbar')
    expect(row.findAll('[data-testid="code-service-command"]').map((c) => c.text())).toEqual([
      'docker compose logs qdrant',
      'docker compose restart qdrant',
    ])
    expect(row.text()).not.toContain('to see why')
  })

  it('shows percent, model and time left while a model downloads', () => {
    const wrapper = mountSection([
      service({
        id: 'ollama',
        state: 'downloading',
        percent: 42,
        model: 'gpt-oss:20b',
        minutes_left: 6,
      }),
    ])

    expect(wrapper.get('[data-testid="badge-service-state"]').text()).toBe('Downloading · 42 %')
    expect(wrapper.get('[data-testid="text-service-progress"]').text()).toBe(
      'gpt-oss:20b · about 6 min left'
    )
    expect(wrapper.find('[data-testid="text-service-recovery"]').exists()).toBe(false)
  })

  it('names the Compose profile and the guide for a switched-off sidecar, without an effect', () => {
    const wrapper = mountSection([
      service({
        id: 'collabora',
        state: 'off',
        profile: 'office',
        docs_anchor: 'modules/office-convert',
        recovery: 'Turn it on with the "office" Compose profile: `COMPOSE_PROFILES=office make up`',
      }),
    ])

    const row = wrapper.get('[data-testid="item-service"]')
    expect(row.find('[data-testid="text-service-effect"]').exists()).toBe(false)
    expect(row.get('[data-testid="text-service-recovery"]').text()).toContain(
      'Turn it on with the "office" Compose profile:'
    )
    expect(row.get('[data-testid="code-service-command"]').text()).toBe(
      'COMPOSE_PROFILES=office make up'
    )
    expect(row.get('[data-testid="link-service-docs"]').attributes('href')).toBe(
      'https://docs.synaplan.com/modules/office-convert'
    )
  })

  it('gives a Kubernetes install the effect sentence and no command', () => {
    const wrapper = mountSection([service({ id: 'tika', state: 'failed', recovery: null })])

    const row = wrapper.get('[data-testid="item-service"]')
    expect(row.get('[data-testid="text-service-effect"]').text()).toBe(
      'PDFs and Office files cannot be read.'
    )
    expect(row.find('[data-testid="text-service-recovery"]').exists()).toBe(false)
    expect(row.find('[data-testid="code-service-command"]').exists()).toBe(false)
  })

  it('falls back to the backend copy for a service the frontend does not know yet', () => {
    const wrapper = mountSection([
      service({ id: 'future' as ServiceReadiness['id'], state: 'starting' }),
    ])

    const row = wrapper.get('[data-testid="item-service"]')
    expect(row.text()).toContain('future (backend)')
    expect(row.text()).toContain('backend unlocks')
    expect(row.text()).toContain('backend effect')
  })
})
