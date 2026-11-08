import { describe, expect, it } from 'vitest'
import type { IamAuditEntry } from '@/services/api/iamApi'
import { toolCallSentence } from '@/utils/auditToolCall'

const labels: Record<string, string> = {
  'people.audit.someone': 'Someone',
  'people.audit.tool.unnamed': 'a tool',
  'people.audit.tool.outcome.done': 'Done',
  'people.audit.tool.outcome.failed': 'It failed',
  'people.audit.tool.outcome.refused': 'It was refused',
  'people.audit.tool.outcome.needs_approval': 'It is waiting for approval',
  'people.audit.tool.outcome.timed_out': 'It timed out',
  'people.audit.tool.outcome.other': 'Finished',
  'people.audit.tool.source.document': '{who} edited a document with {tool}. {outcome}.',
  'people.audit.tool.source.mcp': '{who} ran {tool} on a connected server. {outcome}.',
  'people.audit.tool.source.custom': '{who} called {tool}. {outcome}.',
  'people.audit.tool.source.compute': '{who} ran code with {tool}. {outcome}.',
  'people.audit.tool.source.skill': '{who} used the skill {tool}. {outcome}.',
  'people.audit.tool.source.plugin': '{who} used an app tool, {tool}. {outcome}.',
  'people.audit.tool.source.builtin': '{who} used a built-in tool, {tool}. {outcome}.',
  'people.audit.tool.source.unknown': '{who} ran {tool}. {outcome}.',
}

function translate(key: string, values?: Record<string, string | number>): string {
  const template = labels[key] ?? key
  if (!values) return template
  return template.replace(/\{(\w+)\}/g, (_, name: string) => String(values[name] ?? ''))
}

function entry(
  subject: Record<string, unknown>,
  extra: Partial<IamAuditEntry> = {}
): IamAuditEntry {
  return {
    id: 1,
    actorId: 7,
    actorName: 'Ben Weber',
    action: 'tool.executed',
    kind: 'tool',
    resourceId: String(subject.tool ?? ''),
    resourceName: null,
    subjectName: null,
    subject,
    ip: '',
    created: 1,
    ...extra,
  }
}

describe('toolCallSentence', () => {
  it('ignores rows that are not tool calls', () => {
    expect(
      toolCallSentence(
        entry({ tool: 'set_cells', source: 'document' }, { action: 'share.grant' }),
        translate
      )
    ).toBeNull()
  })

  it('says a document edit edited a document', () => {
    const row = toolCallSentence(
      entry({
        tool: 'set_cells',
        source: 'document',
        kind: 'write',
        outcome: 'done',
        durationMs: 4,
        requestId: 'chat:0',
        arguments: { cells: [{ value: 'secret-cell' }] },
        result: 'secret-cell',
      }),
      translate
    )
    expect(row?.sentence).toBe('Ben Weber edited a document with set_cells. Done.')
    expect(row?.sentence).not.toContain('secret-cell')
    expect(row?.approvalId).toBeNull()
  })

  it('names the tool on a connected server, without the server number', () => {
    const row = toolCallSentence(
      entry({
        tool: 'mcp__1__create_issue',
        source: 'mcp',
        kind: 'write',
        outcome: 'done',
      }),
      translate
    )
    expect(row?.sentence).toBe('Ben Weber ran create_issue on a connected server. Done.')
    expect(row?.sentence).not.toContain('mcp__1__')
  })

  it('strips the colon form of a connected-server name', () => {
    const row = toolCallSentence(
      entry({ tool: 'mcp:1:create_issue', source: 'mcp', kind: 'write', outcome: 'done' }),
      translate
    )
    expect(row?.sentence).toBe('Ben Weber ran create_issue on a connected server. Done.')
    expect(row?.sentence).not.toContain('mcp:1:')
  })

  it('uses one sentence per source', () => {
    const cases: Array<[string, string]> = [
      ['custom', 'Ben Weber called create_ticket. Done.'],
      ['compute', 'Ben Weber ran code with code_run. Done.'],
      ['skill', 'Ben Weber used the skill summarize. Done.'],
      ['plugin', 'Ben Weber used an app tool, memory_add. Done.'],
      ['builtin', 'Ben Weber used a built-in tool, web_search. Done.'],
      ['other', 'Ben Weber ran lookup. Done.'],
    ]
    for (const [source, sentence] of cases) {
      const tool =
        source === 'custom'
          ? 'create_ticket'
          : source === 'compute'
            ? 'code_run'
            : source === 'skill'
              ? 'summarize'
              : source === 'plugin'
                ? 'memory_add'
                : source === 'builtin'
                  ? 'web_search'
                  : 'lookup'
      expect(toolCallSentence(entry({ tool, source, outcome: 'done' }), translate)?.sentence).toBe(
        sentence
      )
    }
  })

  it('keeps a failed outcome and a granted approval id', () => {
    const row = toolCallSentence(
      entry({
        tool: 'create_issue',
        source: 'mcp',
        outcome: 'failed',
        approvalId: 812,
      }),
      translate
    )
    expect(row?.sentence).toBe('Ben Weber ran create_issue on a connected server. It failed.')
    expect(row?.approvalId).toBe(812)
  })

  it('prefers the display name and falls back when the person is missing', () => {
    const row = toolCallSentence(
      entry(
        { tool: 'set_cells', source: 'document', outcome: 'done' },
        { actorName: '  ', resourceName: 'Q3 report.xlsx' }
      ),
      translate
    )
    expect(row?.sentence).toBe('Someone edited a document with Q3 report.xlsx. Done.')
  })
})
