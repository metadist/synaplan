import { computed, type Ref } from 'vue'
import { useConfigStore } from '@/stores/config'

function bundledAsset(file: string): string {
  return `${import.meta.env.BASE_URL}${file}`
}

/**
 * Resolve the brand wordmark and compact icon from runtime config (Epic 4.4).
 *
 * A white-label hoster points `branding.logoUrl` / `logoDarkUrl` / `iconUrl`
 * at their own assets. Empty values keep the bundled Synaplan look.
 *
 * Dark-mode wordmark falls back to the light logo before the bundled SVG, so
 * setting only `logoUrl` still brands both themes.
 */
export function useBrandLogo(isDark: Ref<boolean>) {
  const config = useConfigStore()

  const configuredWordmark = computed(() => {
    if (isDark.value) {
      return config.branding.logoDarkUrl || config.branding.logoUrl
    }
    return config.branding.logoUrl
  })

  const logoSrc = computed(() => {
    if (configuredWordmark.value) {
      return configuredWordmark.value
    }
    return bundledAsset(isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg')
  })

  /**
   * Compact mark for the sidebar rail, favicon, and decorative watermarks.
   * Prefer the dedicated icon; otherwise reuse the wordmark so a logo-only
   * branding config still replaces the bird.
   */
  const iconSrc = computed(() => {
    if (config.branding.iconUrl) {
      return config.branding.iconUrl
    }
    if (configuredWordmark.value) {
      return configuredWordmark.value
    }
    return bundledAsset(isDark.value ? 'single_bird-light.svg' : 'single_bird-dark.svg')
  })

  return { logoSrc, iconSrc }
}
