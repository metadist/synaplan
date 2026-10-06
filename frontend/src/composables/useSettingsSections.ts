import { computed, toValue, type MaybeRef } from 'vue'
import type { LocationQuery, RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { isNativeServerControlAvailable, isPurchaseAllowed } from '@/services/api/nativeServer'

export interface SettingsSectionLink {
  /** DOM id of the section on its page. */
  id: string
  /** Path segment under /settings. */
  slug: string
  labelKey: string
}

/**
 * The settings sections, in page order. A failed profile load hides the
 * sections that only render once the profile form is available.
 */
export function settingsSectionLinks(
  profileLoadFailed: boolean,
  showBilling: boolean,
  showApp: boolean
): SettingsSectionLink[] {
  const items: SettingsSectionLink[] = [
    { id: 'profile', slug: 'profile', labelKey: 'settings.sections.profile' },
    { id: 'appearance', slug: 'appearance', labelKey: 'settings.sections.appearance' },
    { id: 'chat', slug: 'chat', labelKey: 'settings.sections.chat' },
    { id: 'data', slug: 'data', labelKey: 'settings.sections.data' },
  ]
  if (!profileLoadFailed && showBilling) {
    items.push({ id: 'billing', slug: 'billing', labelKey: 'settings.sections.billing' })
  }
  if (!profileLoadFailed) {
    items.push({ id: 'security', slug: 'security', labelKey: 'settings.sections.security' })
  }
  if (showApp) {
    items.push({ id: 'app-server', slug: 'app', labelKey: 'settings.sections.app' })
  }
  if (!profileLoadFailed) {
    items.push(
      { id: 'legal', slug: 'legal', labelKey: 'settings.sections.legal' },
      { id: 'delete', slug: 'delete', labelKey: 'settings.sections.delete' }
    )
  }
  return items
}

export function useSettingsSections(profileLoadFailed: MaybeRef<boolean> = false) {
  const config = useConfigStore()
  const purchaseAllowed = isPurchaseAllowed()
  const showApp = isNativeServerControlAvailable()
  const showBilling = computed(() => config.billing.enabled && purchaseAllowed)

  const sections = computed(() =>
    settingsSectionLinks(toValue(profileLoadFailed), showBilling.value, showApp)
  )

  return { sections, showApp, showBilling }
}

/** Section element id for a settings hash, including the older aliases. */
export function settingsHashTarget(hash: string): string {
  if (hash === '#memories') return 'chat'
  if (hash === '#app') return 'app-server'
  if (hash === '' || hash === '#' || hash === '#profile') return 'profile'
  return hash.startsWith('#') ? hash.slice(1) : hash
}

function queryEqual(left: LocationQuery, right: LocationQuery): boolean {
  const keys = new Set([...Object.keys(left), ...Object.keys(right)])
  for (const key of keys) {
    if (String(left[key] ?? '') !== String(right[key] ?? '')) return false
  }
  return true
}

/**
 * One settings section per URL. Older hashes (#memories, #app, #billing)
 * and /settings without a section land on the matching page.
 */
export function settingsLocationFor(
  to: Pick<RouteLocationNormalized, 'hash' | 'query' | 'params'>
): { path: string; query: LocationQuery } {
  const auth = useAuthStore()
  const config = useConfigStore()
  const allowed = new Set(
    settingsSectionLinks(
      false,
      config.billing.enabled && isPurchaseAllowed(),
      isNativeServerControlAvailable()
    ).map((item) => item.slug)
  )
  const query: LocationQuery = { ...to.query }
  let slug = typeof to.params.section === 'string' ? to.params.section : ''

  if (to.hash === '#memories' || query.highlight === 'memories') {
    slug = 'chat'
    query.highlight = 'memories'
  } else if (query.tab === 'subscription') {
    slug = 'billing'
    delete query.tab
  } else if (to.hash && to.hash !== '#') {
    const target = settingsHashTarget(to.hash)
    slug = target === 'app-server' ? 'app' : target
  } else if (slug === 'app-server') {
    slug = 'app'
  }

  if (!auth.isAuthenticated) {
    slug = 'appearance'
  } else if (!allowed.has(slug)) {
    slug = 'profile'
  }

  return { path: `/settings/${slug}`, query }
}

/** beforeEnter: stay put when the URL is already the canonical section. */
export function normalizeSettingsRoute(to: RouteLocationNormalized): RouteLocationRaw | true {
  const next = settingsLocationFor(to)
  if (
    to.path === next.path &&
    (to.hash === '' || to.hash === '#') &&
    queryEqual(to.query, next.query)
  ) {
    return true
  }
  return next
}
