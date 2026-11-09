import type { IamAuditEntry } from '@/services/api/iamApi'

/**
 * One sentence for a `tool.executed` audit row.
 *
 * The row names the tool, where it came from, how it ended, and the approval
 * that already allowed it. Arguments and results are not on the row and are
 * not read here.
 */
export interface ToolCallSentence {
  sentence: string
  approvalId: number | null
}

type Translate = (key: string, values?: Record<string, string | number>) => string

const SOURCE_KEYS: Record<string, string> = {
  document: 'people.audit.tool.source.document',
  mcp: 'people.audit.tool.source.mcp',
  custom: 'people.audit.tool.source.custom',
  compute: 'people.audit.tool.source.compute',
  skill: 'people.audit.tool.source.skill',
  plugin: 'people.audit.tool.source.plugin',
  builtin: 'people.audit.tool.source.builtin',
}

const OUTCOME_KEYS = new Set(['done', 'failed', 'refused', 'needs_approval', 'timed_out'])

export function toolCallSentence(
  entry: IamAuditEntry,
  translate: Translate
): ToolCallSentence | null {
  if (entry.action !== 'tool.executed') return null
  const subject = asRecord(entry.subject)
  const tool = toolLabel(entry, subject, translate)
  const source = stringField(subject, 'source')
  const outcomeKey = outcomeKeyFor(stringField(subject, 'outcome'))
  const sentence = translate(SOURCE_KEYS[source] ?? 'people.audit.tool.source.unknown', {
    who: entry.actorName?.trim() || translate('people.audit.someone'),
    tool,
    outcome: translate(`people.audit.tool.outcome.${outcomeKey}`),
  })
  return { sentence, approvalId: approvalId(subject) }
}

function toolLabel(
  entry: IamAuditEntry,
  subject: Record<string, unknown> | null,
  translate: Translate
): string {
  const named = entry.resourceName?.trim()
  if (named) return named
  const raw = stringField(subject, 'tool') || entry.resourceId.trim()
  const mcp = /^(?:mcp__\d+__|mcp:\d+:)(.+)$/.exec(raw)
  const label = (mcp?.[1] ?? raw).trim()
  return label === '' ? translate('people.audit.tool.unnamed') : label
}

function outcomeKeyFor(outcome: string): string {
  return OUTCOME_KEYS.has(outcome) ? outcome : 'other'
}

function approvalId(subject: Record<string, unknown> | null): number | null {
  const raw = subject?.approvalId
  if (typeof raw === 'number' && Number.isInteger(raw) && raw > 0) return raw
  if (typeof raw === 'string' && /^[1-9]\d*$/.test(raw)) return Number(raw)
  return null
}

function stringField(subject: Record<string, unknown> | null, key: string): string {
  const value = subject?.[key]
  return typeof value === 'string' ? value : ''
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return null
  return value as Record<string, unknown>
}
