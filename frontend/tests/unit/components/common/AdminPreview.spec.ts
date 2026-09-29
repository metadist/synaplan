import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminPreview from '@/components/common/AdminPreview.vue'

let admin = false

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return admin
    },
  }),
}))

describe('AdminPreview', () => {
  beforeEach(() => {
    admin = false
  })

  it('renders nothing for a regular user', () => {
    const wrapper = mount(AdminPreview, {
      props: { feature: 'telegram' },
      slots: { default: '<p data-testid="preview-body">Connect Telegram</p>' },
    })

    expect(wrapper.text()).toBe('')
    expect(wrapper.find('[data-testid="preview-body"]').exists()).toBe(false)
  })

  it('renders the slot for an admin', () => {
    admin = true
    const wrapper = mount(AdminPreview, {
      props: { feature: 'telegram' },
      slots: { default: '<p>Connect Telegram</p>' },
    })

    expect(wrapper.text()).toContain('Connect Telegram')
    expect(wrapper.find('[data-testid="badge-admin-preview"]').exists()).toBe(false)
  })

  it('shows a static badge that says other people cannot see this yet', () => {
    admin = true
    const wrapper = mount(AdminPreview, {
      props: { feature: 'telegram', badge: true },
    })

    const badge = wrapper.get('[data-testid="badge-admin-preview"]')
    expect(badge.text()).toBe('Admin preview')
    expect(badge.attributes('title')).toContain('Only admins')
    expect(badge.element.tagName).toBe('SPAN')
  })
})
