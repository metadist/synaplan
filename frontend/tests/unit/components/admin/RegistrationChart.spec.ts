import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// vue-chartjs renders to a <canvas> that happy-dom does not implement, so the
// real Line/Bar throw while resizing. Replace them with identifiable stub
// components so the chart-type toggle can be asserted without a Chart.js render.
vi.mock('vue-chartjs', () => ({
  Line: { name: 'LineChart', template: '<div data-testid="chart-line" />' },
  Bar: { name: 'BarChart', template: '<div data-testid="chart-bar" />' },
}))

import RegistrationChart from '@/components/admin/RegistrationChart.vue'
import type { RegistrationAnalytics } from '@/services/api/adminApi'

const analytics: RegistrationAnalytics = {
  timeline: [
    { date: '2026-01-01', count: 2, byProvider: { local: 2 }, byType: { password: 2 } },
    { date: '2026-01-02', count: 1, byProvider: { google: 1 }, byType: { oidc: 1 } },
  ],
  byProvider: { local: 2, google: 1 },
  byType: { password: 2, oidc: 1 },
  period: '30d',
  groupBy: 'day',
}

const mountOptions = {
  props: { data: analytics },
  global: {
    stubs: {
      Icon: true,
    },
  },
}

describe('RegistrationChart', () => {
  it('emits update:period when the period select changes', async () => {
    const wrapper = mount(RegistrationChart, mountOptions)

    await wrapper.find('[data-testid="select-period"]').setValue('90d')

    expect(wrapper.emitted('update:period')).toEqual([['90d']])
  })

  it('emits update:groupBy when the grouping select changes', async () => {
    const wrapper = mount(RegistrationChart, mountOptions)

    await wrapper.find('[data-testid="select-group-by"]').setValue('month')

    expect(wrapper.emitted('update:groupBy')).toEqual([['month']])
  })

  it('renders the line chart by default and switches to the bar chart on toggle', async () => {
    const wrapper = mount(RegistrationChart, mountOptions)

    expect(wrapper.find('[data-testid="chart-line"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chart-bar"]').exists()).toBe(false)

    await wrapper.find('[data-testid="btn-chart-type-bar"]').trigger('click')

    expect(wrapper.find('[data-testid="chart-bar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chart-line"]').exists()).toBe(false)

    await wrapper.find('[data-testid="btn-chart-type-line"]').trigger('click')

    expect(wrapper.find('[data-testid="chart-line"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chart-bar"]').exists()).toBe(false)
  })
})
