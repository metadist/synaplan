import { looksLikeFileGenerationEnvelope } from './fileGenerationEnvelope'

export type JsonRecord = Record<string, unknown>

export interface JsonRecordList {
  collectionKey: string | null
  total: number | null
  records: JsonRecord[]
}

export interface JsonRecordView {
  title: string
  id: string | null
  source: string | null
  date: string | null
  count: number | null
  extras: Array<{ key: string; value: string }>
}

const INTERNAL_KEYS = new Set(['BTEXT', 'BFILEPATH', 'BFILETEXT'])

const LIST_KEYS = [
  'chats',
  'items',
  'results',
  'data',
  'records',
  'messages',
  'files',
  'documents',
  'users',
  'entries',
] as const

const TITLE_KEYS = ['title', 'name', 'label', 'subject', 'filename', 'path', 'query']
const DATE_KEYS = ['updated_at', 'created_at', 'updatedAt', 'createdAt', 'date', 'timestamp']
const SOURCE_KEYS = ['source', 'origin', 'provider', 'channel']
const COUNT_KEYS = ['message_count', 'messageCount', 'messages']

const KNOWN_COLLECTION_KEYS = new Set<string>(LIST_KEYS)

export interface JsonPayload {
  value: unknown
  /**
   * The source text was cut off (the backend caps a tool result at
   * `McpFetchRunner::MAX_OUTPUT_CHARS` and appends an ellipsis, and a stream
   * can be mid-flight) and the value was recovered from the complete prefix.
   */
  truncated: boolean
}

/**
 * Parse a whole-message JSON payload, recovering the complete prefix when the
 * text was cut off mid-token.
 */
export function parseJsonPayload(text: string): JsonPayload | null {
  const trimmed = text.trim()
  if (!(trimmed.startsWith('{') || trimmed.startsWith('['))) {
    return null
  }

  const direct = tryParseObject(trimmed)
  if (direct !== null) {
    return { value: direct, truncated: false }
  }

  const repaired = repairTruncatedJson(trimmed)
  if (repaired === null) {
    return null
  }
  const value = tryParseObject(repaired)
  return value === null ? null : { value, truncated: true }
}

export function parseJsonValue(text: string): unknown | null {
  return parseJsonPayload(text)?.value ?? null
}

function tryParseObject(text: string): unknown | null {
  try {
    const value: unknown = JSON.parse(text)
    return value === null || typeof value !== 'object' ? null : value
  } catch {
    return null
  }
}

/**
 * Close a JSON document that was cut off mid-token: keep everything up to the
 * last completed member and re-close the containers that were still open
 * there. Without this, a capped list result stays an unreadable raw dump.
 */
function repairTruncatedJson(text: string): string | null {
  const stack: string[] = []
  let inString = false
  let escaped = false
  let cutIndex = -1
  let cutStack: string[] = []

  for (let i = 0; i < text.length; i++) {
    const char = text[i]

    if (inString) {
      if (escaped) {
        escaped = false
      } else if (char === '\\') {
        escaped = true
      } else if (char === '"') {
        inString = false
      }
      continue
    }

    if (char === '"') {
      inString = true
    } else if (char === '{' || char === '[') {
      stack.push(char)
    } else if (char === '}' || char === ']') {
      stack.pop()
      if (stack.length > 0) {
        cutIndex = i + 1
        cutStack = [...stack]
      }
    }
  }

  if (cutIndex === -1) {
    return null
  }

  const closers = cutStack
    .reverse()
    .map((open) => (open === '{' ? '}' : ']'))
    .join('')

  return text.slice(0, cutIndex) + closers
}

export function isInternalJsonEnvelope(value: unknown): boolean {
  if (!isPlainObject(value)) {
    return false
  }
  return Object.keys(value).some((key) => INTERNAL_KEYS.has(key))
}

/**
 * True when the entire string is a JSON object or array that should be shown
 * as a structured result rather than markdown / a raw code dump.
 */
export function looksLikeJsonDocument(text: string): boolean {
  if (looksLikeFileGenerationEnvelope(text)) {
    return false
  }
  const payload = parseJsonPayload(text)
  if (payload === null || isInternalJsonEnvelope(payload.value)) {
    return false
  }
  return true
}

export function extractRecordList(value: unknown): JsonRecordList | null {
  if (isRecordArray(value)) {
    return { collectionKey: null, total: value.length, records: value }
  }
  if (!isPlainObject(value)) {
    return null
  }
  for (const key of LIST_KEYS) {
    const arr = value[key]
    if (isRecordArray(arr) || (Array.isArray(arr) && arr.length === 0)) {
      const total = typeof value.total === 'number' ? value.total : arr.length
      return { collectionKey: key, total, records: arr as JsonRecord[] }
    }
  }
  return null
}

export function isKnownCollectionKey(key: string | null): boolean {
  return key !== null && KNOWN_COLLECTION_KEYS.has(key)
}

export function presentRecord(record: JsonRecord, locale: string): JsonRecordView {
  let title: string | null = null
  let titleKey: string | null = null
  for (const key of TITLE_KEYS) {
    if (typeof record[key] === 'string' && record[key].trim() !== '') {
      title = record[key].trim()
      titleKey = key
      break
    }
  }

  const id = record.id == null ? null : String(record.id)
  if (!title) {
    title = id ?? ''
  }

  let source: string | null = null
  for (const key of SOURCE_KEYS) {
    if (typeof record[key] === 'string' && record[key].trim() !== '') {
      source = record[key].trim()
      break
    }
  }

  let date: string | null = null
  for (const key of DATE_KEYS) {
    if (typeof record[key] === 'string' && record[key] !== '') {
      date = formatJsonScalar(record[key], locale)
      break
    }
  }

  let count: number | null = null
  for (const key of COUNT_KEYS) {
    const raw = record[key]
    if (typeof raw === 'number') {
      count = raw
      break
    }
  }

  const used = new Set<string>(
    [titleKey, 'id', ...SOURCE_KEYS, ...DATE_KEYS, ...COUNT_KEYS].filter(
      (key): key is string => typeof key === 'string'
    )
  )
  const extras: Array<{ key: string; value: string }> = []
  for (const [key, val] of Object.entries(record)) {
    if (used.has(key) || val === null || val === undefined || typeof val === 'object') {
      continue
    }
    extras.push({ key, value: formatJsonScalar(val, locale) })
    if (extras.length >= 3) {
      break
    }
  }

  return { title, id, source, date, count, extras }
}

export function formatJsonScalar(value: unknown, locale: string): string {
  if (value === null || value === undefined) {
    return ''
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false'
  }
  if (typeof value === 'number') {
    return String(value)
  }
  if (typeof value === 'string') {
    if (/^\d{4}-\d{2}-\d{2}/.test(value)) {
      const parsed = new Date(value)
      if (!Number.isNaN(parsed.getTime())) {
        return parsed.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
      }
    }
    return value
  }
  return JSON.stringify(value)
}

function isPlainObject(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isRecordArray(value: unknown): value is JsonRecord[] {
  return Array.isArray(value) && value.every(isPlainObject)
}
