import { describe, expect, it } from 'vitest'
import {
  modelDeletionMessage,
  type ModelDeletionResult,
} from '@/components/config/modelDeletionCopy'

const messages: Record<string, string> = {
  'config.aiModels.admin.modelDeleted': 'Model deleted',
  'config.aiModels.admin.deletedMoved': 'Removed. {job} now uses {model}.',
  'config.aiModels.admin.deletedMovedMany':
    'Removed. The jobs that used this model now use another available one.',
  'config.aiModels.admin.deletedCleared':
    'Removed. {job} has no model until you pick one on this page. Nothing else was changed.',
  'config.aiModels.admin.deletedMovedAndCleared':
    'Removed. Some jobs now use another model. {job} has no model until you pick one on this page.',
  'config.aiModels.admin.someJobs': 'Some jobs',
  'people.policies.capability.CHAT': 'Chat',
  'people.policies.capability.VECTORIZE': 'Search',
}

const t = (key: string, params?: Record<string, string>) => {
  let text = messages[key] ?? key
  for (const [name, value] of Object.entries(params ?? {})) {
    text = text.replaceAll(`{${name}}`, value)
  }
  return text
}
const te = (key: string) => key in messages

describe('modelDeletionMessage', () => {
  it('names the one job that moved', () => {
    const result: ModelDeletionResult = {
      moved: [{ setting: 'CHAT', ownerId: 0, modelId: 9, modelName: 'Next chat' }],
      cleared: [],
    }
    expect(modelDeletionMessage(result, t, te)).toBe('Removed. Chat now uses Next chat.')
  })

  it('does not list every job when several moved', () => {
    const result: ModelDeletionResult = {
      moved: [
        { setting: 'CHAT', ownerId: 0, modelId: 9, modelName: 'Next chat' },
        { setting: 'SORT', ownerId: 0, modelId: 9, modelName: 'Next chat' },
      ],
      cleared: [],
    }
    expect(modelDeletionMessage(result, t, te)).toBe(
      messages['config.aiModels.admin.deletedMovedMany']
    )
  })

  it('says search was cleared and nothing else changed', () => {
    const result: ModelDeletionResult = {
      moved: [],
      cleared: [{ setting: 'VECTORIZE', ownerId: 0 }],
    }
    expect(modelDeletionMessage(result, t, te)).toBe(
      'Removed. Search has no model until you pick one on this page. Nothing else was changed.'
    )
  })

  it('names one cleared job beside the moves', () => {
    const result: ModelDeletionResult = {
      moved: [{ setting: 'CHAT', ownerId: 0, modelId: 9, modelName: 'Next chat' }],
      cleared: [{ setting: 'VECTORIZE', ownerId: 0 }],
    }
    expect(modelDeletionMessage(result, t, te)).toBe(
      'Removed. Some jobs now use another model. Search has no model until you pick one on this page.'
    )
  })

  it('keeps the plain sentence when nothing was a default', () => {
    expect(modelDeletionMessage({ moved: [], cleared: [] }, t, te)).toBe('Model deleted')
  })
})
