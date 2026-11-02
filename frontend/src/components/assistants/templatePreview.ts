import type { AgentTemplate, AgentTemplateRequirement } from '@/services/api/agentTemplatesApi'

type Translate = (key: string, named?: Record<string, unknown>, plural?: number) => string

export function requirementLabel(
  requirement: AgentTemplateRequirement,
  translate: Translate
): string {
  if (requirement.state === 'unavailable' && requirement.kind === 'watch') {
    return translate('templates.unavailableWatch')
  }
  if (requirement.state === 'unavailable' && requirement.value === 'research') {
    return translate('templates.unavailableResearch')
  }
  if (requirement.state === 'unavailable' && requirement.value === 'pic2text') {
    return translate('templates.unavailableImage')
  }
  if (requirement.state === 'unavailable') {
    return translate('templates.unavailableChat')
  }
  if (requirement.hintKey) return translate(requirement.hintKey)
  if (requirement.kind === 'connection') return translate('templates.connectMail')
  if (requirement.kind === 'knowledgeFolder') return translate('templates.chooseFolder')
  return translate('templates.previewNotReady')
}

/**
 * Plain-words preview of a built-in template: what it creates, which default
 * model it uses, and what is still missing. Never names a model.
 */
export function templatePreview(template: AgentTemplate, translate: Translate): string {
  const assistants = template.creates?.assistants ?? 1
  const prompts = template.creates?.prompts ?? 0
  const tasks = template.creates?.tasks ?? 0
  const created =
    template.installedAgentId != null
      ? translate('templates.previewAlready')
      : prompts > 0
        ? translate(
            'templates.previewCreatesBoth',
            {
              assistants: translate('templates.previewAssistants', { count: assistants }, assistants),
              prompts: translate('templates.previewPrompts', { count: prompts }, prompts),
            }
          )
        : tasks > 0
          ? translate('templates.previewCreatesTask', {
              assistants: translate('templates.previewAssistants', { count: assistants }, assistants),
              tasks: translate('templates.previewTasks', { count: tasks }, tasks),
            })
          : `${translate('templates.previewAssistants', { count: assistants }, assistants)}.`

  const parts = [created]
  if (tasks > 0) parts.push(translate('templates.previewTaskHome'))
  const model = modelSentence(template, translate)
  if (model) parts.push(model)
  parts.push(needsSentence(template, translate))
  return parts.join(' ')
}

function modelSentence(template: AgentTemplate, translate: Translate): string | null {
  const capabilities = (template.requires ?? []).filter((row) => row.kind === 'capability')
  const chat = capabilities.find((row) => (row.value ?? '').toLowerCase() === 'chat')
  const image = capabilities.find((row) => (row.value ?? '').toLowerCase() === 'pic2text')
  if (chat && chat.state !== 'ready') return null
  if (image && image.state !== 'ready' && !chat) return null
  if (chat?.state === 'ready' && image?.state === 'ready') return translate('templates.previewModelBoth')
  if (image?.state === 'ready' && chat?.state !== 'ready') {
    return translate('templates.previewModelImage')
  }
  return translate('templates.previewModelChat')
}

function needsSentence(template: AgentTemplate, translate: Translate): string {
  const needs = (template.requires ?? [])
    .filter((row) => row.state !== 'ready')
    .map((row) => requirementLabel(row, translate))
    .filter((line) => line !== '')
  if (needs.length === 0) return translate('templates.previewNothingElse')
  return needs.join(' ')
}
