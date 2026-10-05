import { describe, it, expect, vi, afterEach } from 'vitest'
import {
  MESSAGE_HIGHLIGHT_CLASS,
  OPEN_MESSAGE_CHAT_QUERY,
  OPEN_MESSAGE_MESSAGE_QUERY,
  highlightMessageElement,
  openMessageLocation,
  openSourceMessage,
  parseOpenMessageQuery,
  parsePositiveQueryId,
  type OpenSourceMessageDeps,
} from '@/composables/useScrollToMessage'

function readyDeps(overrides: Partial<OpenSourceMessageDeps> = {}): OpenSourceMessageDeps {
  return {
    ensureChatReady: vi.fn().mockResolvedValue('ready'),
    isMessageLoaded: () => true,
    hasMoreMessages: () => false,
    loadOlderMessages: vi.fn().mockResolvedValue(undefined),
    loadedCount: () => 1,
    findElement: () => document.createElement('div'),
    nextTick: async () => undefined,
    notifyMissing: vi.fn(),
    ...overrides,
  }
}

describe('parseOpenMessageQuery', () => {
  it('reads positive chat and message ids', () => {
    expect(parseOpenMessageQuery({ chat: '12', message: '34' })).toEqual({
      chatId: 12,
      messageId: 34,
    })
    expect(parseOpenMessageQuery({ chat: ['8'], message: ['9'] })).toEqual({
      chatId: 8,
      messageId: 9,
    })
  })

  it('rejects a missing, zero, or non-integer id', () => {
    expect(parseOpenMessageQuery({ chat: '4' })).toBeNull()
    expect(parseOpenMessageQuery({ message: '4' })).toBeNull()
    expect(parseOpenMessageQuery({ chat: '0', message: '4' })).toBeNull()
    expect(parseOpenMessageQuery({ chat: '4', message: '1.5' })).toBeNull()
    expect(parseOpenMessageQuery({ chat: '4', message: 'nope' })).toBeNull()
    expect(parsePositiveQueryId('')).toBeNull()
    expect(parsePositiveQueryId(null)).toBeNull()
  })
})

describe('openMessageLocation', () => {
  it('builds the chat route query the memories list can push', () => {
    expect(openMessageLocation(12, 34)).toEqual({
      name: 'chat',
      query: {
        [OPEN_MESSAGE_CHAT_QUERY]: '12',
        [OPEN_MESSAGE_MESSAGE_QUERY]: '34',
      },
    })
    expect(OPEN_MESSAGE_CHAT_QUERY).toBe('chat')
    expect(OPEN_MESSAGE_MESSAGE_QUERY).toBe('message')
  })
})

describe('openSourceMessage', () => {
  it('scrolls a message that is already loaded, including when that chat is already open', async () => {
    const element = document.createElement('div')
    element.scrollIntoView = vi.fn()
    const deps = readyDeps({
      findElement: () => element,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('found')
    expect(deps.ensureChatReady).toHaveBeenCalledWith(5)
    expect(deps.loadOlderMessages).not.toHaveBeenCalled()
    expect(element.scrollIntoView).toHaveBeenCalledWith({ block: 'center', inline: 'nearest' })
    expect(element.classList.contains(MESSAGE_HIGHLIGHT_CLASS)).toBe(true)
    expect(deps.notifyMissing).not.toHaveBeenCalled()
  })

  it('loads older pages until the message is present', async () => {
    const pages = [
      [1, 2],
      [1, 2, 3],
      [1, 2, 3, 9],
    ]
    let page = 0
    const element = document.createElement('div')
    element.scrollIntoView = vi.fn()
    const loadOlderMessages = vi.fn(async () => {
      page += 1
    })
    const deps = readyDeps({
      isMessageLoaded: (id) => pages[page].includes(id),
      hasMoreMessages: () => page < pages.length - 1,
      loadOlderMessages,
      loadedCount: () => pages[page].length,
      findElement: () => element,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('found')
    expect(loadOlderMessages).toHaveBeenCalledTimes(2)
    expect(element.scrollIntoView).toHaveBeenCalledOnce()
    expect(deps.notifyMissing).not.toHaveBeenCalled()
  })

  it('tells the user once when history is exhausted', async () => {
    const deps = readyDeps({
      isMessageLoaded: () => false,
      hasMoreMessages: () => false,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('missing')
    expect(deps.notifyMissing).toHaveBeenCalledOnce()
    expect(deps.loadOlderMessages).not.toHaveBeenCalled()
  })

  it('stops when an older page adds nothing', async () => {
    const loadOlderMessages = vi.fn().mockResolvedValue(undefined)
    const deps = readyDeps({
      isMessageLoaded: () => false,
      hasMoreMessages: () => true,
      loadOlderMessages,
      loadedCount: () => 50,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('missing')
    expect(loadOlderMessages).toHaveBeenCalledOnce()
    expect(deps.notifyMissing).toHaveBeenCalledOnce()
  })

  it('does not toast when the chat is gone', async () => {
    const deps = readyDeps({
      ensureChatReady: vi.fn().mockResolvedValue('gone'),
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('gone')
    expect(deps.notifyMissing).not.toHaveBeenCalled()
    expect(deps.loadOlderMessages).not.toHaveBeenCalled()
  })

  it('does not toast when a newer request cancels paging', async () => {
    let cancel = false
    const deps = readyDeps({
      isMessageLoaded: () => false,
      hasMoreMessages: () => true,
      loadOlderMessages: vi.fn(async () => {
        cancel = true
      }),
      loadedCount: () => 10,
      isCancelled: () => cancel,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('cancelled')
    expect(deps.notifyMissing).not.toHaveBeenCalled()
  })

  it('toasts when the message is loaded but not in the document', async () => {
    const deps = readyDeps({
      findElement: () => null,
    })

    const result = await openSourceMessage({ chatId: 5, messageId: 9 }, deps)

    expect(result).toBe('missing')
    expect(deps.notifyMissing).toHaveBeenCalledOnce()
  })
})

describe('highlightMessageElement', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('removes the highlight when the animation ends', () => {
    const element = document.createElement('div')
    document.body.appendChild(element)
    highlightMessageElement(element)
    expect(element.classList.contains(MESSAGE_HIGHLIGHT_CLASS)).toBe(true)

    element.dispatchEvent(new Event('animationend'))

    expect(element.classList.contains(MESSAGE_HIGHLIGHT_CLASS)).toBe(false)
  })
})
