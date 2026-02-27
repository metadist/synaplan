import { describe, expect, it } from 'vitest'
import { parseJsonMetadata, parseWidgetSetupOutput } from '@/utils/widgetSetupOutput'

describe('widgetSetupOutput parser', () => {
  it('parses valid JSON setup payload', () => {
    const raw = `\`\`\`json
{
  "promptText": "You are a support assistant.",
  "rules": {
    "locationLinkRequired": true,
    "conciseReplies": true
  },
  "scenarios": [
    { "key": "time", "title": "Time", "expectedBehavior": "Give time." }
  ],
  "knowledgeMapping": {
    "pricing.pdf": ["pricing", "discounts"]
  }
}
\`\`\``

    const parsed = parseWidgetSetupOutput(raw)
    expect(parsed.source).toBe('json')
    expect(parsed.promptText).toContain('support assistant')
    expect(parsed.rules.locationLinkRequired).toBe(true)
    expect(parsed.rules.conciseReplies).toBe(true)
    expect(parsed.knowledgeMapping['pricing.pdf']).toEqual(['pricing', 'discounts'])
  })

  it('falls back to text mode when JSON is invalid', () => {
    const raw = 'Please answer concise and add call-to-action at the end.'
    const parsed = parseWidgetSetupOutput(raw)

    expect(parsed.source).toBe('fallback')
    expect(parsed.promptText).toContain('Please answer concise')
    expect(parsed.rules.conciseReplies).toBe(true)
    expect(parsed.rules.ctaRequired).toBe(true)
  })

  it('parses metadata JSON safely', () => {
    const fallback = { test: true }
    expect(parseJsonMetadata('{"test":false}', fallback)).toEqual({ test: false })
    expect(parseJsonMetadata('invalid-json', fallback)).toEqual(fallback)
  })
})
