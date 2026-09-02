import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageText from '@/components/MessageText.vue'
import type { PlatformDocRef } from '@/components/chat/refs/DocRefPill'

function messageTextEl(wrapper: ReturnType<typeof mount>): HTMLElement {
  return wrapper.get('[data-testid="message-text"]').element as HTMLElement
}

const channelsDoc: PlatformDocRef = {
  slug: 'channels',
  title: 'Channels: WhatsApp & Email',
  url: 'https://docs.synaplan.com/channels',
}

describe('MessageText [Doc:slug] pills', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('renders a known slug as a link with the given url', async () => {
    const wrapper = mount(MessageText, {
      props: {
        content: 'See [Doc:channels] for setup.',
        docs: [channelsDoc],
        readonly: true,
      },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    const link = el.querySelector('a.pill[data-doc-slug="channels"]')
    expect(link).not.toBeNull()
    expect(link?.getAttribute('href')).toBe(channelsDoc.url)
    expect(link?.getAttribute('target')).toBe('_blank')
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer')
    expect(el.textContent).toContain('Channels: WhatsApp & Email')
    expect(el.textContent).not.toContain('[Doc:channels]')
  })

  it('renders an unknown slug as plain text with no link', async () => {
    const wrapper = mount(MessageText, {
      props: {
        content: 'See [Doc:missing-page] for setup.',
        docs: [channelsDoc],
        readonly: true,
      },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('a.pill')).toBeNull()
    expect(el.textContent).toContain('[Doc:missing-page]')
  })

  it('never builds an href from a hardcoded host', async () => {
    const listedUrl = 'https://example.test/guide/channels'
    const wrapper = mount(MessageText, {
      props: {
        content: 'See [Doc:channels] for setup.',
        docs: [{ ...channelsDoc, url: listedUrl }],
        readonly: true,
      },
    })
    await wrapper.vm.$nextTick()

    const href = messageTextEl(wrapper)
      .querySelector('a.pill[data-doc-slug="channels"]')
      ?.getAttribute('href')
    expect(href).toBe(listedUrl)
    expect(href).not.toContain('docs.synaplan.com')
    expect(href).not.toContain('localhost')
  })

  it('leaves [Doc:a, b] untouched', async () => {
    const wrapper = mount(MessageText, {
      props: {
        content: 'Bad cite [Doc:a, b] stays raw.',
        docs: [channelsDoc],
        readonly: true,
      },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('a.pill')).toBeNull()
    expect(el.textContent).toContain('[Doc:a, b]')
  })

  it('turns the token into a pill only after the closing bracket arrives', async () => {
    const wrapper = mount(MessageText, {
      props: {
        content: 'See [Doc:channels',
        docs: [channelsDoc],
        isStreaming: true,
        readonly: true,
      },
    })
    await wrapper.vm.$nextTick()

    expect(messageTextEl(wrapper).querySelector('a.pill')).toBeNull()

    await wrapper.setProps({ content: 'See [Doc:channels] for setup.' })
    await wrapper.vm.$nextTick()

    expect(messageTextEl(wrapper).querySelector('a.pill[data-doc-slug="channels"]')).not.toBeNull()
  })
})
