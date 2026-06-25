import {
  createRouter,
  createWebHistory,
  type NavigationGuardNext,
  type RouteLocationNormalized,
  type RouteLocationRaw,
} from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { useConfigStore } from '@/stores/config'
import { authReady } from '@/stores/auth'
import { useGlobalErrorStore } from '@/stores/globalError'
import { useGuestStore, GUEST_STORAGE_KEY } from '@/stores/guest'
import { i18n } from '@/i18n'
import { getErrorMessage } from '@/utils/errorMessage'
import LoadingView from '@/views/LoadingView.vue'

const guardSubscription = (
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext
) => {
  const configStore = useConfigStore()
  if (!configStore.billing.enabled) {
    next(resolveDefaultRoute())
  } else {
    next()
  }
}

/** System / feature status page is for local development only (not shown in production builds). */
const guardDevOnlyAdminFeatures = (
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext
) => {
  if (!import.meta.env.DEV) {
    next({ name: 'admin' })
    return
  }
  next()
}

/**
 * Build-time fallback brand name. The live name comes from the runtime branding
 * config (`useConfigStore().branding.name`, Epic 4); this constant only covers
 * the window before config has loaded and the static index.html title.
 */
export const APP_NAME = 'Synaplan'

/** Live brand name with a safe fallback before runtime config has loaded. */
export function brandName(): string {
  return useConfigStore().branding.name || APP_NAME
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    // Public routes (no auth required)
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.login' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.register' },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.forgotPassword' },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.resetPassword' },
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('@/views/VerifyEmailView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.verifyEmail' },
    },
    {
      path: '/verify-email-callback',
      name: 'verify-email-callback',
      component: () => import('@/views/VerifyEmailCallbackView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.verifyEmail' },
    },
    {
      path: '/email-verified',
      name: 'email-verified',
      component: () => import('@/views/EmailVerifiedView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.emailVerified' },
    },
    {
      path: '/auth/callback',
      name: 'oauth-callback',
      component: () => import('../components/auth/OAuthCallback.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.login' },
    },
    {
      // Bridge page loaded inside an Office.context.ui.displayDialogAsync
      // popup from the Synamail Outlook add-in. Issues a scoped API key
      // and posts it back to the parent taskpane via messageParent.
      // See AddinConnectView.vue for the protocol details, and
      // Synamail/docs/SYNAPLAN_INTEGRATION.md for the cross-repo plan.
      path: '/addin/connect',
      name: 'addin-connect',
      component: () => import('@/views/AddinConnectView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.addinConnect' },
    },
    {
      path: '/logged-out',
      name: 'logged-out',
      component: () => import('@/views/LoggedOutView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.loggedOut' },
    },
    {
      // MOBILE-APP SEAM (Epic 9.1): public account-deletion info page. Google
      // Play requires a web URL where users can learn how to delete their
      // account + data WITHOUT signing in. Default target of
      // `branding.accountDeletionUrl`; reachable from store metadata too.
      path: '/account-deletion',
      name: 'account-deletion',
      component: () => import('@/views/AccountDeletionView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.accountDeletion' },
    },
    // Shared chat with optional language parameter for SEO
    // /shared/de/abc123 -> German UI
    // /shared/en/abc123 -> English UI
    // /shared/abc123 -> Default to English (backwards compatible)
    // Note: SharedChatView sets its own dynamic title based on chat content
    {
      path: '/shared/:lang([a-z]{2})/:token',
      name: 'shared-chat-lang',
      component: () => import('@/views/SharedChatView.vue'),
      meta: { requiresAuth: false, public: true },
    },
    {
      path: '/shared/:token',
      name: 'shared-chat',
      component: () => import('@/views/SharedChatView.vue'),
      meta: { requiresAuth: false, public: true },
    },

    // NOTE: There is intentionally no '/error' route.
    // Errors are surfaced inline by ErrorBoundary via the globalError Pinia
    // store, which keeps the URL stable and lets us recover without a full
    // navigation (see components/ErrorBoundary.vue + stores/globalError.ts).
    {
      path: '/loading',
      name: 'loading',
      component: LoadingView,
      meta: { requiresAuth: false },
    },

    // Chat route (accessible for both authenticated users and guests)
    {
      path: '/',
      name: 'chat',
      component: () => import('@/views/ChatView.vue'),
      meta: { requiresAuth: false, allowGuest: true, titleKey: 'pageTitles.chat' },
    },

    // Protected routes (require authentication)
    //
    // Canonical URL tree since the 2026-06 navigation IA cleanup (§4.6):
    //   /channels/*  — ways conversations reach Synaplan (widgets, email, API)
    //   /ai/*        — AI machinery (models, instructions, routing, summarizer)
    //   /files/*     — knowledge base (browse + search)
    // The old /tools/* and /config/* paths redirect below (kept ≥ 2 releases
    // for bookmarks/docs; see redirects.spec.ts).
    {
      path: '/channels',
      name: 'channels',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configInbound' },
    },
    {
      path: '/channels/widgets',
      name: 'channels-widgets',
      component: () => import('@/views/WidgetsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.chatWidget', titleKey: 'pageTitles.chatWidget' },
    },
    {
      path: '/channels/widgets/live-support',
      name: 'live-support',
      component: () => import('../views/LiveSupportView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.liveSupport' },
    },
    {
      path: '/channels/widgets/:widgetId/chats',
      name: 'widget-chats',
      component: () => import('../views/WidgetSessionsView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.widgetChats' },
    },
    {
      path: '/channels/widgets/:widgetId',
      name: 'widget-detail',
      component: () => import('@/views/WidgetDetailView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.widgetDetail' },
    },
    {
      path: '/channels/email',
      name: 'channels-email',
      component: () => import('@/views/ToolsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.mailHandler', titleKey: 'pageTitles.mailHandler' },
    },
    {
      path: '/channels/api',
      name: 'channels-api',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configApiKeys' },
    },
    {
      path: '/channels/api/docs',
      name: 'channels-api-docs',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configApiDocs' },
    },
    {
      path: '/ai/models',
      name: 'ai-models',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configAiModels' },
    },
    {
      path: '/ai/providers/higgsfield',
      name: 'ai-provider-higgsfield',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configProviderHiggsfield' },
    },
    {
      path: '/ai/instructions',
      name: 'ai-instructions',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configTaskPrompts' },
    },
    {
      path: '/ai/routing',
      name: 'ai-routing',
      component: () => import('@/views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configSortingPrompt' },
    },
    {
      // Transitional: the page retires into the chat Tools dropdown (Q3);
      // the backend POST /api/v1/summary/generate API is a stable contract
      // (Nextcloud + plugin consumers) and is documented on /channels/api/docs.
      path: '/ai/summarizer',
      name: 'ai-summarizer',
      component: () => import('@/views/ToolsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.docSummary', titleKey: 'pageTitles.docSummary' },
    },

    // --- Transitional redirects (old → new, §4.6; keep ≥ 2 releases) ---
    { path: '/tools', redirect: '/channels' },
    { path: '/tools/chat-widget', redirect: '/channels/widgets' },
    { path: '/tools/chat-widget/live-support', redirect: '/channels/widgets/live-support' },
    {
      path: '/tools/chat-widget/:widgetId/chats',
      redirect: (to) => ({
        path: `/channels/widgets/${to.params.widgetId}/chats`,
        query: to.query,
      }),
    },
    {
      path: '/tools/chat-widget/:widgetId',
      redirect: (to) => ({ path: `/channels/widgets/${to.params.widgetId}`, query: to.query }),
    },
    { path: '/tools/mail-handler', redirect: '/channels/email' },
    { path: '/tools/doc-summary', redirect: '/ai/summarizer' },
    {
      path: '/plugins/:pluginName',
      name: 'plugin-view',
      component: () => import('@/views/PluginView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.plugins' },
    },
    {
      path: '/files',
      name: 'files',
      component: () => import('@/views/FilesView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.files' },
    },
    {
      path: '/memories',
      name: 'memories',
      component: () => import('@/views/MemoriesView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.memories' },
      beforeEnter: (_to, _from, next) => {
        // Check if memory service is available
        const configStore = useConfigStore()
        if (configStore.features?.memoryService === false) {
          // Memory service not available - redirect to chat
          next({ name: 'chat' })
        } else {
          next()
        }
      },
    },
    {
      path: '/feedbacks',
      name: 'feedbacks',
      component: () => import('@/views/FeedbackView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.feedback' },
      beforeEnter: (_to, _from, next) => {
        // Check if memory service is available (feedbacks stored in same service)
        const configStore = useConfigStore()
        if (configStore.features?.memoryService === false) {
          next({ name: 'chat' })
        } else {
          next()
        }
      },
    },
    {
      // Search over the knowledge base. Phase 5 of the IA cleanup folds this
      // into FilesView as a tab; the URL is already canonical (§4.6).
      path: '/files/search',
      name: 'files-search',
      component: () => import('@/views/RagSearchView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.ragSearch' },
    },
    {
      // Vector storage (Qdrant/MariaDB) inventory: how many files and vectors
      // are stored for the user, plus a global admin view.
      path: '/files/vectors',
      name: 'files-vectors',
      component: () => import('@/views/VectorStorageView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.vectorStorage' },
    },

    // --- Transitional redirects (old → new, §4.6; keep ≥ 2 releases) ---
    { path: '/rag', redirect: '/files/search' },
    { path: '/config', redirect: '/channels' },
    { path: '/config/inbound', redirect: '/channels' },
    { path: '/config/ai-models', redirect: '/ai/models' },
    { path: '/config/task-prompts', redirect: '/ai/instructions' },
    { path: '/config/sorting-prompt', redirect: '/ai/routing' },
    { path: '/config/api-keys', redirect: '/channels/api' },
    { path: '/config/api-documentation', redirect: '/channels/api/docs' },
    {
      path: '/statistics',
      name: 'statistics',
      component: () => import('@/views/StatisticsView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.statistics' },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.settings' },
    },
    {
      path: '/testv',
      name: 'test',
      component: () => import('@/views/TestView.vue'),
      meta: { requiresAuth: false }, // Test page accessible without auth
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/views/ProfileView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.profile' },
    },
    {
      path: '/admin',
      name: 'admin',
      component: () => import('@/views/AdminView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.admin' },
    },
    {
      path: '/admin/features',
      name: 'admin-features',
      component: () => import('@/views/FeatureStatusView.vue'),
      beforeEnter: guardDevOnlyAdminFeatures,
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.adminFeatures' },
    },
    {
      path: '/admin/config',
      name: 'admin-config',
      component: () => import('@/views/AdminConfigView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.adminConfig' },
    },
    {
      path: '/subscription',
      name: 'subscription',
      component: () => import('@/views/SubscriptionView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscription' },
      beforeEnter: guardSubscription,
    },
    {
      path: '/subscription/success',
      name: 'subscription-success',
      component: () => import('@/views/SubscriptionSuccessView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscriptionSuccess' },
      beforeEnter: guardSubscription,
    },
    {
      path: '/subscription/cancel',
      name: 'subscription-cancel',
      component: () => import('@/views/SubscriptionCancelView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscriptionCancel' },
      beforeEnter: guardSubscription,
    },
    // 404 - Must be last
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { requiresAuth: false, titleKey: 'pageTitles.notFound' },
    },
  ],
})

// Update document title after each navigation
router.afterEach((to) => {
  const titleKey = to.meta.titleKey as string | undefined
  if (titleKey) {
    const t = i18n.global.t
    const pageTitle = t(titleKey)
    document.title = `${pageTitle} | ${brandName()}`
  } else {
    // Fallback for routes without titleKey (e.g., shared chat handles its own title)
    document.title = brandName()
  }
})

// Loop detection: track recent redirects to prevent infinite loops
const redirectHistory: { path: string; time: number }[] = []
const LOOP_WINDOW_MS = 3000 // 3 second window
const MAX_REDIRECTS_IN_WINDOW = 3 // Max redirects allowed in window

function detectRedirectLoop(targetPath: string): boolean {
  const now = Date.now()

  // Clean old entries
  while (redirectHistory.length > 0 && now - redirectHistory[0].time > LOOP_WINDOW_MS) {
    redirectHistory.shift()
  }

  // Add current redirect
  redirectHistory.push({ path: targetPath, time: now })

  // Check for loop (same path repeated too many times)
  const recentToLogin = redirectHistory.filter((r) => r.path.startsWith('/login')).length
  if (recentToLogin >= MAX_REDIRECTS_IN_WINDOW) {
    console.error('🔄 Redirect loop detected! Stopping redirect chain.')
    return true
  }

  return false
}

/**
 * Maps a route path to a backend feature-status key for the guest gate.
 *
 * CHECKPOINT (§4.6): the returned strings ('files', 'settings', 'memories',
 * 'statistics') are BACKEND feature-status keys (admin → Feature Status) and
 * MUST stay identical across URL migrations — a wrong key fails silently
 * (wrong or missing upsell). The new /channels/* and /ai/* prefixes map to
 * 'settings' exactly like the legacy /config/* and /tools/* paths they
 * replaced; /files/search inherits 'files' via the /files prefix.
 */
function mapPathToFeatureKey(path: string): string {
  if (path.startsWith('/files')) return 'files'
  if (path.startsWith('/memories') || path.startsWith('/feedbacks')) return 'memories'
  if (
    path.startsWith('/config') ||
    path.startsWith('/settings') ||
    path.startsWith('/tools') ||
    path.startsWith('/channels') ||
    path.startsWith('/ai/')
  )
    return 'settings'
  if (path.startsWith('/statistics')) return 'statistics'
  return 'general'
}

/**
 * MOBILE-APP SEAM (Epic 4): configurable start page / post-login route.
 *
 * A branded deployment (or the app pointed at a branded server) can set
 * `branding.defaultRoute` (post-login home) and `branding.landingPage`
 * (logged-out entry). Each accepts EITHER a route name (e.g. 'chat') OR a
 * free-form path (must start with '/', e.g. '/welcome') so a brand can point at
 * a custom marketing/landing page that isn't a named route.
 *
 * Safety net (so a typo can never break navigation):
 *  - free-form values must start with '/'; otherwise the value is treated as a
 *    route name and validated by name,
 *  - the value is resolved against the REAL route table — an unknown path lands
 *    on the catch-all 404 and is rejected, an unknown name throws and is caught,
 *  - the logged-out landing must additionally resolve to a PUBLIC route (else a
 *    not-signed-in visitor would just bounce back to login → loop),
 *  - callers additionally guard against self-redirects (see beforeEach).
 * Anything rejected falls back to today's defaults ('chat' / 'login').
 */
function resolveBrandTarget(
  configured: string,
  opts: { requirePublic?: boolean; rejectNames?: string[] }
): RouteLocationRaw | null {
  if (!configured) {
    return null
  }

  // A leading '/' means free-form path; anything else is a named route.
  const target: RouteLocationRaw = configured.startsWith('/') ? configured : { name: configured }

  let resolved
  try {
    resolved = router.resolve(target)
  } catch {
    // Unknown route name → vue-router throws.
    return null
  }

  // Unknown path resolves to the catch-all 404; unknown name has no match.
  if (resolved.matched.length === 0 || resolved.name === 'not-found') {
    return null
  }
  if (opts.rejectNames?.includes(resolved.name as string)) {
    return null
  }
  if (opts.requirePublic && resolved.meta?.public !== true) {
    return null
  }

  return target
}

/** Post-login home as a navigation target; defaults to 'chat'. */
function resolveDefaultRoute(): RouteLocationRaw {
  const configured = useConfigStore().branding.defaultRoute
  return resolveBrandTarget(configured, { rejectNames: ['login', 'not-found'] }) ?? { name: 'chat' }
}

/**
 * The custom logged-out landing as a navigation target, or `null` when nothing
 * valid is configured (→ keep today's default behavior, no redirect).
 */
function resolveLandingTarget(): RouteLocationRaw | null {
  const configured = useConfigStore().branding.landingPage
  return resolveBrandTarget(configured, { requirePublic: true, rejectNames: ['not-found'] })
}

/** Resolved path of a navigation target, for self-redirect / loop checks. */
function targetPath(target: RouteLocationRaw): string {
  try {
    return router.resolve(target).path
  } catch {
    return ''
  }
}

// Global navigation guard for authentication
// With cookie-based auth, we wait for auth check then verify session
router.beforeEach(async (to, from, next) => {
  // Wait for initial auth check with timeout to prevent hanging
  try {
    await Promise.race([
      authReady,
      new Promise((_, reject) => setTimeout(() => reject(new Error('Auth check timeout')), 10000)),
    ])
  } catch (err) {
    console.error('Auth initialization failed:', err)
    // If auth check times out, allow navigation to public routes only
    if (to.meta.public || to.meta.requiresAuth === false) {
      next()
      return
    }
    // For protected routes, redirect to login
    if (!detectRedirectLoop('/login')) {
      next({ name: 'login', query: { reason: 'auth_timeout' } })
    } else {
      useGlobalErrorStore().setError({
        message: getErrorMessage(err) ?? 'Auth initialization failed',
        reason: 'auth_timeout',
        source: 'router:beforeEach',
        stack: err instanceof Error ? (err.stack ?? '') : '',
      })
      next(false)
    }
    return
  }

  const { isAuthenticated, isAdmin } = useAuth()
  const requiresAuth = to.meta.requiresAuth !== false // Default to true
  const requiresAdminAccess = to.meta.requiresAdmin === true
  const isPublicRoute = to.meta.public === true

  const authenticated = isAuthenticated.value

  if (requiresAuth && !authenticated) {
    // Check for redirect loop before redirecting
    const targetPath = `/login?redirect=${encodeURIComponent(to.fullPath)}&reason=auth_required`
    if (detectRedirectLoop(targetPath)) {
      console.error('🛑 Breaking redirect loop - staying on current page')
      useGlobalErrorStore().setError({
        message: 'Redirect loop detected while resolving authentication',
        reason: 'redirect_loop',
        source: 'router:beforeEach',
        statusCode: 508,
      })
      next(false)
      return
    }

    // Guest users: redirect to chat with feature-gate modal instead of login
    const guestStore = useGuestStore()
    const hasStoredGuestSession =
      !guestStore.initialized && !!localStorage.getItem(GUEST_STORAGE_KEY)
    if (guestStore.isGuestMode || hasStoredGuestSession) {
      const featureKey = mapPathToFeatureKey(to.path)
      next({ name: 'chat', query: { restricted: featureKey } })
      return
    }

    if (import.meta.env.DEV) console.debug('🔒 Redirecting unauthenticated user to login')
    next({
      name: 'login',
      query: { redirect: to.fullPath, reason: 'auth_required' },
    })
  } else if (requiresAdminAccess && !isAdmin.value) {
    // Admin route without admin privileges
    next(resolveDefaultRoute())
  } else if (to.name === 'chat' && !authenticated && !useGuestStore().isGuestMode) {
    // A branded deployment may configure a custom public logged-out landing
    // (route name or free-form path): send first-time, not-signed-in visitors
    // there instead of the home/chat entry. Default-safe: when nothing valid is
    // configured `landing` is null and we fall through, so guest/anonymous chat
    // behaves exactly as before. The path check prevents a self-redirect loop.
    const landing = resolveLandingTarget()
    if (landing && targetPath(landing) !== to.path) {
      next(landing)
    } else {
      next()
    }
  } else if (isPublicRoute && isAuthenticated.value && to.name === 'login') {
    // Already logged in, redirect to home (but check for loops)
    const home = resolveDefaultRoute()
    if (from.path === targetPath(home) || detectRedirectLoop('/')) {
      // Prevent ping-pong between login and the home route
      next()
      return
    }
    next(home)
  } else {
    next()
  }
})

// Global error handler for lazy-loaded components
const CHUNK_RELOAD_FLAG = 'synaplan:chunk-reload-at'
router.onError((error, to) => {
  console.error('Router error:', error)

  // Chunk load failure (stale client after a deployment or dev-server
  // restart): the app shell can no longer import the route's lazy chunk.
  // Reload INTO the target route — a plain reload() would silently drop the
  // navigation and leave the user on the old page, which reads as "the
  // button does nothing". Message text differs per browser engine.
  const message = String((error as Error)?.message ?? '')
  const isChunkLoadFailure =
    message.includes('Failed to fetch dynamically imported module') || // Chromium
    message.includes('Importing a module script failed') || // WebKit / iOS Safari
    message.includes('error loading dynamically imported module') // Firefox

  if (isChunkLoadFailure) {
    // One forced reload per 10s — if the chunk is still broken after a fresh
    // load, fall through to the error screen instead of reload-looping.
    const lastReload = Number(sessionStorage.getItem(CHUNK_RELOAD_FLAG) ?? 0)
    if (Date.now() - lastReload > 10_000) {
      sessionStorage.setItem(CHUNK_RELOAD_FLAG, String(Date.now()))
      window.location.href = to?.fullPath ?? window.location.href
      return
    }
  }

  useGlobalErrorStore().setError({
    message: error.message,
    reason: 'router_navigation',
    source: 'router:onError',
    stack: error.stack ?? '',
  })
})

export default router
