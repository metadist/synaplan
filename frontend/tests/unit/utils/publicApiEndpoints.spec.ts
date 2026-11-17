import { describe, expect, it } from 'vitest'

import { publicApiEndpoints, publicApiOrigin } from '@/utils/publicApiEndpoints'

describe('publicApiEndpoints', () => {
  it('points local Vite at the API port', () => {
    expect(publicApiOrigin('http://localhost:5173')).toBe('http://localhost:8000')
    expect(publicApiEndpoints('http://localhost:5173')).toEqual([
      { id: 'openai', url: 'http://localhost:8000/v1' },
      { id: 'chat', url: 'http://localhost:8000/v1/chat/completions' },
      { id: 'mcp', url: 'http://localhost:8000/mcp' },
    ])
  })

  it('keeps a production origin', () => {
    expect(publicApiEndpoints('https://web.synaplan.com')).toEqual([
      { id: 'openai', url: 'https://web.synaplan.com/v1' },
      { id: 'chat', url: 'https://web.synaplan.com/v1/chat/completions' },
      { id: 'mcp', url: 'https://web.synaplan.com/mcp' },
    ])
  })
})
