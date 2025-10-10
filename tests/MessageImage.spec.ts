import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageImage from '@/components/MessageImage.vue'

describe('MessageImage', () => {
  it('should render image with correct src', () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
        alt: 'Test image',
      },
    })

    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://example.com/image.jpg')
  })

  it('should render alt text', () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
        alt: 'Test image',
      },
    })

    const img = wrapper.find('img')
    expect(img.attributes('alt')).toBe('Test image')
    expect(wrapper.text()).toContain('Test image')
  })

  it('should have aspect-video class for 16:9 ratio', () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
      },
    })

    expect(wrapper.find('.aspect-video').exists()).toBe(true)
  })

  it('should have object-cover for image', () => {
    const wrapper = mount(MessageImage, {
      props: {
        url: 'https://example.com/image.jpg',
      },
    })

    const img = wrapper.find('img')
    expect(img.classes()).toContain('object-cover')
  })
})
