/**
 * What a `folder_fetch` step read, as the backend reports it on the task card
 * (`folder_read`). The card renders its own sentence from `outcome`, so it
 * follows the reader's language; the step's text stays English for the answer.
 */

export const FOLDER_READ_OUTCOMES = [
  'reading',
  'read',
  'partial',
  'listed',
  'refused',
  'not_found',
  'unreachable',
  'nothing',
  'busy',
  'not_connected',
] as const
export type FolderReadOutcome = (typeof FOLDER_READ_OUTCOMES)[number]

export interface FolderReadSkip {
  name: string
  reason: string
}

export interface FolderRead {
  outcome: FolderReadOutcome
  app: string
  label: string
  host: string
  folder: string
  read: number
  total: number
  files: string[]
  skipped: FolderReadSkip[]
  stopped: boolean
  openUrl: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function text(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function count(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.floor(value) : 0
}

/** Only an http(s) address may become a link; anything else is dropped. */
function safeUrl(value: unknown): string | null {
  if (typeof value !== 'string') return null
  try {
    const url = new URL(value)
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : null
  } catch {
    return null
  }
}

/** Parses the wire shape; anything that is not a folder read yields undefined. */
export function parseFolderRead(value: unknown): FolderRead | undefined {
  if (!isRecord(value)) return undefined
  const outcome = value.outcome
  if (!(FOLDER_READ_OUTCOMES as readonly unknown[]).includes(outcome)) return undefined
  const files = Array.isArray(value.files)
    ? value.files.filter((name): name is string => typeof name === 'string' && name !== '')
    : []
  const skipped = Array.isArray(value.skipped)
    ? value.skipped
        .filter(isRecord)
        .map((row) => ({ name: text(row.name), reason: text(row.reason) }))
        .filter((row) => row.name !== '')
    : []
  const read = count(value.read)
  return {
    outcome: outcome as FolderReadOutcome,
    app: text(value.app),
    label: text(value.label),
    host: text(value.host),
    folder: text(value.folder),
    read,
    total: Math.max(count(value.total), read),
    files,
    skipped,
    stopped: value.stopped === true,
    openUrl: safeUrl(value.open_url),
  }
}

/** The folder reads a task run recorded, from its saved card snapshot. */
export function folderReadsFromSnapshot(snapshot: { cards?: unknown[] } | null): FolderRead[] {
  const reads: FolderRead[] = []
  for (const card of snapshot?.cards ?? []) {
    if (!isRecord(card) || card.capability !== 'folder_fetch') continue
    const read = parseFolderRead(card.folder_read)
    if (read) reads.push(read)
  }
  return reads
}

/** How many left-out files each reason accounts for. */
export function skipCounts(read: FolderRead): {
  tooLarge: number
  overLimit: number
  other: number
} {
  let tooLarge = 0
  let overLimit = 0
  for (const row of read.skipped) {
    if (row.reason === 'too_large') tooLarge++
    else if (row.reason === 'over_limit') overLimit++
  }
  return { tooLarge, overLimit, other: read.skipped.length - tooLarge - overLimit }
}
