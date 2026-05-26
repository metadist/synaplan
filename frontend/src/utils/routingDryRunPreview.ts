import type { Capability } from '@/types/ai-models'
import type { AIModel } from '@/types/ai-models'

export type UseCaseId =
  | 'text_chat'
  | 'media_generation'
  | 'file_generation'
  | 'file_analytics'
  | 'comm_send_email'
  | 'comm_receive_email'

export interface DryRunStepPreview {
  stepKey: string
  labelKey: string
  capability: Capability
}

export const ROUTING_EXCLUDED_CANONICAL_TOPICS = ['general', 'mediamaker'] as const

export function isRoutingExcludedCanonicalTopic(topic: string): boolean {
  return (ROUTING_EXCLUDED_CANONICAL_TOPICS as readonly string[]).includes(topic.toLowerCase())
}

/** Map legacy handler keys to the editable use case row in the admin UI. */
export function canonicalToEditableRoutingTopic(topic: string): string {
  const t = topic.toLowerCase()
  if (t === 'general') return 'general-chat'
  if (t === 'mediamaker') return 'image-generation'
  return topic
}

const TOPIC_TO_USE_CASE: Record<string, UseCaseId> = {
  general: 'text_chat',
  chat: 'text_chat',
  'general-chat': 'text_chat',
  coding: 'text_chat',
  mediamaker: 'media_generation',
  text2pic: 'media_generation',
  text2vid: 'media_generation',
  text2sound: 'media_generation',
  'image-generation': 'media_generation',
  'video-generation': 'media_generation',
  'audio-generation': 'media_generation',
  'tools:pic': 'media_generation',
  'tools:vid': 'media_generation',
  'tools:tts': 'media_generation',
  officemaker: 'file_generation',
  text2doc: 'file_generation',
  analyzefile: 'file_analytics',
  analyze: 'file_analytics',
  pic2text: 'file_analytics',
  docsummary: 'file_analytics',
  'tools:filesort': 'file_analytics',
  email: 'comm_send_email',
  mail: 'comm_send_email',
  'send-email': 'comm_send_email',
  'mail-handler': 'comm_receive_email',
  'inbound-email': 'comm_receive_email',
}

const USE_CASE_LABEL_KEYS: Record<UseCaseId, string> = {
  text_chat: 'config.routing.useCases.textChat',
  media_generation: 'config.routing.useCases.mediaGeneration',
  file_generation: 'config.routing.useCases.fileGeneration',
  file_analytics: 'config.routing.useCases.fileAnalytics',
  comm_send_email: 'config.routing.useCases.commSendEmail',
  comm_receive_email: 'config.routing.useCases.commReceiveEmail',
}

const USE_CASE_DEFAULT_STEPS: Record<UseCaseId, DryRunStepPreview[]> = {
  text_chat: [{ stepKey: 'chat', labelKey: 'config.routing.steps.chat', capability: 'CHAT' }],
  media_generation: [
    {
      stepKey: 'generate',
      labelKey: 'config.routing.steps.mediaGenerate',
      capability: 'TEXT2PIC',
    },
  ],
  file_generation: [
    {
      stepKey: 'create',
      labelKey: 'config.routing.steps.fileCreate',
      capability: 'CHAT',
    },
  ],
  file_analytics: [
    { stepKey: 'extract', labelKey: 'config.routing.steps.fileExtract', capability: 'ANALYZE' },
    { stepKey: 'answer', labelKey: 'config.routing.steps.chat', capability: 'CHAT' },
  ],
  comm_send_email: [
    { stepKey: 'draft', labelKey: 'config.routing.steps.draftEmail', capability: 'CHAT' },
    { stepKey: 'send', labelKey: 'config.routing.steps.sendEmail', capability: 'CHAT' },
  ],
  comm_receive_email: [
    { stepKey: 'fetch', labelKey: 'config.routing.steps.fetchEmail', capability: 'CHAT' },
    { stepKey: 'analyse', labelKey: 'config.routing.steps.fileExtract', capability: 'ANALYZE' },
  ],
}


export function topicToUseCaseId(topic: string, aliasTarget?: string | null): UseCaseId {
  const canonical = aliasTarget && aliasTarget !== topic ? aliasTarget : topic
  return TOPIC_TO_USE_CASE[topic] ?? TOPIC_TO_USE_CASE[canonical] ?? 'text_chat'
}

export function useCaseLabelKey(useCaseId: UseCaseId): string {
  return USE_CASE_LABEL_KEYS[useCaseId]
}

export function buildDryRunPreview(
  query: string,
  primaryTopic: string,
  aliasTarget?: string | null,
  classification?: {
    webSearch?: boolean
    mediaType?: 'image' | 'video' | 'audio' | string | null
    intent?: string
    steps?: Array<{ id: string; capability: Capability; label_key?: string }>
  }
): { useCaseId: UseCaseId; steps: DryRunStepPreview[]; isCompound: boolean } {
  const useCaseId = topicToUseCaseId(primaryTopic, aliasTarget)

  const sorterStepsPlan = buildSorterStepsPreview(classification?.steps)
  if (sorterStepsPlan) {
    return sorterStepsPlan
  }

  const classificationPlan = buildClassificationCompoundPreview(useCaseId, classification)
  if (classificationPlan) {
    return classificationPlan
  }

  const steps = USE_CASE_DEFAULT_STEPS[useCaseId].map((s) => ({ ...s }))
  if (useCaseId === 'media_generation') {
    const mediaType = classification?.mediaType?.toLowerCase() ?? ''
    if (mediaType === 'video') {
      steps[0] = {
        ...steps[0],
        capability: 'TEXT2VID',
        labelKey: 'config.routing.steps.videoGenerate',
      }
    } else if (mediaType === 'audio') {
      steps[0] = {
        ...steps[0],
        capability: 'TEXT2SOUND',
        labelKey: 'config.routing.steps.readAloud',
      }
    }
  }

  return { useCaseId, steps, isCompound: false }
}

function buildSorterStepsPreview(
  steps?: Array<{ id: string; capability: Capability; label_key?: string }>
): { useCaseId: UseCaseId; steps: DryRunStepPreview[]; isCompound: boolean } | null {
  if (!steps?.length) {
    return null
  }

  const mapped = steps.map((step) => ({
    stepKey: step.id,
    labelKey: step.label_key ?? defaultLabelKeyForCapability(step.capability),
    capability: step.capability,
  }))

  return {
    useCaseId: mapped.length > 1 ? 'text_chat' : topicToUseCaseId('general-chat'),
    steps: mapped,
    isCompound: mapped.length > 1,
  }
}

function defaultLabelKeyForCapability(capability: Capability): string {
  const match = Object.values(USE_CASE_DEFAULT_STEPS)
    .flat()
    .find((step) => step.capability === capability)
  return match?.labelKey ?? 'config.routing.steps.chat'
}

function buildClassificationCompoundPreview(
  useCaseId: UseCaseId,
  classification?: {
    webSearch?: boolean
    mediaType?: 'image' | 'video' | 'audio' | string | null
    intent?: string
  }
): { useCaseId: UseCaseId; steps: DryRunStepPreview[]; isCompound: boolean } | null {
  if (!classification?.webSearch) {
    return null
  }

  const intent = classification.intent ?? 'chat'
  const mediaType = classification.mediaType ?? null
  if (!mediaType) {
    return null
  }

  if (intent !== 'image_generation' && useCaseId !== 'media_generation') {
    return null
  }

  const mediaStep =
    mediaType === 'video'
      ? {
          stepKey: 'generate',
          labelKey: 'config.routing.steps.videoGenerate',
          capability: 'TEXT2VID' as Capability,
        }
      : mediaType === 'audio'
        ? {
            stepKey: 'generate',
            labelKey: 'config.routing.steps.readAloud',
            capability: 'TEXT2SOUND' as Capability,
          }
        : {
            stepKey: 'generate',
            labelKey: 'config.routing.steps.mediaGenerate',
            capability: 'TEXT2PIC' as Capability,
          }

  return {
    useCaseId: 'text_chat',
    isCompound: true,
    steps: [
      { stepKey: 'answer', labelKey: 'config.routing.steps.chat', capability: 'CHAT' },
      mediaStep,
    ],
  }
}

export function resolveModelLabelForCapability(
  capability: Capability,
  defaults: Partial<Record<Capability, number | null>>,
  modelsByCapability: Partial<Record<Capability, AIModel[]>>
): string | null {
  const modelId = defaults[capability]
  if (!modelId) return null
  const models = modelsByCapability[capability] ?? []
  const match = models.find((m) => m.id === modelId)
  return match?.name ?? null
}
