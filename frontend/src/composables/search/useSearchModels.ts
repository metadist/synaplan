import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import {
  getAdminSearchConfig,
  putAdminSearchConfig,
  type AdminSearchConfig,
  type AdminSearchSlot,
} from '@/services/api/adminSearchApi'

/** How often a running reindex refreshes its progress. */
export const RUN_POLL_MS = 3000
const UNDO_TOAST_MS = 10000
const REFUSALS = ['invalid_model', 'probe_failed', 'run_in_progress'] as const

type Refusal = (typeof REFUSALS)[number]

const refusalOf = (error: unknown): Refusal | null => {
  if (!(error instanceof ApiError)) return null
  const reason = error.details?.reason
  return REFUSALS.find((known) => known === reason) ?? null
}

/**
 * State of the Smart Search card: both model slots, the index coverage and
 * the reindex run. Polls only while a run is queued or running, so a
 * finished run always lands in a terminal state on screen.
 */
export function useSearchModels() {
  const { t } = useI18n()
  const { confirm } = useDialog()
  const { success, error: showError, info, push } = useNotification()

  const config = ref<AdminSearchConfig | null>(null)
  const loading = ref(true)
  const loadFailed = ref(false)
  const saving = ref<AdminSearchSlot | null>(null)
  let pollTimer: ReturnType<typeof setTimeout> | null = null

  const stopPolling = () => {
    if (pollTimer !== null) clearTimeout(pollTimer)
    pollTimer = null
  }

  const schedulePoll = () => {
    stopPolling()
    if (config.value?.activeRun) pollTimer = setTimeout(() => void refresh(), RUN_POLL_MS)
  }

  const refresh = async () => {
    try {
      config.value = await getAdminSearchConfig()
      loadFailed.value = false
    } catch {
      loadFailed.value = config.value === null
    } finally {
      loading.value = false
    }
    schedulePoll()
  }

  const modelName = (slot: AdminSearchSlot, modelId: number | null | undefined): string => {
    const state = config.value?.[slot]
    const option = state?.options.find((candidate) => candidate.id === modelId)
    if (option) return `${option.name} (${option.service})`
    if (modelId !== null && modelId !== undefined) {
      if (modelId === state?.effectiveModelId && state.effectiveModelName)
        return state.effectiveModelName
      if (modelId === state?.inheritedModelId && state.inheritedModelName)
        return state.inheritedModelName
    }
    return String(t('aiInfra.searchModels.noModel'))
  }

  const refused = (slot: AdminSearchSlot, modelId: number | null, reason: Refusal | null) => {
    const model = modelName(slot, modelId ?? config.value?.[slot].inheritedModelId)
    showError(
      reason
        ? t(`aiInfra.searchModels.refused.${reason}`, { model })
        : t('aiInfra.searchModels.refused.unknown')
    )
  }

  const write = async (slot: AdminSearchSlot, modelId: number | null) => {
    saving.value = slot
    try {
      const result = await putAdminSearchConfig(slot, modelId)
      config.value = result.config
      schedulePoll()
      return result
    } catch (error) {
      refused(slot, modelId, refusalOf(error))
      return null
    } finally {
      saving.value = null
    }
  }

  const effectiveName = (slot: AdminSearchSlot) =>
    modelName(slot, config.value?.[slot].effectiveModelId)

  const changeAi = async (modelId: number | null) => {
    const result = await write('ai', modelId)
    if (!result) return
    push({
      type: 'success',
      message: t('aiInfra.searchModels.ai.saved', { model: effectiveName('ai') }),
      duration: UNDO_TOAST_MS,
      action: {
        label: t('aiInfra.searchModels.undo'),
        onClick: () => {
          void write('ai', result.previousModelId).then((restored) => {
            if (restored)
              success(t('aiInfra.searchModels.ai.restored', { model: effectiveName('ai') }))
          })
        },
      },
    })
  }

  const changeEmbed = async (modelId: number | null) => {
    const target = modelName('embed', modelId ?? config.value?.embed.inheritedModelId)
    const confirmed = await confirm({
      title: t('aiInfra.searchModels.embed.confirmTitle'),
      message: t('aiInfra.searchModels.embed.confirmMessage', {
        model: target,
        current: effectiveName('embed'),
        count: config.value?.index.rows ?? 0,
      }),
      confirmText: t('aiInfra.searchModels.embed.confirm'),
      cancelText: t('common.cancel'),
    })
    if (!confirmed) return false

    const result = await write('embed', modelId)
    if (!result) return false
    if (result.runId === null)
      success(t('aiInfra.searchModels.embed.savedNoRun', { model: target }))
    else info(t('aiInfra.searchModels.embed.started', { model: target }))
    return true
  }

  const run = computed(() => config.value?.activeRun ?? config.value?.latestRun ?? null)

  onBeforeUnmount(stopPolling)

  return { config, loading, loadFailed, saving, run, refresh, changeAi, changeEmbed, modelName }
}
