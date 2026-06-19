import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageText from '@/components/MessageText.vue'

// These tests lock in the anti-flash guarantee (no streaming flicker): while a
// message streams, morphdom must keep the DOM nodes of already-finished blocks
// PHYSICALLY identical between chunks. If a finished paragraph were torn down
// and rebuilt on every chunk (the old v-html behaviour) its element reference
// would change — that is exactly the flash the user reported.

function messageTextEl(wrapper: ReturnType<typeof mount>): HTMLElement {
  return wrapper.get('[data-testid="message-text"]').element as HTMLElement
}

describe('MessageText streaming (anti-flash)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('keeps a finished paragraph node identical as the in-progress tail grows', async () => {
    const wrapper = mount(MessageText, {
      props: { content: '', isStreaming: true, readonly: true },
    })

    // Two paragraphs: the first is "sealed" (followed by a blank line), the
    // second is still streaming.
    await wrapper.setProps({ content: 'Para A done.\n\nPara B start' })

    const paragraphsBefore = messageTextEl(wrapper).querySelectorAll('p')
    expect(paragraphsBefore.length).toBe(2)
    const sealedParagraph = paragraphsBefore[0]
    expect(sealedParagraph.textContent).toBe('Para A done.')

    // The tail grows with more streamed text.
    await wrapper.setProps({ content: 'Para A done.\n\nPara B start more text' })

    const paragraphsAfter = messageTextEl(wrapper).querySelectorAll('p')
    // The sealed paragraph must be the SAME DOM node (not re-created).
    expect(paragraphsAfter[0]).toBe(sealedParagraph)
    expect(paragraphsAfter[0].textContent).toBe('Para A done.')
    // The tail did update.
    expect(messageTextEl(wrapper).textContent).toContain('more text')
  })

  it('keeps earlier sealed paragraphs stable when a new paragraph is appended', async () => {
    const wrapper = mount(MessageText, {
      props: { content: 'First.\n\nSecond in progress', isStreaming: true, readonly: true },
    })

    const firstBefore = messageTextEl(wrapper).querySelectorAll('p')[0]
    expect(firstBefore.textContent).toBe('First.')

    // Second paragraph completes and a third one begins — a fresh paragraph
    // boundary, which previously re-rendered the WHOLE message.
    await wrapper.setProps({ content: 'First.\n\nSecond done.\n\nThird in progress' })

    const paragraphsAfter = messageTextEl(wrapper).querySelectorAll('p')
    expect(paragraphsAfter.length).toBe(3)
    // The first paragraph node survived untouched.
    expect(paragraphsAfter[0]).toBe(firstBefore)
    expect(paragraphsAfter[2].textContent).toContain('Third in progress')
  })

  it('renders inline markdown in the streaming tail (bold stays bold)', async () => {
    const wrapper = mount(MessageText, {
      props: { content: 'Intro **bold** and ', isStreaming: true, readonly: true },
    })

    const strong = messageTextEl(wrapper).querySelector('strong')
    expect(strong?.textContent).toBe('bold')
  })

  // Regression (issue #903): a single `$` (prices like "19 $", shell vars, …)
  // used to flag the whole message as "math" and route it through a RAW
  // (un-formatted) streaming renderer that only upgraded to formatted markdown
  // after a debounce — so **bold** flashed as literal `**bold**` on every
  // chunk. Markdown must render immediately even when a `$` is present.
  it('renders bold immediately when content contains a $ (no raw-markdown flash)', async () => {
    const wrapper = mount(MessageText, {
      props: {
        content: 'Der Preis ist 19 $ und das ist **wichtig**',
        isStreaming: true,
        readonly: true,
      },
    })

    const el = messageTextEl(wrapper)
    expect(el.querySelector('strong')?.textContent).toBe('wichtig')
    // The raw markers must NOT be visible as text.
    expect(el.textContent).not.toContain('**wichtig**')
  })

  // Regression (issue #903): two currency dollars on one line ("19 $/Monat …
  // 39 $/Monat") were parsed as a KaTeX formula, eating both `$`, rendering
  // the span (incl. the second **bold**) in math italic and leaking raw `**`.
  it('renders both bolds and keeps currency when a line has two dollar signs', async () => {
    const line =
      '**Copilot Business** (ca. 19 $/Nutzer/Monat) und **Copilot Enterprise** (ca. 39 $/Nutzer/Monat).'
    const wrapper = mount(MessageText, {
      props: { content: line, isStreaming: true, readonly: true },
    })

    const el = messageTextEl(wrapper)
    const strongs = [...el.querySelectorAll('strong')].map((s) => s.textContent)
    expect(strongs).toContain('Copilot Business')
    expect(strongs).toContain('Copilot Enterprise')
    // Raw markers must not leak, and the dollars must survive.
    expect(el.textContent).not.toContain('**')
    expect(el.textContent).toContain('19 $')
    expect(el.textContent).toContain('39 $')
  })

  it('keeps bold formatted across chunks when a $ is present', async () => {
    const wrapper = mount(MessageText, {
      props: { content: 'Kostet 5 $. **Fett** bleibt', isStreaming: true, readonly: true },
    })

    expect(messageTextEl(wrapper).querySelector('strong')?.textContent).toBe('Fett')

    // Next chunk arrives — bold must stay rendered, never revert to raw text.
    await wrapper.setProps({ content: 'Kostet 5 $. **Fett** bleibt fett und mehr Text' })

    const el = messageTextEl(wrapper)
    expect(el.querySelector('strong')?.textContent).toBe('Fett')
    expect(el.textContent).not.toContain('**Fett**')
    expect(el.textContent).toContain('mehr Text')
  })
})
