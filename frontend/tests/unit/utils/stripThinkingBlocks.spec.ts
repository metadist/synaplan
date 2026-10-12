import { describe, expect, it } from 'vitest'
import { stripThinkingBlocks, visiblePreview } from '@/utils/stripThinkingBlocks'

const hi =
  '<think>We have a conversation where the user is just saying "hi". Use concise.</think>Hi there! How can I help you today?'
const hello =
  '<think>User says "hellooooo". Likely English. Need to respond friendly, ask how can help.</think>Hello! How can I assist you today?'

describe('stripThinkingBlocks', () => {
  it('keeps the sentence after a closed scratchpad', () => {
    expect(stripThinkingBlocks(hi)).toBe('Hi there! How can I help you today?')
    expect(stripThinkingBlocks(hello)).toBe('Hello! How can I assist you today?')
  })

  it('drops an unclosed trailing scratchpad', () => {
    expect(stripThinkingBlocks('<think>still thinking')).toBe('')
  })

  it('leaves a visitor message alone', () => {
    expect(stripThinkingBlocks('hellooooo')).toBe('hellooooo')
  })
})

describe('visiblePreview', () => {
  it('cuts the answer, not the scratchpad', () => {
    expect(visiblePreview(hello)).toBe('Hello! How can I assist you today?')
    expect(visiblePreview(hello, 5)).toBe('Hello')
  })

  it('returns an empty string for a missing preview', () => {
    expect(visiblePreview(null)).toBe('')
  })
})
