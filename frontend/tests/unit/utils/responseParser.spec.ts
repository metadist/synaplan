import { describe, it, expect } from 'vitest'
import { parseAIResponse } from '@/utils/responseParser'

describe('parseAIResponse', () => {
  describe('markdown tables with links (issue #953)', () => {
    it('keeps a markdown table with links inline instead of extracting URL cards', () => {
      const content = `Here are the providers:

| Provider | Website |
| --- | --- |
| OpenAI | https://openai.com |
| Anthropic | https://anthropic.com |
| Google | https://google.com |
| Meta | https://ai.meta.com |

Let me know if you need more.`

      const result = parseAIResponse(content)

      expect(result.parts.some((p) => p.type === 'links')).toBe(false)
      expect(result.hasLinks).toBe(false)
      const textPart = result.parts.find((p) => p.type === 'text')
      expect(textPart?.content).toContain('| Provider | Website |')
      expect(textPart?.content).toContain('https://openai.com')
      expect(textPart?.content).toContain('https://anthropic.com')
    })

    it('keeps a table with markdown links inline', () => {
      const content = `| Name | Link |
| --- | --- |
| One | [Example](https://example.com) |
| Two | [Docs](https://docs.example.com) |
| Three | [Blog](https://blog.example.com) |`

      const result = parseAIResponse(content)

      expect(result.parts.some((p) => p.type === 'links')).toBe(false)
      const textPart = result.parts.find((p) => p.type === 'text')
      expect(textPart?.content).toContain('| Name | Link |')
      expect(textPart?.content).toContain('[Example](https://example.com)')
    })

    it('handles alignment markers in table separator', () => {
      const content = `| Left | Center | Right |
| :--- | :---: | ---: |
| https://one.com | https://two.com | https://three.com |
| https://four.com | https://five.com | https://six.com |`

      const result = parseAIResponse(content)

      expect(result.parts.some((p) => p.type === 'links')).toBe(false)
    })
  })

  describe('clustered link list extraction (existing behavior preserved)', () => {
    it('still extracts a compact markdown link list (web search style)', () => {
      const content = `- [Example](https://example.com): The first source
- [Docs](https://docs.example.com): The documentation
- [Blog](https://blog.example.com): The blog post
- [API](https://api.example.com): The API reference`

      const result = parseAIResponse(content)

      expect(result.parts.some((p) => p.type === 'links')).toBe(true)
      expect(result.hasLinks).toBe(true)
    })

    it('does not extract links scattered across prose', () => {
      const content =
        'This is a long paragraph of prose. '.repeat(20) +
        ' See https://example.com for details. ' +
        'More prose here. '.repeat(20) +
        ' Also https://docs.example.com is useful. ' +
        'Even more prose. '.repeat(20) +
        ' Finally https://blog.example.com closes it.'

      const result = parseAIResponse(content)

      expect(result.parts.some((p) => p.type === 'links')).toBe(false)
    })
  })
})
