import { describe, expect, it } from 'vitest'
import { fileNameFromDisposition } from '@/services/api/responseBody'

describe('fileNameFromDisposition', () => {
  it('prefers the UTF-8 name', () => {
    expect(
      fileNameFromDisposition(
        "attachment; filename=waermepumpe.docx; filename*=utf-8''w%C3%A4rmepumpe.docx"
      )
    ).toBe('wärmepumpe.docx')
  })

  it('reads a quoted and a bare plain name', () => {
    expect(fileNameFromDisposition('attachment; filename="heat \\"pumps\\".md"')).toBe(
      'heat "pumps".md'
    )
    expect(fileNameFromDisposition('attachment; filename=report.html')).toBe('report.html')
  })

  it('never returns a path', () => {
    expect(fileNameFromDisposition("attachment; filename*=utf-8''..%2F..%2Fevil.html")).toBe(
      '.._.._evil.html'
    )
  })

  it('is empty without a header or a name', () => {
    expect(fileNameFromDisposition(null)).toBe('')
    expect(fileNameFromDisposition('attachment')).toBe('')
  })
})
