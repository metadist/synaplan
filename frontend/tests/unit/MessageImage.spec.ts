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

  // One teardown for the whole file. Restoring per describe block invites
  // order-dependent failures, because a block that stubs a global the block
  // above it did not has to remember to undo it.
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
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

  describe('preview box (issue #2405)', () => {
    const box = (wrapper: ReturnType<typeof mount>) =>
      wrapper.find('[data-testid="btn-image-fullscreen"]')

    it('keeps a fixed 16:9 placeholder until the image has loaded', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      expect(box(wrapper).classes()).toContain('aspect-video')
      expect(wrapper.text()).toContain('Loading')
    })

    it('shows the loaded image whole, at its own aspect ratio inside a height cap', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      const img = wrapper.find('[data-testid="img-message-image"]')
      await img.trigger('load')

      expect(box(wrapper).classes()).not.toContain('aspect-video')
      expect(img.classes()).not.toContain('object-cover')
      expect(img.classes()).toContain('object-contain')
      expect(img.classes()).toContain('max-w-full')
      expect(img.classes().some((name) => name.startsWith('max-h-'))).toBe(true)
      expect(img.classes().some((name) => name.includes('scale-'))).toBe(false)
    })

    it('returns to the fixed placeholder while a loaded image retries after an error', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      const img = wrapper.find('[data-testid="img-message-image"]')
      await img.trigger('load')
      expect(box(wrapper).classes()).not.toContain('aspect-video')

      // The first error retries with a fresh credential; the box must not collapse meanwhile.
      await img.trigger('error')
      await flushPromises()

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(false)
      expect(box(wrapper).classes()).toContain('aspect-video')
      expect(wrapper.text()).toContain('Loading')

      await wrapper.find('[data-testid="img-message-image"]').trigger('load')
      expect(box(wrapper).classes()).not.toContain('aspect-video')
    })

    it('keeps the fixed placeholder size after the image failed to load', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      const img = wrapper.find('[data-testid="img-message-image"]')
      await img.trigger('load')
      // First error retries with a fresh credential, the second one gives up.
      await img.trigger('error')
      await flushPromises()
      await wrapper.find('[data-testid="img-message-image"]').trigger('error')
      await flushPromises()

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(true)
      expect(box(wrapper).classes()).toContain('aspect-video')
    })
  })

  describe('download (issue #1071)', () => {
    it('renders a download button once the image has loaded', async () => {
      const wrapper = mount(MessageImage, {
        props: { url: 'https://example.com/image.jpg' },
      })
      await flushPromises()

      expect(wrapper.find('[data-testid="btn-image-download"]').exists()).toBe(true)
    })

    it('fetches an authenticated blob for the download of an internal image', async () => {
      vi.spyOn(global.URL, 'createObjectURL').mockReturnValue('blob:mock-url')
      vi.spyOn(global.URL, 'revokeObjectURL').mockImplementation(() => undefined)
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
      vi.stubGlobal('fetch', fetchMock)

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

      // The visible image is a plain `src`, so the download fetches its own
      // blob over the Bearer-authenticated path before saving it.
      expect(clickSpy).toHaveBeenCalledTimes(1)
    })
  })

  describe('load failure', () => {
    const url = '/api/v1/files/uploads/1/000/gone.png'

    // The element reports the failure, so a stale credential gets one silent
    // retry before the user is told anything.
    const failTwice = async (wrapper: ReturnType<typeof mount>) => {
      await wrapper.find('img').trigger('error')
      await flushPromises()
      await wrapper.find('img').trigger('error')
      await flushPromises()
    }

    it('shows a retryable error instead of an endless loading state', async () => {
      const wrapper = mount(MessageImage, { props: { url } })
      await flushPromises()

      await failTwice(wrapper)

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="btn-image-retry"]').exists()).toBe(true)
      expect(wrapper.find('img').exists()).toBe(false)
    })

    it('retries once on its own before surfacing an error', async () => {
      const wrapper = mount(MessageImage, { props: { url } })
      await flushPromises()

      await wrapper.find('img').trigger('error')
      await flushPromises()

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(false)
      expect(wrapper.find('img').exists()).toBe(true)
    })

    it('recovers when the retry succeeds', async () => {
      const wrapper = mount(MessageImage, { props: { url } })
      await flushPromises()
      await failTwice(wrapper)

      await wrapper.find('[data-testid="btn-image-retry"]').trigger('click')
      await flushPromises()
      await wrapper.find('img').trigger('load')

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(false)
      expect(wrapper.find('img').exists()).toBe(true)
    })

    it('gives a later error its own silent retry after a successful load', async () => {
      const wrapper = mount(MessageImage, { props: { url } })
      await flushPromises()

      await wrapper.find('img').trigger('error')
      await flushPromises()
      await wrapper.find('img').trigger('load')

      await wrapper.find('img').trigger('error')
      await flushPromises()

      expect(wrapper.find('[data-testid="image-load-error"]').exists()).toBe(false)
      expect(wrapper.find('img').exists()).toBe(true)
    })
  })
})
