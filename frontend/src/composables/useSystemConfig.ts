import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'
import {
  getConfigSchema,
  getConfigValues,
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
      isLive: all.some((f) => f.schema.source === 'database'),
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
      success(t(field?.source === 'database' ? 'admin.config.savedLive' : 'admin.config.saved'))
      values.value = {
        ...values.value,
        [key]: {
          value: field?.sensitive ? '' : value,
          isSet: true,
          isMasked: field?.sensitive || false,
        },
      }
      if (result.requiresRestart) {
        restartRequired.value = true
      }
      // Feature flags feed the runtime config (navigation, share buttons,
      // Steps editor, …) — reload it so the change is visible at once.
      if (field?.tab === 'features') {
        await configStore.reload()
      }
    } catch (err) {
      console.error('Failed to update config:', err)
      showError(t('admin.config.saveError'))
    }
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
    testService,
    dismissRestart,
  }
}

export type SystemConfigHandle = ReturnType<typeof useSystemConfig>
