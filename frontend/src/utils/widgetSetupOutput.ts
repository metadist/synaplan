import { z } from 'zod'
import {
  DEFAULT_WIDGET_BEHAVIOR_RULES,
  normalizeWidgetBehaviorRules,
  type WidgetBehaviorRules,
} from '@/utils/widgetBehaviorRules'

const SetupRulesSchema = z
  .object({
    locationLinkRequired: z.boolean().optional(),
    locationImageLink: z.boolean().optional(),
    conciseReplies: z.boolean().optional(),
    ctaRequired: z.boolean().optional(),
  })
  .optional()

const SetupScenarioSchema = z.object({
  key: z.string(),
  title: z.string(),
  expectedBehavior: z.string(),
})

const SetupOutputSchema = z.object({
  promptText: z.string().min(1),
  rules: SetupRulesSchema,
  scenarios: z.array(SetupScenarioSchema).optional(),
  knowledgeMapping: z.record(z.string(), z.array(z.string())).optional(),
})

export interface WidgetSetupParsedOutput {
  promptText: string
  rules: WidgetBehaviorRules
  scenarios: Array<{ key: string; title: string; expectedBehavior: string }>
  knowledgeMapping: Record<string, string[]>
  source: 'json' | 'fallback'
}

const defaultScenarios = (): WidgetSetupParsedOutput['scenarios'] => [
  {
    key: 'time',
    title: 'Time question',
    expectedBehavior: 'Respond with the current time in a clear format.',
  },
  {
    key: 'location',
    title: 'Location question',
    expectedBehavior: 'Provide location details and include relevant links when needed.',
  },
  {
    key: 'general',
    title: 'General question',
    expectedBehavior: 'Provide a helpful and relevant response.',
  },
]

const extractJsonCandidate = (raw: string): string | null => {
  const fenced = raw.match(/```json\s*([\s\S]*?)```/i)
  if (fenced && fenced[1]) {
    return fenced[1].trim()
  }

  const firstBrace = raw.indexOf('{')
  const lastBrace = raw.lastIndexOf('}')
  if (firstBrace >= 0 && lastBrace > firstBrace) {
    return raw.slice(firstBrace, lastBrace + 1).trim()
  }

  return null
}

const inferRulesFromText = (raw: string): WidgetBehaviorRules => {
  const text = raw.toLowerCase()
  return normalizeWidgetBehaviorRules({
    locationLinkRequired: text.includes('location') && text.includes('link'),
    locationImageLink: text.includes('location') && text.includes('image') && text.includes('link'),
    conciseReplies:
      text.includes('short') ||
      text.includes('concise') ||
      text.includes('kurz') ||
      text.includes('konkret'),
    ctaRequired:
      text.includes('call-to-action') ||
      text.includes('call to action') ||
      text.includes('cta') ||
      text.includes('handlungsaufforderung'),
  })
}

const cleanupPromptText = (raw: string): string => {
  return raw
    .replace(/```json[\s\S]*?```/gi, '')
    .replace(/```[\s\S]*?```/g, '')
    .trim()
}

export const parseWidgetSetupOutput = (raw: string): WidgetSetupParsedOutput => {
  const jsonCandidate = extractJsonCandidate(raw)
  if (jsonCandidate) {
    try {
      const parsedJson = JSON.parse(jsonCandidate)
      const validated = SetupOutputSchema.parse(parsedJson)
      return {
        promptText: validated.promptText.trim(),
        rules: normalizeWidgetBehaviorRules(validated.rules ?? DEFAULT_WIDGET_BEHAVIOR_RULES),
        scenarios: validated.scenarios ?? defaultScenarios(),
        knowledgeMapping: (validated.knowledgeMapping ?? {}) as Record<string, string[]>,
        source: 'json',
      }
    } catch {
      // Continue with fallback parser
    }
  }

  return {
    promptText: cleanupPromptText(raw),
    rules: inferRulesFromText(raw),
    scenarios: defaultScenarios(),
    knowledgeMapping: {},
    source: 'fallback',
  }
}

export const parseJsonMetadata = <T>(value: unknown, fallback: T): T => {
  if (typeof value !== 'string' || value.trim().length === 0) {
    return fallback
  }
  try {
    return JSON.parse(value) as T
  } catch {
    return fallback
  }
}
