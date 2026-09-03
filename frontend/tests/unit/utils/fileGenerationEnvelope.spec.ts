import { describe, expect, it } from 'vitest'
import { looksLikeFileGenerationEnvelope } from '@/utils/fileGenerationEnvelope'

describe('looksLikeFileGenerationEnvelope', () => {
  it.each([
    '{"BFILEPATH":"report.docx","BFILETEXT":"content"}',
    '```json\n{"BFILEPATH":"report.docx","BFILETEXT":"content"}\n```',
    'Here is your presentation: {"BFILEPATH":"slides.pptx","BFILETEXT":"content"}',
    'Here is your presentation: {"BFILEPATH":',
    '{"BFILEPATH":"report.docx","BFILETEXT":"content","BEXPORT":"pdf"}',
  ])('detects file generation content in %s', (content) => {
    expect(looksLikeFileGenerationEnvelope(content)).toBe(true)
  })

  it.each([
    'Regular assistant reply',
    'The BFILEPATH field is used internally.',
    '"BFILEPATH":"report.docx"',
    '{"BTEXT":"regular JSON reply"}',
  ])('does not hide regular content in %s', (content) => {
    expect(looksLikeFileGenerationEnvelope(content)).toBe(false)
  })
})
