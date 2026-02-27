import { describe, expect, it } from 'vitest'
import {
  buildWidgetRulesBlock,
  composePromptWithWidgetRules,
  hasWidgetRulesConflict,
  inferWidgetBehaviorRulesFromPrompt,
  parsePromptAndRulesBlock,
  parseWidgetBehaviorRulesMetadata,
  removeWidgetRulesBlock,
  type WidgetBehaviorRules,
} from '@/utils/widgetBehaviorRules'

const enabledRules: WidgetBehaviorRules = {
  version: 1,
  locationLinkRequired: true,
  locationImageLink: true,
  conciseReplies: true,
  ctaRequired: true,
}

describe('widgetBehaviorRules', () => {
  it('composes generated rule block with manual prompt', () => {
    const prompt = composePromptWithWidgetRules('Be helpful and friendly.', enabledRules)

    expect(prompt).toContain('<!-- WIDGET_RULES_START -->')
    expect(prompt).toContain('Be helpful and friendly.')
  })

  it('removes existing rule block from prompt content', () => {
    const original = `${buildWidgetRulesBlock(enabledRules)}\n\nManual section`
    const cleaned = removeWidgetRulesBlock(original)

    expect(cleaned).toBe('Manual section')
  })

  it('parses manual prompt section without generated rule block', () => {
    const original = `${buildWidgetRulesBlock(enabledRules)}\n\nManual section`
    const parsed = parsePromptAndRulesBlock(original)

    expect(parsed.hadRulesBlock).toBe(true)
    expect(parsed.manualPrompt).toBe('Manual section')
  })

  it('detects conflict when prompt rule block differs from selected rules', () => {
    const locationOnly = {
      ...enabledRules,
      conciseReplies: false,
      ctaRequired: false,
    }
    const promptWithLocationOnly = `${buildWidgetRulesBlock(locationOnly)}\n\nManual section`

    expect(hasWidgetRulesConflict(promptWithLocationOnly, enabledRules)).toBe(true)
  })

  it('parses JSON metadata string into behavior rules', () => {
    const parsed = parseWidgetBehaviorRulesMetadata(
      JSON.stringify({
        locationLinkRequired: true,
        conciseReplies: true,
      })
    )

    expect(parsed.locationLinkRequired).toBe(true)
    expect(parsed.conciseReplies).toBe(true)
    expect(parsed.locationImageLink).toBe(false)
  })

  it('infers rules from existing rule block text', () => {
    const promptWithRules = composePromptWithWidgetRules('Manual section', enabledRules)
    const inferred = inferWidgetBehaviorRulesFromPrompt(promptWithRules)

    expect(inferred.locationLinkRequired).toBe(true)
    expect(inferred.locationImageLink).toBe(true)
    expect(inferred.conciseReplies).toBe(true)
    expect(inferred.ctaRequired).toBe(true)
  })
})
