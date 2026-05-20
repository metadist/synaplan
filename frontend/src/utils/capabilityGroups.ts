import type { Capability } from '@/types/ai-models'

export interface CapabilityGroup {
  id: string
  labelKey: string
  capabilities: Capability[]
}

/** User-facing groups for AI model settings (Release A). */
export const CAPABILITY_GROUPS: CapabilityGroup[] = [
  {
    id: 'chat',
    labelKey: 'config.aiModels.groups.chat',
    capabilities: ['CHAT', 'SORT', 'MEM'],
  },
  {
    id: 'imagesVideo',
    labelKey: 'config.aiModels.groups.imagesVideo',
    capabilities: ['TEXT2PIC', 'TEXT2VID', 'PIC2PIC', 'PIC2TEXT'],
  },
  {
    id: 'voice',
    labelKey: 'config.aiModels.groups.voice',
    capabilities: ['SOUND2TEXT', 'TEXT2SOUND'],
  },
  {
    id: 'documentsRead',
    labelKey: 'config.aiModels.groups.documentsRead',
    capabilities: ['ANALYZE'],
  },
  {
    id: 'routing',
    labelKey: 'config.aiModels.groups.routing',
    capabilities: ['VECTORIZE'],
  },
]

export const ALL_GROUPED_CAPABILITIES: Capability[] = CAPABILITY_GROUPS.flatMap(
  (g) => g.capabilities
)
