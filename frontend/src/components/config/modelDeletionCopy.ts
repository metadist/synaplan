export interface ModelDefaultMove {
  setting: string
  ownerId: number
  modelId: number
  modelName: string
}

export interface ModelDefaultClear {
  setting: string
  ownerId: number
}

export interface ModelDeletionResult {
  moved: ModelDefaultMove[]
  cleared: ModelDefaultClear[]
}

type Translate = (key: string, params?: Record<string, string>) => string
type TranslateExists = (key: string) => boolean

/**
 * One sentence for the catalog after a model row is removed.
 * A single moved or cleared job is named. Several cleared jobs stay "Some jobs".
 */
export function modelDeletionMessage(
  result: ModelDeletionResult,
  t: Translate,
  te: TranslateExists
): string {
  const moved = result.moved
  const cleared = result.cleared
  const job = (setting: string): string => {
    const key = `people.policies.capability.${setting}`
    return te(key) ? t(key) : t('config.aiModels.admin.someJobs')
  }

  if (moved.length > 0 && cleared.length > 0) {
    return t('config.aiModels.admin.deletedMovedAndCleared', {
      job: cleared.length === 1 ? job(cleared[0].setting) : t('config.aiModels.admin.someJobs'),
    })
  }
  if (moved.length === 1) {
    return t('config.aiModels.admin.deletedMoved', {
      job: job(moved[0].setting),
      model: moved[0].modelName,
    })
  }
  if (moved.length > 1) {
    return t('config.aiModels.admin.deletedMovedMany')
  }
  if (cleared.length === 1) {
    return t('config.aiModels.admin.deletedCleared', { job: job(cleared[0].setting) })
  }
  if (cleared.length > 1) {
    return t('config.aiModels.admin.deletedCleared', { job: t('config.aiModels.admin.someJobs') })
  }
  return t('config.aiModels.admin.modelDeleted')
}
