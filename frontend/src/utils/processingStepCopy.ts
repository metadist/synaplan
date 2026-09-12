/**
 * Turns a timeline step into user-facing words.
 *
 * Kept apart from the Vue component so the phrasing rules (which intent hint
 * wins, when the model name is shown, past vs present tense) are plain
 * functions with unit tests. `t` is vue-i18n's translator; every string comes
 * from the `processing.*` / `message.*` namespaces.
 */
import type { TimelineModel, TimelineStep } from '@/utils/processingTimeline'

export type Translate = (key: string, params?: Record<string, unknown>) => string

export interface StepCopy {
  title: string
  detail?: string
  /** Short badge next to the title: model name, result count, … */
  chip?: string
}

export interface StepCopyOptions {
  /**
   * Name the model and provider doing the work. Admin-controlled
   * (`progressNarration.models`); OFF reads generically for white-label
   * deployments.
   */
  showModels: boolean
}

const DEFAULT_OPTIONS: StepCopyOptions = { showModels: true }

const MEDIA_TYPE_KEYS: Record<string, string> = {
  image: 'processing.timeline.mediaImage',
  video: 'processing.timeline.mediaVideo',
  audio: 'processing.timeline.mediaAudio',
}

function str(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() !== '' ? value : undefined
}

function num(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined
}

function strList(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : []
}

/**
 * What the sorter understood, as one plain sentence. Media and document
 * requests are the most specific hints, then research, then multi-step; a
 * plain conversation says so instead of leaving the line empty.
 */
export function intentSentence(
  metadata: TimelineStep['metadata'],
  t: Translate
): string | undefined {
  const mediaType = str(metadata.media_type)
  const intent = str(metadata.intent)
  const parts: string[] = []

  if (intent === 'image_generation' || mediaType) {
    const key =
      mediaType === 'video'
        ? 'processing.timeline.intentVideo'
        : mediaType === 'audio'
          ? 'processing.timeline.intentAudio'
          : 'processing.timeline.intentImage'
    parts.push(t(key))
  } else if (intent === 'document_generation') {
    parts.push(t('processing.timeline.intentDocument'))
  } else if (intent === 'file_analysis') {
    parts.push(t('processing.timeline.intentFile'))
  }

  if (metadata.web_search === true) {
    parts.push(t('processing.timeline.intentWebSearch'))
  }
  if (metadata.multi_step === true) {
    parts.push(t('processing.timeline.intentMultiStep'))
  }

  if (parts.length === 0 && intent === 'chat') {
    parts.push(t('processing.timeline.intentChat'))
  }

  return parts.length > 0 ? parts.join(' · ') : undefined
}

/**
 * "claude-opus-4-8 by Anthropic" — the model with its vendor when both are
 * known, the model alone otherwise. Step metadata wins over the turn-level
 * model (a media step may run on a different provider than the chat).
 */
export function modelWithProvider(
  step: TimelineStep | undefined,
  model: TimelineModel,
  t: Translate
): string | undefined {
  const meta = step?.metadata ?? {}
  const stepModel = str(meta.model_name) ?? str(meta.model)
  const name = stepModel ?? model.name
  if (!name) return str(meta.provider_label) ?? model.providerLabel
  const provider = str(meta.provider_label) ?? (stepModel ? undefined : model.providerLabel)
  return provider ? t('processing.timeline.modelByProvider', { model: name, provider }) : name
}

export function describeStep(
  step: TimelineStep,
  model: TimelineModel,
  t: Translate,
  options: StepCopyOptions = DEFAULT_OPTIONS
): StepCopy {
  const done = step.state === 'done'
  const meta = step.metadata
  const showModels = options.showModels

  switch (step.key) {
    case 'preprocessing':
      return {
        title: done
          ? t('processing.timeline.preprocessingDone')
          : t('processing.preprocessingTitle'),
        detail: done ? undefined : t('processing.preprocessingDesc'),
      }

    case 'understand': {
      if (done) {
        return {
          title: t('processing.timeline.understoodDone'),
          detail: intentSentence(meta, t),
        }
      }
      return {
        title: t('processing.classifyingTitle'),
        detail: t('processing.classifyingDesc'),
        chip: showModels ? modelWithProvider(step, {}, t) : undefined,
      }
    }

    case 'links': {
      const read = num(meta.urls_read)
      const total = num(meta.urls_total)
      if (done) {
        return {
          title: t('processing.urlsFetchedTitle'),
          chip:
            read !== undefined && total !== undefined
              ? t('processing.urlsFetchedDesc', { read, total })
              : undefined,
        }
      }
      return { title: t('processing.fetchingUrlsTitle'), detail: t('processing.fetchingUrlsDesc') }
    }

    case 'web': {
      const count = num(meta.results_count)
      if (step.status === 'search_failed') {
        return { title: t('processing.timeline.searchFailed') }
      }
      if (done) {
        return {
          title: t('processing.searchCompleteTitle'),
          chip: count !== undefined ? `${count} ${t('processing.results')}` : undefined,
        }
      }
      return { title: t('processing.searchingTitle'), detail: t('processing.searchingDesc') }
    }

    case 'pages': {
      const read = num(meta.pages_read) ?? 0
      const total = num(meta.pages_total)
      const hosts = strList(meta.hosts)
      const currentHost = str(meta.current_host)
      if (done) {
        return {
          title: t('processing.pagesReadTitle'),
          chip: t('processing.pagesReadBadge', { count: read }),
          detail: hosts.length > 0 ? hosts.join(' · ') : undefined,
        }
      }
      let detail: string
      if (currentHost && meta.stage === 'condensing') {
        detail = t('processing.timeline.condensingPage', { host: currentHost })
      } else if (currentHost) {
        detail = t('processing.timeline.readingPage', { host: currentHost })
      } else if (hosts.length > 0) {
        detail = hosts.join(' · ')
      } else {
        detail = t('processing.readingPagesDesc')
      }
      return {
        title: t('processing.readingPagesTitle'),
        detail,
        chip: total !== undefined ? `${read}/${total}` : undefined,
      }
    }

    case 'plan':
      return done
        ? { title: t('processing.timeline.plannedDone') }
        : { title: t('processing.planningTitle'), detail: t('processing.planningDesc') }

    case 'prompt':
      return done
        ? { title: t('processing.timeline.promptDone') }
        : {
            title: t('processing.analyzingPromptTitle'),
            detail: t('processing.analyzingPromptDesc'),
          }

    case 'files':
      return done
        ? { title: t('processing.timeline.filesDone') }
        : { title: t('processing.searchingFilesTitle'), detail: t('processing.searchingFilesDesc') }

    case 'memories': {
      const used = num(meta.memories_count)
      if (done) {
        return {
          title: t('processing.timeline.memoriesDone'),
          chip:
            used !== undefined && used > 0
              ? t('processing.timeline.memoriesUsed', { count: used })
              : undefined,
        }
      }
      return {
        title: t('processing.checkingMemoriesTitle'),
        detail: t('processing.checkingMemoriesDesc'),
      }
    }

    case 'analyzing':
      return done
        ? { title: t('processing.timeline.analyzedDone') }
        : {
            title: t('processing.analyzingTitle'),
            detail: str(meta.customMessage) ?? step.message ?? t('processing.analyzingDesc'),
          }

    case 'editing': {
      const source = str(meta.edit_source_name)
      return {
        title: done ? t('processing.timeline.editedDone') : t('processing.editingImageTitle'),
        detail: done
          ? undefined
          : source
            ? t('processing.editingImageNamed', { filename: source })
            : t('processing.editingImageDesc'),
      }
    }

    case 'generate': {
      const label = showModels ? modelWithProvider(step, model, t) : undefined
      const mediaKey = MEDIA_TYPE_KEYS[str(meta.media_type) ?? '']
      if (done) {
        return {
          title: label
            ? t('processing.timeline.sentTo', { model: label })
            : t('processing.timeline.sentGeneric'),
        }
      }
      if (label && mediaKey) {
        return {
          title: t('processing.timeline.generatingMediaWith', { type: t(mediaKey), model: label }),
          detail: t('processing.timeline.waitingFor', { model: label }),
        }
      }
      if (label) {
        return {
          title: t('processing.timeline.sendingTo', { model: label }),
          detail: t('processing.timeline.waitingFor', { model: label }),
        }
      }
      if (mediaKey) {
        return {
          title: t('processing.timeline.generatingMedia', { type: t(mediaKey) }),
          detail: t('processing.generatingDesc'),
        }
      }
      return {
        title: t('processing.generatingTitle'),
        detail: str(meta.customMessage) ?? t('processing.generatingDesc'),
      }
    }

    case 'file': {
      const filename = str(meta.filename)
      if (done) return { title: t('processing.timeline.fileDone'), chip: filename }
      const stage = str(meta.stage)
      const tool = showModels ? str(meta.tool) : undefined
      const writer = showModels ? modelWithProvider(undefined, model, t) : undefined
      let detail: string
      if (stage === 'converting' && tool) {
        detail = t('processing.timeline.convertingWithTool', { filename: filename ?? '', tool })
      } else if (stage === 'converting') {
        detail = t('processing.generatingFileConverting', { filename: filename ?? '' })
      } else if (stage === 'writing' && writer) {
        detail = filename
          ? t('processing.timeline.writingDocumentNamedWith', { filename, model: writer })
          : t('processing.timeline.writingDocumentWith', { model: writer })
      } else if (stage === 'writing') {
        detail = filename
          ? t('processing.generatingFileWritingNamed', { filename })
          : t('processing.generatingFileWriting')
      } else {
        detail = str(meta.customMessage) ?? t('processing.generatingFileDesc')
      }
      return { title: t('processing.generatingFileTitle'), detail }
    }

    case 'thinking': {
      const label = showModels ? model.name : undefined
      if (done) {
        const seconds = Math.max(
          1,
          Math.round(((step.endedAt ?? step.startedAt) - step.startedAt) / 1000)
        )
        return { title: t('message.thoughtFor', { n: seconds }) }
      }
      return {
        title: label
          ? t('processing.timeline.thinkingWith', { model: label })
          : t('processing.thinkingTitle'),
        detail: t('processing.thinkingDesc'),
      }
    }

    case 'memories_after': {
      if (step.status === 'memories_complete' || done) {
        return { title: t('processing.memoriesCompleteTitle') }
      }
      if (step.status === 'saving_memories') {
        return {
          title: t('processing.savingMemoriesTitle'),
          detail: t('processing.savingMemoriesDesc'),
        }
      }
      return {
        title: t('processing.analyzingMemoriesTitle'),
        detail: t('processing.analyzingMemoriesDesc'),
      }
    }
  }
}
