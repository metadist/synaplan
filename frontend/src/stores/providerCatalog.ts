/**
 * The provider catalog as the server declares it (`GET /api/v1/ai/providers`):
 * names, icons, flags, help hints and key requirements for every AI provider
 * of this installation. Icons, help hints and pickers read it here; the
 * bundled maps in `utils/providerIcons.ts` and `utils/providerHelp.ts` are
 * the fallback for the first paint and for a name the server does not know.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { fetchProviderCatalog, type CatalogProvider } from '@/services/api/providerCatalogApi'
import { useAuthStore } from '@/stores/auth'
import {
  providerHelpByEnvVar,
  providerHelpByName,
  type ProviderHelpMeta,
} from '@/utils/providerHelp'
import { getProviderFlag, getProviderIcon, isLocalSelfHostedProvider } from '@/utils/providerIcons'

/** Lowercase without spaces, dashes or underscores: `Hugging Face` and `huggingface` are one name. */
const compact = (name: string): string => name.toLowerCase().replace(/[\s_-]/g, '')

/**
 * Bundled glyphs the old `ServiceIcon` switched on by substring; kept as the
 * fallback so a provider name the server does not know draws as before.
 */
const fallbackIconComponent = (service: string): string | null => {
  const name = compact(service)
  if (name.includes('openaicompatible')) return 'OpenAiCompatibleIcon'
  if (name.includes('groq')) return 'GroqIcon'
  if (name.includes('mistral')) return 'MistralIcon'
  return null
}

/** The help hint the server attached to a provider, in the shape the bundled map uses. */
const helpOf = (provider: CatalogProvider): ProviderHelpMeta | null =>
  provider.helpKey && provider.helpUrl
    ? { id: provider.helpKey, url: provider.helpUrl, isDownload: provider.helpIsDownload }
    : null

export const useProviderCatalogStore = defineStore('providerCatalog', () => {
  const providers = ref<CatalogProvider[]>([])
  const loaded = ref(false)
  let pending: Promise<void> | null = null

  const byName = computed(() => {
    const map = new Map<string, CatalogProvider>()
    for (const provider of providers.value) map.set(compact(provider.name), provider)
    return map
  })

  /** Load once; a failure leaves the bundled fallback in place and is not an error a person sees. */
  async function load(): Promise<void> {
    if (loaded.value) return
    if (pending) return pending
    pending = fetchProviderCatalog()
      .then((response) => {
        providers.value = response.providers
        loaded.value = true
      })
      .catch(() => {
        // Offline, signed out, or an older server: the bundled maps answer.
      })
      .finally(() => {
        pending = null
      })
    return pending
  }

  /** Fire-and-forget load for a component that renders provider branding. */
  function ensureLoaded(): void {
    if (loaded.value || pending) return
    if (!useAuthStore().isAuthenticated) return
    void load()
  }

  function find(service: string): CatalogProvider | null {
    return byName.value.get(compact(service)) ?? null
  }

  function displayName(service: string): string | null {
    return find(service)?.displayName ?? null
  }

  function iconFor(service: string): string {
    return find(service)?.icon ?? getProviderIcon(service)
  }

  function iconComponentFor(service: string): string | null {
    const provider = find(service)
    return provider ? provider.iconComponent : fallbackIconComponent(service)
  }

  function flagFor(service: string): string {
    return find(service)?.flag ?? getProviderFlag(service)
  }

  function isLocal(service: string): boolean {
    return find(service)?.local ?? isLocalSelfHostedProvider(service)
  }

  /** The help hint of a provider, by name. */
  function helpFor(name: string): ProviderHelpMeta | null {
    const provider = find(name)
    return provider ? helpOf(provider) : providerHelpByName(name)
  }

  /** The help hint a system-config field shows, by field key. */
  function helpForField(envVar: string): ProviderHelpMeta | null {
    const provider = providers.value.find((p) => p.helpFields.includes(envVar))
    return provider ? helpOf(provider) : providerHelpByEnvVar(envVar)
  }

  /** For tests: forget the loaded catalog. */
  function reset(): void {
    providers.value = []
    loaded.value = false
    pending = null
  }

  return {
    providers,
    loaded,
    load,
    ensureLoaded,
    find,
    displayName,
    iconFor,
    iconComponentFor,
    flagFor,
    isLocal,
    helpFor,
    helpForField,
    reset,
  }
})
