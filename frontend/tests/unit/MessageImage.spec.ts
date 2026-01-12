import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageImage from '@/components/MessageImage.vue'

describe('MessageImage', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('should render image with correct src', async () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
        alt: 'Test image',
      },
    })

    // Wait for async loadImage() to complete
    await flushPromises()

    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://example.com/image.jpg')
  })

  it('should render alt text', async () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
        alt: 'Test image',
      },
    })

    // Wait for async loadImage() to complete
    await flushPromises()

    const img = wrapper.find('img')
    expect(img.attributes('alt')).toBe('Test image')
    expect(wrapper.text()).toContain('Test image')
  })

  it('should have aspect-video class for 16:9 ratio', async () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
      },
    })

    // Wait for async loadImage() to complete
    await flushPromises()

    expect(wrapper.find('.aspect-video').exists()).toBe(true)
  })

  it('should have object-cover for image', async () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
      },
    })

    // Wait for async loadImage() to complete
    await flushPromises()

    const img = wrapper.find('img')
    expect(img.classes()).toContain('object-cover')
  })
})
