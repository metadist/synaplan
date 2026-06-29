import { computed, type Ref } from 'vue'
import { useConfigStore } from '@/stores/config'

/**
 * Resolve the brand wordmark logo, config-addressable with a safe fallback
 * (Epic 4.4). A white-label hoster can point `branding.logoUrl` /
 * `branding.logoDarkUrl` at their own asset; when empty we fall back to the
 * bundled Synaplan SVGs so the default look is unchanged.
 */
export function useBrandLogo(isDark: Ref<boolean>) {
  const config = useConfigStore()

  const logoSrc = computed(() => {
    const configured = isDark.value ? config.branding.logoDarkUrl : config.branding.logoUrl
    if (configured) {
      return configured
    }
    return `${import.meta.env.BASE_URL}${isDark.value ? 'synaplan-light.svg' : 'synaplan-dark.svg'}`
  })

  return { logoSrc }
}
