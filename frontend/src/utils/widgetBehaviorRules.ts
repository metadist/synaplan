export const WIDGET_RULES_BLOCK_START = '<!-- WIDGET_RULES_START -->'
export const WIDGET_RULES_BLOCK_END = '<!-- WIDGET_RULES_END -->'
export const WIDGET_BEHAVIOR_RULES_VERSION = 1

export interface WidgetBehaviorRules {
  version: number
  locationLinkRequired: boolean
  locationImageLink: boolean
  conciseReplies: boolean
  ctaRequired: boolean
}

export const DEFAULT_WIDGET_BEHAVIOR_RULES: WidgetBehaviorRules = {
  version: WIDGET_BEHAVIOR_RULES_VERSION,
  locationLinkRequired: false,
  locationImageLink: false,
  conciseReplies: false,
  ctaRequired: false,
}

export interface ParsedWidgetPromptContent {
  manualPrompt: string
  hadRulesBlock: boolean
}

export const normalizeWidgetBehaviorRules = (
  value?: Partial<WidgetBehaviorRules> | null
): WidgetBehaviorRules => {
  return {
    version: WIDGET_BEHAVIOR_RULES_VERSION,
    locationLinkRequired: value?.locationLinkRequired ?? false,
    locationImageLink: value?.locationImageLink ?? false,
    conciseReplies: value?.conciseReplies ?? false,
    ctaRequired: value?.ctaRequired ?? false,
  }
}

export const hasWidgetBehaviorRuleEnabled = (rules: WidgetBehaviorRules): boolean => {
  return (
    rules.locationLinkRequired ||
    rules.locationImageLink ||
    rules.conciseReplies ||
    rules.ctaRequired
  )
}

const escapeRegExp = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

const getRulesBlockRegex = (): RegExp =>
  new RegExp(
    `${escapeRegExp(WIDGET_RULES_BLOCK_START)}[\\s\\S]*?${escapeRegExp(WIDGET_RULES_BLOCK_END)}\\n?`,
    'g'
  )

export const removeWidgetRulesBlock = (content: string): string => {
  return content.replace(getRulesBlockRegex(), '').trim()
}

export const parsePromptAndRulesBlock = (content: string): ParsedWidgetPromptContent => {
  const hadRulesBlock = getRulesBlockRegex().test(content)
  return {
    manualPrompt: removeWidgetRulesBlock(content),
    hadRulesBlock,
  }
}

export const inferWidgetBehaviorRulesFromPrompt = (content: string): WidgetBehaviorRules => {
  const match = content.match(
    new RegExp(
      `${escapeRegExp(WIDGET_RULES_BLOCK_START)}([\\s\\S]*?)${escapeRegExp(WIDGET_RULES_BLOCK_END)}`
    )
  )

  if (!match) {
    return normalizeWidgetBehaviorRules()
  }

  const blockContent = match[1].toLowerCase()
  return normalizeWidgetBehaviorRules({
    locationLinkRequired: blockContent.includes('location') && blockContent.includes('link'),
    locationImageLink:
      blockContent.includes('location') &&
      blockContent.includes('image') &&
      blockContent.includes('link'),
    conciseReplies: blockContent.includes('concise') || blockContent.includes('concrete'),
    ctaRequired:
      blockContent.includes('call to action') ||
      blockContent.includes('cta') ||
      blockContent.includes('end every response'),
  })
}

export const buildWidgetRulesBlock = (rules: WidgetBehaviorRules): string => {
  if (!hasWidgetBehaviorRuleEnabled(rules)) {
    return ''
  }

  const lines = [
    'Widget behavior rules:',
    rules.locationLinkRequired
      ? '- For location-related questions, always include a direct location link.'
      : null,
    rules.locationImageLink
      ? '- For location-related questions, include an image link when possible.'
      : null,
    rules.conciseReplies ? '- Keep responses concise, concrete, and focused.' : null,
    rules.ctaRequired ? '- End every response with a clear call to action.' : null,
  ].filter((line): line is string => Boolean(line))

  return `${WIDGET_RULES_BLOCK_START}\n${lines.join('\n')}\n${WIDGET_RULES_BLOCK_END}`
}

export const composePromptWithWidgetRules = (
  manualPrompt: string,
  rules: WidgetBehaviorRules
): string => {
  const basePrompt = removeWidgetRulesBlock(manualPrompt).trim()
  const generatedRulesBlock = buildWidgetRulesBlock(rules)

  if (!generatedRulesBlock) {
    return basePrompt
  }

  if (!basePrompt) {
    return generatedRulesBlock
  }

  return `${generatedRulesBlock}\n\n${basePrompt}`
}

export const hasWidgetRulesConflict = (
  promptContent: string,
  rules: WidgetBehaviorRules
): boolean => {
  if (
    !promptContent.includes(WIDGET_RULES_BLOCK_START) ||
    !promptContent.includes(WIDGET_RULES_BLOCK_END)
  ) {
    return false
  }

  const generatedRulesBlock = buildWidgetRulesBlock(rules)
  if (!generatedRulesBlock) {
    return true
  }

  const match = promptContent.match(
    new RegExp(
      `${escapeRegExp(WIDGET_RULES_BLOCK_START)}[\\s\\S]*?${escapeRegExp(WIDGET_RULES_BLOCK_END)}`
    )
  )

  return match ? match[0].trim() !== generatedRulesBlock.trim() : false
}

export const parseWidgetBehaviorRulesMetadata = (value: unknown): WidgetBehaviorRules => {
  if (typeof value === 'string' && value.length > 0) {
    try {
      const parsed = JSON.parse(value) as Partial<WidgetBehaviorRules>
      return normalizeWidgetBehaviorRules(parsed)
    } catch {
      return normalizeWidgetBehaviorRules()
    }
  }

  if (value && typeof value === 'object') {
    return normalizeWidgetBehaviorRules(value as Partial<WidgetBehaviorRules>)
  }

  return normalizeWidgetBehaviorRules()
}
