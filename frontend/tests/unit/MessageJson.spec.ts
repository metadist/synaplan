import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageJson from '@/components/MessageJson.vue'

const chatListJson = JSON.stringify({
  total: 2,
  chats: [
    { id: 1, title: 'Knowledge Base One', source: 'web', message_count: 4 },
    { id: 2, title: 'Project notes', source: 'file', message_count: 1 },
  ],
})

describe('MessageJson', () => {
  it('renders chat records instead of a raw JSON dump', () => {
    const wrapper = mount(MessageJson, { props: { content: chatListJson } })

    expect(wrapper.text()).toContain('Knowledge Base One')
    expect(wrapper.text()).toContain('Project notes')
    expect(wrapper.text()).not.toContain('"message_count"')
    expect(wrapper.find('[data-testid="json-tree"]').exists()).toBe(false)
  })

  it('lets the user fold open the raw JSON tree', async () => {
    const wrapper = mount(MessageJson, { props: { content: chatListJson } })

    await wrapper.get('[data-testid="btn-toggle-json"]').trigger('click')

    expect(wrapper.find('[data-testid="json-tree"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('chats')
  })

  it('shows a foldable tree for unstructured JSON', () => {
    const wrapper = mount(MessageJson, {
      props: { content: '{"status":"ok","nested":{"count":3}}' },
    })

    expect(wrapper.find('[data-testid="json-tree"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="json-record-row"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('status')
  })

  it('lists the records of a payload the backend cut off, with a hint', () => {
    const cutOff =
      '{"total": 50, "chats": [{"id": 1, "title": "Knowledge Base One", "message_count": 4}, {"id": 2, "titl…'

    const wrapper = mount(MessageJson, { props: { content: cutOff } })

    expect(wrapper.findAll('[data-testid="json-record-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Knowledge Base One')
    expect(wrapper.find('[data-testid="json-truncated-hint"]').exists()).toBe(true)
  })

  it('copies pretty JSON', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
    })

    const wrapper = mount(MessageJson, { props: { content: chatListJson } })
    await wrapper.get('[data-testid="btn-copy-json"]').trigger('click')

    expect(writeText).toHaveBeenCalled()
    const copied = writeText.mock.calls[0]?.[0] as string
    expect(copied).toContain('\n')
    expect(copied).toContain('Knowledge Base One')
  })
})
