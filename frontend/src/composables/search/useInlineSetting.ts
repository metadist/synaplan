import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { updateConfigValue } from '@/services/api/adminConfigApi'
import { useConfigStore } from '@/stores/config'
import type { SettingControl } from './types'

/** Long enough to reach the Undo button after reading the sentence. */
export const UNDO_TOAST_MS = 10000

/**
 * Switch a system setting from a search result: the consequence is
 * confirmed first, every change ends in a toast with Undo. Writes go through
 * the admin config endpoint, so the server still decides who may change what.
 */
export function useInlineSetting() {
  const { t, te } = useI18n()
  const { confirm } = useDialog()
  const { push, error: showError } = useNotification()
  const configStore = useConfigStore()

  /** Values written in this session, so the row shows what is in force now. */
  const written = ref<Record<string, string>>({})
  const savingKey = ref<string | null>(null)

  const valueOf = (control: SettingControl): string => written.value[control.key] ?? control.current

  const nameOf = (key: string): string => {
    const feature = `people.policies.feature.${key.slice('FEATURE_'.length)}`
    return key.startsWith('FEATURE_') && te(feature) ? t(feature) : key
  }

  const optionLabel = (key: string, option: string): string => {
    const label = `admin.config.fieldOptions.${key}.${option}`
    return te(label) ? t(label) : option
  }

  const valueLabel = (control: SettingControl, value: string): string =>
    control.type === 'toggle'
      ? t(value === 'true' ? 'common.enabled' : 'common.disabled')
      : optionLabel(control.key, value)

  const consequence = (control: SettingControl, next: string): string => {
    const perKey = `search.palette.setting.consequence.${control.key}.${next}`
    if (te(perKey)) return t(perKey)
    const name = nameOf(control.key)
    if (control.type === 'toggle') {
      return t(`search.palette.setting.consequence.${next === 'true' ? 'on' : 'off'}`, { name })
    }
    return t('search.palette.setting.consequence.select', {
      name,
      value: optionLabel(control.key, next),
    })
  }

  const write = async (key: string, value: string): Promise<boolean> => {
    try {
      const result = await updateConfigValue(key, value)
      return result.success
    } catch {
      return false
    }
  }

  /** Feature flags feed the runtime config; a failed reload is not a failed save. */
  const refreshRuntime = async () => {
    try {
      await configStore.reload()
    } catch {
      showError(t('search.palette.setting.notRefreshed'))
    }
  }

  const undo = async (control: SettingControl, previous: string) => {
    const name = nameOf(control.key)
    const current = valueOf(control)
    savingKey.value = control.key
    const restored = await write(control.key, previous)
    savingKey.value = null
    if (!restored) {
      showError(
        t('search.palette.setting.undoFailed', { name, value: valueLabel(control, current) })
      )
      return
    }
    written.value = { ...written.value, [control.key]: previous }
    push({
      type: 'info',
      message: t('search.palette.setting.restored', {
        name,
        value: valueLabel(control, previous),
      }),
    })
    await refreshRuntime()
  }

  const apply = async (control: SettingControl, next: string): Promise<void> => {
    const previous = valueOf(control)
    if (control.envPinned || savingKey.value !== null || next === previous) return

    const name = nameOf(control.key)
    const confirmed = await confirm({
      title: t('search.palette.setting.confirmTitle', { name }),
      message: consequence(control, next),
      confirmText: t('search.palette.setting.confirm'),
      cancelText: t('common.cancel'),
    })
    if (!confirmed) return

    savingKey.value = control.key
    written.value = { ...written.value, [control.key]: next }
    const saved = await write(control.key, next)
    savingKey.value = null

    if (!saved) {
      written.value = { ...written.value, [control.key]: previous }
      showError(t('search.palette.setting.failed', { name, value: valueLabel(control, previous) }))
      return
    }

    push({
      type: 'success',
      message: t('search.palette.setting.saved', { name, value: valueLabel(control, next) }),
      duration: UNDO_TOAST_MS,
      action: {
        label: t('search.palette.setting.undo'),
        onClick: () => void undo(control, previous),
      },
    })
    await refreshRuntime()
  }

  /** Toggle flips; a select moves to the next choice (keyboard shortcut). */
  const cycle = (control: SettingControl): Promise<void> => {
    const current = valueOf(control)
    if (control.type === 'toggle') return apply(control, current === 'true' ? 'false' : 'true')
    const options = control.options
    if (options.length === 0) return Promise.resolve()
    const next = options[(options.indexOf(current) + 1) % options.length]!
    return apply(control, next)
  }

  return { apply, cycle, valueOf, nameOf, optionLabel, valueLabel, savingKey }
}
