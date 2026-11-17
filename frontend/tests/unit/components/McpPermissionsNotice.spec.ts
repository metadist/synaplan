import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import McpPermissionsNotice from '@/components/config/mcp/McpPermissionsNotice.vue'

describe('McpPermissionsNotice', () => {
  it('says writes no longer ask and emits review and dismiss', async () => {
    const wrapper = mount(McpPermissionsNotice, { props: { showReview: true, busy: false } })
    expect(wrapper.text()).toContain('without asking')
    await wrapper.get('[data-testid="btn-mcp-notice-review"]').trigger('click')
    await wrapper.get('[data-testid="btn-mcp-notice-dismiss"]').trigger('click')
    expect(wrapper.emitted('review')).toHaveLength(1)
    expect(wrapper.emitted('dismiss')).toHaveLength(1)
  })

  it('hides Review where the permissions are already open', () => {
    const wrapper = mount(McpPermissionsNotice, { props: { showReview: false, busy: true } })
    expect(wrapper.find('[data-testid="btn-mcp-notice-review"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-testid="btn-mcp-notice-dismiss"]').attributes('disabled')
    ).toBeDefined()
  })
})
