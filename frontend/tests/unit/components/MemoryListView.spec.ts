import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MemoryListView from '@/components/MemoryListView.vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

function makeMemory(id: number, category: string, key: string): UserMemory {
  return {
    id,
    category,
    key,
    value: `Value ${id}`,
    source: 'user_created',
    messageId: null,
    created: 1705234567,
    updated: 1705234567,
  }
}

describe('MemoryListView', () => {
  it('offers to add the first memory when there are none', async () => {
    const wrapper = mount(MemoryListView, {
      props: { memories: [], availableCategories: [] },
      global: { mocks: { $t: (key: string) => key }, stubs: { Icon: true } },
    })
    const empty = wrapper.get('[data-testid="state-memories-empty"]')
    expect(empty.text()).toContain('memories.empty')
    await empty.get('[data-testid="btn-memories-empty-create"]').trigger('click')
    expect(wrapper.emitted('create')).toHaveLength(1)
  })

  it('should render all memories in the mobile card list (no accidental limit)', () => {
    const memories: UserMemory[] = [
      makeMemory(1, 'personal', 'name'),
      makeMemory(2, 'personal', 'age'),
      makeMemory(3, 'personal', 'diet'),
      makeMemory(4, 'work', 'position'),
      makeMemory(5, 'preferences', 'timezone'),
      makeMemory(6, 'projects', 'synaplan'),
      makeMemory(7, 'personal', 'city'),
    ]

    const wrapper = mount(MemoryListView, {
      props: {
        memories,
        availableCategories: [{ category: 'personal', count: 5 }],
      },
      global: {
        mocks: {
          $t: (key: string) => key,
        },
        stubs: {
          Icon: true,
        },
      },
    })

    // Mobile list is always rendered in DOM (hidden via CSS classes).
    const mobileCards = wrapper.findAll('div.md\\:hidden div.surface-card[data-memory-id]')
    expect(mobileCards.length).toBe(memories.length)
  })

  it('should keep selects constrained to viewport width (w-full + max-w-full)', () => {
    const wrapper = mount(MemoryListView, {
      props: {
        memories: [makeMemory(1, 'personal', 'name')],
        availableCategories: [{ category: 'personal', count: 1 }],
      },
      global: {
        mocks: {
          $t: (key: string) => key,
        },
        stubs: {
          Icon: true,
        },
      },
    })

    const selects = wrapper.findAll('select')
    expect(selects.length).toBe(2)
    for (const select of selects) {
      expect(select.classes()).toContain('w-full')
      expect(select.classes()).toContain('max-w-full')
      expect(select.classes()).toContain('min-w-0')
    }
  })
})
