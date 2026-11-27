import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessageMemories from '@/components/MessageMemories.vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

const memory: UserMemory = {
  id: 42,
  category: 'preferences',
  key: 'favorite_color',
  value: 'teal',
  source: 'user_created',
  messageId: null,
  created: 1,
  updated: 1,
}

function mountList(editable = true) {
  return mount(MessageMemories, {
    props: { memories: [memory], editable },
    global: { stubs: { Icon: true } },
  })
}

describe('MessageMemories', () => {
  beforeEach(() => {
    vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('edits and deletes the expanded memory without opening the memories page', async () => {
    const wrapper = mountList()
    await wrapper.get('[data-testid="btn-used-memories-toggle"]').trigger('click')

    await wrapper.get('[data-testid="btn-used-memory-edit"]').trigger('click')
    expect(wrapper.emitted('edit-memory')?.[0]).toEqual([memory])
    expect(wrapper.emitted('click-memory')).toBeUndefined()

    await wrapper.get('[data-testid="btn-used-memory-delete"]').trigger('click')
    expect(wrapper.emitted('delete-memory')?.[0]).toEqual([memory])
    expect(wrapper.emitted('click-memory')).toBeUndefined()
  })

  it('opens the memories page from the memory row', async () => {
    const wrapper = mountList()
    await wrapper.get('[data-testid="btn-used-memories-toggle"]').trigger('click')
    await wrapper.get('[data-testid="item-used-memory"]').trigger('click')
    expect(wrapper.emitted('click-memory')?.[0]).toEqual([memory])
  })

  it('hides edit and delete when the chat is not the owner’s', async () => {
    const wrapper = mountList(false)
    await wrapper.get('[data-testid="btn-used-memories-toggle"]').trigger('click')
    expect(wrapper.find('[data-testid="btn-used-memory-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-used-memory-delete"]').exists()).toBe(false)
  })

  it('expands the memory this answer focuses', async () => {
    const wrapper = mountList()
    await wrapper.setProps({ memoryFocus: { id: 42, token: 1 } })
    await flushPromises()

    const row = wrapper.get('[data-testid="item-used-memory"]')
    expect(row.classes()).toContain('ring-brand')
    expect(wrapper.find('[data-testid="btn-used-memory-edit"]').exists()).toBe(true)
  })

  it('does not expand a memory this answer did not use', async () => {
    const wrapper = mountList()
    await wrapper.setProps({ memoryFocus: { id: 99, token: 1 } })
    await flushPromises()
    expect(wrapper.find('[data-testid="item-used-memory"]').exists()).toBe(false)
  })
})
