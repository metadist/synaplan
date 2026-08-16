import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ConnectionStatusPill from '@/components/config/ConnectionStatusPill.vue'

const STATUSES = ['connected', 'error', 'reauth_required', 'never_tested', 'disconnected'] as const

describe('ConnectionStatusPill', () => {
  it.each(STATUSES)('renders the %s status', (status) => {
    const wrapper = mount(ConnectionStatusPill, { props: { status } })
    expect(
      wrapper.get(`[data-testid="connection-status-${status}"]`).text().length
    ).toBeGreaterThan(0)
  })
})
