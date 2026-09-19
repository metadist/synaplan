import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/en.json'
import ComputeStatusCard from '@/components/admin/ComputeStatusCard.vue'
import type { ComputeStatus } from '@/services/featuresService'

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

const status = (overrides: Partial<ComputeStatus> = {}): ComputeStatus => ({
  enabled: true,
  reachable: true,
  protocol: 1,
  tier: 'gvisor',
  tierMeetsRequirement: true,
  capacity: { maxConcurrent: 4, running: 1, queued: 2 },
  images: [
    { key: 'python', digest: 'a1b2c3d4e5f6' },
    { key: 'node', digest: 'b1b2c3d4e5f6' },
  ],
  runsLast24h: 12,
  failedLast24h: 1,
  ...overrides,
})

const mountCard = (value: ComputeStatus) =>
  mount(ComputeStatusCard, {
    props: { compute: value },
    global: {
      plugins: [i18n],
      stubs: { Icon: true, RouterLink: { template: '<a><slot /></a>' } },
    },
  })

describe('ComputeStatusCard', () => {
  it('shows tier badge, capacity, images and 24h counts when healthy', () => {
    const wrapper = mountCard(status())
    expect(wrapper.get('[data-testid="compute-tier-badge"]').text()).toBe('Strong isolation')
    expect(wrapper.get('[data-testid="compute-capacity-text"]').text()).toContain('1 of 4 running')
    expect(wrapper.get('[data-testid="compute-capacity-text"]').text()).toContain('2 queued')
    expect(wrapper.get('[data-testid="compute-capacity-bar"] div').attributes('style')).toContain(
      'width: 25%'
    )
    expect(wrapper.findAll('[data-testid="compute-image-chip"]')).toHaveLength(2)
    expect(wrapper.get('[data-testid="compute-runs-24h"]').text()).toContain('12')
    expect(wrapper.get('[data-testid="compute-failed-24h"]').text()).toContain('1')
    expect(wrapper.find('[data-testid="compute-posture-warning"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-compute-retry"]').exists()).toBe(false)
  })

  it('names the tier per runtime id', () => {
    expect(
      mountCard(status({ tier: 'docker' }))
        .get('[data-testid="compute-tier-badge"]')
        .text()
    ).toBe('Standard')
    expect(
      mountCard(status({ tier: 'microvm' }))
        .get('[data-testid="compute-tier-badge"]')
        .text()
    ).toBe('Virtual machine')
  })

  it('shows the posture warning when the tier is below requirement', () => {
    const wrapper = mountCard(status({ tierMeetsRequirement: false }))
    expect(wrapper.get('[data-testid="compute-posture-warning"]').text()).toContain(
      'Strong isolation'
    )
  })

  it('shows one disabled sentence and no retry when the flag is off', () => {
    const wrapper = mountCard(status({ enabled: false }))
    expect(wrapper.get('[data-testid="compute-state-line"]').text()).toContain('Turned off')
    expect(wrapper.find('[data-testid="btn-compute-retry"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="link-compute-config"]').exists()).toBe(true)
  })

  it('shows the unreachable sentence and emits retry when the sidecar is down', async () => {
    const wrapper = mountCard(status({ reachable: false, tier: '', tierMeetsRequirement: false }))
    expect(wrapper.get('[data-testid="compute-state-line"]').text()).toContain('not answering')
    expect(wrapper.find('[data-testid="compute-capacity-bar"]').exists()).toBe(false)
    await wrapper.get('[data-testid="btn-compute-retry"]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })
})
