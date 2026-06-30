import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageImage from '@/components/MessageImage.vue'

describe('MessageImage', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // Provide #app teleport target
    if (!document.getElementById('app')) {
      const app = document.createElement('div')
      app.id = 'app'
      document.body.appendChild(app)
    }
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

  describe('download (issue #1071)', () => {
    const originalFetch = global.fetch
    const originalCreate = global.URL.createObjectURL
    const originalRevoke = global.URL.revokeObjectURL

    afterEach(() => {
      global.fetch = originalFetch
      global.URL.createObjectURL = originalCreate
      global.URL.revokeObjectURL = originalRevoke
      vi.restoreAllMocks()
    })

    it('renders a download button once the image has loaded', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      expect(wrapper.find('[data-testid="btn-image-download"]').exists()).toBe(true)
    })

    it('triggers a real download from the loaded blob for internal images', async () => {
      global.URL.createObjectURL = vi.fn(() => 'blob:mock-url')
      global.URL.revokeObjectURL = vi.fn()
      const fetchMock = vi.fn((url: RequestInfo | URL) => {
        if (typeof url === 'string' && url.includes('/config/runtime')) {
          return Promise.resolve({
            ok: true,
            json: () =>
              Promise.resolve({
                recaptcha: { enabled: false, siteKey: '' },
                features: { help: false },
              }),
          })
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          statusText: 'OK',
          blob: () => Promise.resolve(new Blob(['image-bytes'])),
        })
      })
      global.fetch = fetchMock as unknown as typeof fetch

      const clickSpy = vi
        .spyOn(HTMLAnchorElement.prototype, 'click')
        .mockImplementation(() => undefined)

      const wrapper = mount(MessageImage, {
        props: { url: '/api/v1/files/uploads/1/000/cat.png', alt: 'cat' },
      })
      await flushPromises()

      const button = wrapper.find('[data-testid="btn-image-download"]')
      expect(button.exists()).toBe(true)

      await button.trigger('click')
      await flushPromises()

      // Internal image is fetched once (during load); the download reuses that
      // blob, so the anchor is clicked without a second network round-trip.
      expect(clickSpy).toHaveBeenCalledTimes(1)
    })
  })
})
