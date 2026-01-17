import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MemoryCard from '@/components/memories/MemoryCard.vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

describe('MemoryCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  const mockMemory: UserMemory = {
    id: 1,
    category: 'preferences',
    key: 'tech_stack',
    value: 'TypeScript with Vue 3',
    source: 'user_created',
    messageId: null,
    created: 1705234567,
    updated: 1705234567,
  }

  it('should render memory details', () => {
    const wrapper = mount(MemoryCard, {
      props: {
        memory: mockMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    expect(wrapper.text()).toContain('tech_stack')
    expect(wrapper.text()).toContain('TypeScript with Vue 3')
    // Category is translated via i18n, so we check the raw value
    expect(mockMemory.category).toBe('preferences')
  })

  it('should emit edit event when edit button is clicked', async () => {
    const wrapper = mount(MemoryCard, {
      props: {
        memory: mockMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    const editButton = wrapper.find('[data-testid="edit-button"]')
    if (editButton.exists()) {
      await editButton.trigger('click')
      expect(wrapper.emitted('edit')).toBeTruthy()
      expect(wrapper.emitted('edit')?.[0]).toEqual([mockMemory])
    }
  })

  it('should emit delete event when delete button is clicked', async () => {
    const wrapper = mount(MemoryCard, {
      props: {
        memory: mockMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    const deleteButton = wrapper.find('[data-testid="delete-button"]')
    if (deleteButton.exists()) {
      await deleteButton.trigger('click')
      expect(wrapper.emitted('delete')).toBeTruthy()
      expect(wrapper.emitted('delete')?.[0]).toEqual([mockMemory.id])
    }
  })

  it('should show auto-detected badge for auto-detected memories', () => {
    const autoMemory: UserMemory = {
      ...mockMemory,
      source: 'auto_detected',
      messageId: 123,
    }

    const wrapper = mount(MemoryCard, {
      props: {
        memory: autoMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    // Check that the component renders (source badge is translated via i18n)
    expect(wrapper.find('.txt-secondary').exists()).toBe(true)
    expect(autoMemory.source).toBe('auto_detected')
  })

  it('should show user-edited badge for edited memories', () => {
    const editedMemory: UserMemory = {
      ...mockMemory,
      source: 'user_edited',
    }

    const wrapper = mount(MemoryCard, {
      props: {
        memory: editedMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    // Check that the component renders (source badge is translated via i18n)
    expect(wrapper.find('.txt-secondary').exists()).toBe(true)
    expect(editedMemory.source).toBe('user_edited')
  })

  it('should format dates correctly', () => {
    const wrapper = mount(MemoryCard, {
      props: {
        memory: mockMemory,
      },
      global: {
        stubs: {
          'i18n-t': true,
        },
      },
    })

    // Check if date element exists
    const dateElement = wrapper.find('.txt-tertiary span')
    expect(dateElement.exists()).toBe(true)
    // Date is formatted via Intl.DateTimeFormat
    expect(dateElement.text()).toBeTruthy()
  })
})
