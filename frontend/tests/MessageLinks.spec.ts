import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageLinks from '@/components/MessageLinks.vue'

describe('MessageLinks', () => {
  const mockLinks = [
    {
      title: 'Example Site',
      url: 'https://example.com',
      desc: 'An example website',
      host: 'example.com',
    },
    {
      title: 'Test Site',
      url: 'https://test.com',
      desc: 'A test website',
      host: 'test.com',
    },
  ]

  it('should render all links', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    const links = wrapper.findAll('a')
    expect(links.length).toBe(2)
  })

  it('should render link titles', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    expect(wrapper.text()).toContain('Example Site')
    expect(wrapper.text()).toContain('Test Site')
  })

  it('should render link descriptions', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    expect(wrapper.text()).toContain('An example website')
    expect(wrapper.text()).toContain('A test website')
  })

  it('should render host badges', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    expect(wrapper.text()).toContain('example.com')
    expect(wrapper.text()).toContain('test.com')
  })

  it('should have correct href attributes', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    const links = wrapper.findAll('a')
    expect(links[0].attributes('href')).toBe('https://example.com')
    expect(links[1].attributes('href')).toBe('https://test.com')
  })

  it('should open links in new tab', () => {
    const wrapper = mount(MessageLinks, {
      props: {
        items: mockLinks,
      },
    })

    const links = wrapper.findAll('a')
    links.forEach(link => {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    })
  })
})
