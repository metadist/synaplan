import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import MessageText from '@/components/MessageText.vue'
import en from '@/i18n/locales/en/chat.json'
import de from '@/i18n/locales/de/chat.json'

function mountWithLocale(locale: 'en' | 'de', content: string) {
  const messages = {
    en: { message: en.message },
    de: { message: de.message },
  }
  const i18n = createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages,
  })
  return mount(MessageText, {
    props: { content, isStreaming: false, readonly: true },
    global: {
      plugins: [i18n, createPinia()],
      stubs: {
        MessageCode: true,
        MessageJson: true,
        MessageTable: true,
      },
    },
  })
}

describe('MessageText media markers', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('renders image and video markers localized in English', async () => {
    const image = mountWithLocale('en', '__IMAGE_GENERATED__')
    await flushPromises()
    expect(image.text()).toContain(en.message.imageGenerated)

    const video = mountWithLocale('en', '__VIDEO_GENERATED__')
    await flushPromises()
    expect(video.text()).toContain(en.message.videoGenerated)
  })

  it('renders image and video markers localized in German', async () => {
    const image = mountWithLocale('de', '__IMAGE_GENERATED__')
    await flushPromises()
    expect(image.text()).toContain(de.message.imageGenerated)

    const video = mountWithLocale('de', '__VIDEO_GENERATED__')
    await flushPromises()
    expect(video.text()).toContain(de.message.videoGenerated)
  })
})
