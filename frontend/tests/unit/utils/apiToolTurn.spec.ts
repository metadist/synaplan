import { describe, expect, it } from 'vitest'
import { collapseApiToolTurn, TOOL_RESULT_MARKER } from '@/utils/apiToolTurn'
import { parseContentWithThinking } from '@/utils/messageMapper'

describe('collapseApiToolTurn', () => {
  it('hides a stored tool_result bubble', () => {
    const raw = JSON.stringify({
      type: 'tool_result',
      tool_use_id: 'toolu_1',
      content: '# SKILL.md',
    })

    expect(collapseApiToolTurn(raw)).toBe(TOOL_RESULT_MARKER)
    expect(parseContentWithThinking(raw, 'user')).toEqual([
      { type: 'text', content: TOOL_RESULT_MARKER },
    ])
  })

  it('keeps the text the person typed and drops the tool block', () => {
    const raw = JSON.stringify([
      { type: 'text', text: 'Make 3 slides about Q3' },
      { type: 'tool_result', tool_use_id: 'toolu_1', content: { ok: false } },
    ])

    expect(parseContentWithThinking(raw, 'user')).toEqual([
      { type: 'text', content: 'Make 3 slides about Q3' },
    ])
  })

  it('leaves ordinary text and user-typed JSON alone', () => {
    expect(collapseApiToolTurn('Hello there')).toBe('Hello there')
    expect(parseContentWithThinking('{"foo":1}', 'user')).toEqual([
      { type: 'text', content: '{"foo":1}' },
    ])
  })

  it('does not rewrite assistant messages', () => {
    const raw = JSON.stringify({ type: 'tool_result', tool_use_id: 'toolu_1', content: 'x' })
    expect(parseContentWithThinking(raw, 'assistant')[0]?.content).toContain('tool_result')
  })
})
