import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageCode from '@/components/MessageCode.vue'

describe('MessageCode', () => {
  it('should render code content', () => {
    const wrapper = mount(MessageCode, {
      props: {
        content: 'const x = 42;',
        language: 'typescript',
        filename: 'example.ts',
      },
    })

    expect(wrapper.text()).toContain('const x = 42;')
  })

  it('should display language label', () => {
    const wrapper = mount(MessageCode, {
      props: {
        content: 'print("hello")',
        language: 'python',
      },
    })

    expect(wrapper.text()).toContain('python')
  })

  it('should display filename', () => {
    const wrapper = mount(MessageCode, {
      props: {
        content: 'const x = 42;',
        filename: 'test.ts',
      },
    })

    expect(wrapper.text()).toContain('test.ts')
  })

  it('should have copy button', () => {
    const wrapper = mount(MessageCode, {
      props: {
        content: 'const x = 42;',
      },
    })

    const copyButton = wrapper.find('button')
    expect(copyButton.exists()).toBe(true)
  })

  it('should copy code to clipboard on click', async () => {
    const mockWriteText = vi.fn()
    Object.defineProperty(navigator, 'clipboard', {
      value: {
        writeText: mockWriteText,
      },
      writable: true,
    })

    const wrapper = mount(MessageCode, {
      props: {
        content: 'const x = 42;',
      },
    })

    const copyButton = wrapper.find('button')
    await copyButton.trigger('click')

    expect(mockWriteText).toHaveBeenCalledWith('const x = 42;')
  })

  it('should show copied state after copy', async () => {
    const mockWriteText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', {
      value: {
        writeText: mockWriteText,
      },
      writable: true,
    })

    const wrapper = mount(MessageCode, {
      props: {
        content: 'const x = 42;',
      },
    })

    const copyButton = wrapper.find('button')
    await copyButton.trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Copied')
  })
})
