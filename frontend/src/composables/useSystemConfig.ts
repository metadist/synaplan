import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'
import {
  getConfigSchema,
  getConfigValues,
  resetBrandingStyle as resetBrandingStyleApi,
  testConnection,
  updateConfigValue,
  type ConfigFieldSchema,
  type ConfigSchema,
  type ConfigValue,
} from '@/services/api/adminConfigApi'
import {
  SECTION_TEST_SERVICE,
  sectionKey,
  type ConfigSectionRef,
} from '@/constants/operateSettings'
import { applyBrandingTheme } from '@/utils/brandingTheme'

export interface ResolvedConfigField {
  key: string
  schema: ConfigFieldSchema
  value: ConfigValue
}

export interface ResolvedConfigSection {
  /** Backend section id — used for DOM ids and `?section=` deep links. */
  id: string
  tab: string
  label: string
  fields: ResolvedConfigField[]
  /** Instance provider keys: edited on the provider cards, reported read-only here. */
  managedFields: ResolvedConfigField[]
  allManaged: boolean
  isLive: boolean
  testService: string | null
}

export interface ResolveOptions {
  /** Drop provider-key fields entirely (the page already shows their editor). */
  hideManaged?: boolean
  /** Field keys that must not render on this surface. */
  hiddenFields?: readonly string[]
}

const EMPTY_VALUE: ConfigValue = { value: '', isSet: false, isMasked: false }

/**
 * Must match BrandingService::STYLE_RESET_KEYS. Used only to re-read the
 * outcome when the reset response itself is lost.
 */
const STYLE_RESET_KEYS = [
  'BRAND_PRIMARY_COLOR',
  'BRAND_SECONDARY_COLOR',
  'BRAND_ACCENT_COLOR',
  'BRAND_PRIMARY_COLOR_DARK',
  'BRAND_SECONDARY_COLOR_DARK',
  'BRAND_ACCENT_COLOR_DARK',
  'BRAND_FONT_FAMILY',
  'BRAND_HEADING_FONT_FAMILY',
  'BRAND_FONT_URL',
] as const

function styleValueChanged(
  before: ConfigValue | undefined,
  after: ConfigValue | undefined
): boolean {
  return (
    (before?.isSet ?? false) !== (after?.isSet ?? false) ||
    (before?.value ?? '') !== (after?.value ?? '')
  )
}

/**
 * Loads the admin config schema and values once per page and owns saving,
 * the restart notice and connection tests, so AI infrastructure and System
 * configuration render the same settings with the same behaviour.
 */
export function useSystemConfig() {
  const { t, te } = useI18n()
  const configStore = useConfigStore()
  const { success, error: showError } = useNotification()

  const loading = ref(false)
  const loaded = ref(false)
  const loadFailed = ref(false)
  const schema = ref<ConfigSchema | null>(null)
  const values = ref<Record<string, ConfigValue>>({})
  const restartRequired = ref(false)
  const testingService = ref<string | null>(null)

  async function load(): Promise<void> {
    loading.value = true
    try {
      const [schemaData, valuesData] = await Promise.all([getConfigSchema(), getConfigValues()])
      schema.value = schemaData
      values.value = valuesData
      loadFailed.value = false
      loaded.value = true
    } catch (err) {
      console.error('Failed to load system config:', err)
      loadFailed.value = true
      showError(t('admin.config.loadError'))
    } finally {
      loading.value = false
    }
  }

  async function ensureLoaded(): Promise<void> {
    if (loaded.value || loading.value) return
    await load()
  }

  /** Section ids repeat across backend tabs (`ai.media`, `processing.media`), so labels are keyed by both. */
  function sectionLabel(ref: ConfigSectionRef, fallback: string): string {
    const key = `admin.config.sections.${ref.tab}_${ref.section}`
    return te(key) ? t(key) : fallback
  }

  function resolveSection(
    ref: ConfigSectionRef,
    options: ResolveOptions = {}
  ): ResolvedConfigSection | null {
    const section = schema.value?.tabs[ref.tab]?.sections[ref.section]
    if (!schema.value || !section) return null

    const hidden = new Set(options.hiddenFields ?? [])
    const all: ResolvedConfigField[] = section.fields
      .filter((key) => !hidden.has(key) && schema.value?.fields[key])
      .map((key) => ({
        key,
        schema: schema.value!.fields[key],
        value: values.value[key] ?? EMPTY_VALUE,
      }))

    const fields = all.filter((f) => !f.schema.managedBy)
    const managedFields = options.hideManaged ? [] : all.filter((f) => !!f.schema.managedBy)
    if (fields.length === 0 && managedFields.length === 0) return null

    return {
      id: ref.section,
      tab: ref.tab,
      label: sectionLabel(ref, section.label),
      fields,
      managedFields,
      allManaged: managedFields.length > 0 && fields.length === 0,
      // The badge promises "saving takes effect immediately". Only the fields
      // actually rendered as inputs count, and every one of them must be
      // database-backed — a hidden provider key must not badge an env field.
      isLive: fields.length > 0 && fields.every((f) => f.schema.source === 'database'),
      testService: SECTION_TEST_SERVICE[sectionKey(ref)] ?? null,
    }
  }

  async function update(key: string, value: string): Promise<void> {
    try {
      const result = await updateConfigValue(key, value)
      if (!result.success) {
        showError(result.error || t('admin.config.saveError'))
        return
      }
      const field = schema.value?.fields[key]
      const cleared = value === ''
      success(
        t(
          cleared
            ? 'admin.config.cleared'
            : field?.source === 'database'
              ? 'admin.config.savedLive'
              : 'admin.config.saved'
        )
      )
      const previous = values.value[key]
      values.value = {
        ...values.value,
        [key]: {
          ...previous,
          value: cleared ? (field?.default ?? '') : field?.sensitive ? '' : value,
          isSet: !cleared,
          isMasked: !cleared && Boolean(field?.sensitive),
        },
      }
      if (result.requiresRestart) {
        restartRequired.value = true
      }
      // Feature flags and branding feed the runtime config — reload it so the
      // change is visible at once. A reload failure is not a save failure:
      // the value is already stored, so the message must not invite a retry.
      if (field?.tab === 'features' || field?.tab === 'branding') {
        try {
          await configStore.reload()
          if (field.tab === 'branding') {
            applyBrandingTheme()
          }
        } catch (err) {
          console.error('Saved, but the runtime config could not be reloaded:', err)
          showError(t('admin.config.savedButNotRefreshed'))
        }
      }
    } catch (err) {
      console.error('Failed to update config:', err)
      showError(t('admin.config.saveError'))
    }
  }

  /**
   * Restore the default style (brand colors + fonts, both modes). One request,
   * one outcome sentence: the backend reports per key what did and did not
   * reset, and the runtime config + live theme follow in the same step so the
   * new defaults are visible at once.
   */
  async function resetBrandingStyle(): Promise<boolean> {
    try {
      const result = await resetBrandingStyleApi()
      const next = { ...values.value }
      for (const key of result.reset) {
        const field = schema.value?.fields[key]
        next[key] = {
          value: field?.default ?? '',
          isSet: false,
          isMasked: false,
        }
      }
      values.value = next
      try {
        await configStore.reload()
        applyBrandingTheme()
      } catch (err) {
        console.error('Style reset, but the runtime config could not be reloaded:', err)
        showError(t('admin.config.savedButNotRefreshed'))
        return result.success
      }
      if (result.success) {
        success(t('admin.config.brandingReset.success'))
      } else {
        showError(
          t('admin.config.brandingReset.partial', {
            reset: result.reset.length,
            failed: result.failed.length,
          })
        )
      }
      return result.success
    } catch (err) {
      // A dropped response or a schema mismatch can land here after the
      // server already cleared the style keys. Re-read them before saying
      // anything about what changed.
      console.error('Failed to reset branding style:', err)
      return reconcileBrandingReset(values.value)
    }
  }

  /**
   * The reset call failed in the client. The stored values say what actually
   * happened: nothing, a full reset, or a partial one. If they cannot be
   * read, say so — do not claim the previous values are still in place.
   */
  async function reconcileBrandingReset(before: Record<string, ConfigValue>): Promise<boolean> {
    let fresh: Record<string, ConfigValue>
    try {
      fresh = await getConfigValues()
    } catch (err) {
      console.error('Could not re-read branding after a reset error:', err)
      showError(t('admin.config.brandingReset.unconfirmed'))
      return false
    }

    values.value = fresh
    const cleared = STYLE_RESET_KEYS.filter(
      (key) => styleValueChanged(before[key], fresh[key]) && !fresh[key]?.isSet
    )
    const stillCustom = STYLE_RESET_KEYS.filter((key) => fresh[key]?.isSet)

    try {
      await configStore.reload()
      applyBrandingTheme()
    } catch (err) {
      if (cleared.length > 0) {
        console.error('Style reset, but the runtime config could not be reloaded:', err)
        showError(t('admin.config.savedButNotRefreshed'))
        return stillCustom.length === 0
      }
    }

    if (cleared.length === 0) {
      showError(t('admin.config.brandingReset.failed'))
      return false
    }
    if (stillCustom.length === 0) {
      success(t('admin.config.brandingReset.success'))
      return true
    }
    showError(
      t('admin.config.brandingReset.partial', {
        reset: cleared.length,
        failed: stillCustom.length,
      })
    )
    return false
  }

  async function testService(service: string): Promise<void> {
    testingService.value = service
    try {
      const result = await testConnection(service)
      if (result.success) {
        success(result.message)
      } else {
        showError(result.message || t('admin.config.testFailed'))
      }
    } catch (err) {
      console.error('Connection test failed:', err)
      showError(t('admin.config.testFailed'))
    } finally {
      testingService.value = null
    }
  }

  function dismissRestart(): void {
    restartRequired.value = false
  }

  return {
    loading,
    loaded,
    loadFailed,
    schema,
    values,
    restartRequired,
    testingService,
    load,
    ensureLoaded,
    resolveSection,
    update,
    resetBrandingStyle,
    testService,
    dismissRestart,
  }
}

export type SystemConfigHandle = ReturnType<typeof useSystemConfig>
