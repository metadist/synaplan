import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminPreview from '@/components/common/AdminPreview.vue'
import type { AdminPreviewFeature } from '@/composables/useAdminPreview'

let admin = false

vi.mock('@/composables/useAdminPreview', () => ({
  isAdminPreview: () => admin,
}))

const TEST_FEATURE = 'test-preview' as AdminPreviewFeature

describe('AdminPreview', () => {
  beforeEach(() => {
    admin = false
  })

  it('renders nothing for a regular user', () => {
    const wrapper = mount(AdminPreview, {
      props: { feature: TEST_FEATURE },
      slots: { default: '<p data-testid="preview-body">Preview body</p>' },
    })

    expect(wrapper.text()).toBe('')
    expect(wrapper.find('[data-testid="preview-body"]').exists()).toBe(false)
  })

  it('renders the slot for an admin', () => {
    admin = true
    const wrapper = mount(AdminPreview, {
      props: { feature: TEST_FEATURE },
      slots: { default: '<p>Preview body</p>' },
    })

    expect(wrapper.text()).toContain('Preview body')
    expect(wrapper.find('[data-testid="badge-admin-preview"]').exists()).toBe(false)
  })

  it('shows a static badge that says other people cannot see this yet', () => {
    admin = true
    const wrapper = mount(AdminPreview, {
      props: { feature: TEST_FEATURE, badge: true },
    })

    const badge = wrapper.get('[data-testid="badge-admin-preview"]')
    expect(badge.text()).toBe('Admin preview')
    expect(badge.attributes('title')).toContain('Only admins')
    expect(badge.element.tagName).toBe('SPAN')
  })
})
