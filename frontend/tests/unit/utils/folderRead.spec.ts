import { describe, expect, it } from 'vitest'
import { folderReadsFromSnapshot, parseFolderRead, skipCounts } from '@/utils/folderRead'

const wire = {
  outcome: 'partial',
  app: 'nextcloud',
  label: 'Nextcloud',
  host: 'files.example.org',
  folder: 'Invoices/2026',
  read: 12,
  total: 14,
  files: ['inv-0101.pdf', '', 3],
  skipped: [{ name: 'scan.tiff', reason: 'too_large' }, { name: '' }, 'x'],
  stopped: false,
  open_url: 'https://files.example.org/index.php/apps/files/?dir=%2FInvoices%2F2026',
}

describe('parseFolderRead', () => {
  it('keeps the summary the backend sent and drops malformed entries', () => {
    expect(parseFolderRead(wire)).toEqual({
      outcome: 'partial',
      app: 'nextcloud',
      label: 'Nextcloud',
      host: 'files.example.org',
      folder: 'Invoices/2026',
      read: 12,
      total: 14,
      files: ['inv-0101.pdf'],
      skipped: [{ name: 'scan.tiff', reason: 'too_large' }],
      stopped: false,
      openUrl: 'https://files.example.org/index.php/apps/files/?dir=%2FInvoices%2F2026',
    })
  })

  it('refuses an unknown outcome and anything that is not an object', () => {
    expect(parseFolderRead({ ...wire, outcome: 'exploded' })).toBeUndefined()
    expect(parseFolderRead('read')).toBeUndefined()
    expect(parseFolderRead(null)).toBeUndefined()
  })

  it('never turns a non-http address into a link', () => {
    expect(parseFolderRead({ ...wire, open_url: 'javascript:alert(1)' })?.openUrl).toBeNull()
    expect(parseFolderRead({ ...wire, open_url: 'not a url' })?.openUrl).toBeNull()
  })

  it('never reports fewer files in total than were read', () => {
    expect(parseFolderRead({ ...wire, read: 5, total: 2 })?.total).toBe(5)
  })
})

describe('folder read helpers', () => {
  it('counts what was left out by reason', () => {
    const read = parseFolderRead({
      ...wire,
      skipped: [
        { name: 'a', reason: 'too_large' },
        { name: 'b', reason: 'over_limit' },
        { name: 'c', reason: 'over_limit' },
        { name: 'd', reason: 'unreadable' },
      ],
    })
    expect(read && skipCounts(read)).toEqual({ tooLarge: 1, overLimit: 2, other: 1 })
  })

  it('finds the folder reads in a task run snapshot', () => {
    const reads = folderReadsFromSnapshot({
      cards: [
        { capability: 'summarize', folder_read: wire },
        { capability: 'folder_fetch', folder_read: wire },
        { capability: 'folder_fetch' },
      ],
    })
    expect(reads).toHaveLength(1)
    expect(reads[0].folder).toBe('Invoices/2026')
    expect(folderReadsFromSnapshot(null)).toEqual([])
  })
})
