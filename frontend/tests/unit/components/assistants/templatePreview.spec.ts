import { describe, expect, it } from 'vitest'
import { templatePreview } from '@/components/assistants/templatePreview'
import type { AgentTemplate } from '@/services/api/agentTemplatesApi'

function translate(key: string, named?: Record<string, unknown>, plural?: number): string {
  if (
    key.startsWith('templates.') &&
    !key.startsWith('templates.preview') &&
    !key.startsWith('templates.unavailable')
  ) {
    return key
  }
  const nested = [named?.assistants, named?.prompts, named?.tasks]
    .filter((part) => part != null)
    .join('+')
  return `${key}|${plural ?? ''}|${named?.count ?? ''}${nested === '' ? '' : `|${nested}`}`
}

function template(overrides: Partial<AgentTemplate> = {}): AgentTemplate {
  return {
    id: 'offer-writer',
    version: '2026.11.1',
    titleKey: 'templates.offerWriter.title',
    summaryKey: 'templates.offerWriter.summary',
    category: 'sales',
    tryIt: [],
    requires: [
      { kind: 'capability', value: 'chat', optional: false, state: 'ready' },
      {
        kind: 'knowledgeFolder',
        hintKey: 'templates.offerWriter.needsPriceList',
        optional: false,
        state: 'needsYou',
      },
    ],
    usedBy: 0,
    updateAvailable: false,
    creates: { assistants: 1, prompts: 0, tasks: 0 },
    ...overrides,
  }
}

describe('templatePreview', () => {
  it('says what is created, the default chat model, and what is still missing', () => {
    const text = templatePreview(template(), translate)

    expect(text).toContain('templates.previewAssistants|1|1')
    expect(text).toContain('templates.previewModelChat')
    expect(text).toContain('templates.offerWriter.needsPriceList')
    expect(text).not.toContain('templates.previewNothingElse')
  })

  it('counts instructions when the bundle has them', () => {
    const text = templatePreview(
      template({ creates: { assistants: 1, prompts: 2, tasks: 0 } }),
      translate
    )

    expect(text).toContain('templates.previewCreatesBoth')
    expect(text).toContain('templates.previewPrompts|2|2')
  })

  it('does not claim a new assistant or a chat model that this server does not have', () => {
    const text = templatePreview(
      template({
        installedAgentId: 12,
        requires: [{ kind: 'capability', value: 'chat', optional: false, state: 'unavailable' }],
      }),
      translate
    )

    expect(text).toContain('templates.previewAlready')
    expect(text).not.toContain('templates.previewAssistants')
    expect(text).not.toContain('templates.previewModelChat')
    expect(text).toContain('templates.unavailableChat')
  })

  it('says a weekday task is created and where to turn it off', () => {
    const text = templatePreview(
      template({
        id: 'daily-briefing',
        creates: { assistants: 1, prompts: 0, tasks: 1 },
        requires: [
          { kind: 'capability', value: 'chat', optional: false, state: 'ready' },
          {
            kind: 'connection',
            hintKey: 'templates.dailyBriefing.needsMail',
            optional: true,
            state: 'needsYou',
          },
        ],
      }),
      translate
    )

    expect(text).toContain('templates.previewCreatesTask')
    expect(text).toContain('templates.previewTasks|1|1')
    expect(text).toContain('templates.previewTaskHome')
    expect(text).toContain('templates.dailyBriefing.needsMail')
    expect(text).not.toContain('templates.previewNothingElse')
  })

  it('says nothing else is needed when every requirement is ready', () => {
    const text = templatePreview(
      template({
        requires: [{ kind: 'capability', value: 'chat', optional: false, state: 'ready' }],
      }),
      translate
    )

    expect(text).toContain('templates.previewNothingElse')
  })
})
