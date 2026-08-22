import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DemoLoginHint from '@/components/auth/DemoLoginHint.vue'

const config = { setup: { demoLoginHint: false } }

vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))

describe('DemoLoginHint', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    config.setup.demoLoginHint = false
  })

  it('stays hidden until the runtime config marks a fresh demo install', () => {
    const wrapper = mount(DemoLoginHint)
    expect(wrapper.find('[data-testid="section-demo-login-hint"]').exists()).toBe(false)
  })

  it('shows the seeded admin and emits continue', async () => {
    config.setup.demoLoginHint = true
    const wrapper = mount(DemoLoginHint)

    expect(wrapper.find('[data-testid="section-demo-login-hint"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="demo-login-email"]').text()).toBe('admin@synaplan.com')
    expect(wrapper.get('[data-testid="demo-login-password"]').text()).toBe('admin123')

    await wrapper.get('[data-testid="btn-demo-login"]').trigger('click')
    expect(wrapper.emitted('continue')).toHaveLength(1)
  })
})
