import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { useConfigStore } from '@/stores/config'
import { authReady } from '@/stores/auth'
import { i18n } from '@/i18n'
import LoadingView from '@/views/LoadingView.vue'

export const APP_NAME = 'Synaplan'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    // Public routes (no auth required)
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.login' },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('../views/RegisterView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.register' },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('../views/ForgotPasswordView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.forgotPassword' },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('../views/ResetPasswordView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.resetPassword' },
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('../views/VerifyEmailView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.verifyEmail' },
    },
    {
      path: '/verify-email-callback',
      name: 'verify-email-callback',
      component: () => import('../views/VerifyEmailCallbackView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.verifyEmail' },
    },
    {
      path: '/email-verified',
      name: 'email-verified',
      component: () => import('../views/EmailVerifiedView.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.emailVerified' },
    },
    {
      path: '/auth/callback',
      name: 'oauth-callback',
      component: () => import('../components/auth/OAuthCallback.vue'),
      meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.login' },
    },
    // Shared chat with optional language parameter for SEO
    // /shared/de/abc123 -> German UI
    // /shared/en/abc123 -> English UI
    // /shared/abc123 -> Default to English (backwards compatible)
    // Note: SharedChatView sets its own dynamic title based on chat content
    {
      path: '/shared/:lang([a-z]{2})/:token',
      name: 'shared-chat-lang',
      component: () => import('../views/SharedChatView.vue'),
      meta: { requiresAuth: false, public: true },
    },
    {
      path: '/shared/:token',
      name: 'shared-chat',
      component: () => import('../views/SharedChatView.vue'),
      meta: { requiresAuth: false, public: true },
    },

    // Error pages (always accessible)
    {
      path: '/error',
      name: 'error',
      component: () => import('../views/ErrorView.vue'),
      meta: { requiresAuth: false, titleKey: 'pageTitles.error' },
    },
    {
      path: '/loading',
      name: 'loading',
      component: LoadingView,
      meta: { requiresAuth: false },
    },

    // Protected routes (require authentication)
    {
      path: '/',
      name: 'chat',
      component: () => import('../views/ChatView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.chat' },
    },
    {
      path: '/tools',
      redirect: '/tools/introduction',
      meta: { requiresAuth: true },
    },
    {
      path: '/tools/introduction',
      name: 'tools-introduction',
      component: () => import('../views/ToolsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.introduction', titleKey: 'pageTitles.tools' },
    },
    {
      path: '/tools/chat-widget',
      name: 'tools-chat-widget',
      component: () => import('../views/WidgetsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.chatWidget', titleKey: 'pageTitles.chatWidget' },
    },
    {
      path: '/tools/doc-summary',
      name: 'tools-doc-summary',
      component: () => import('../views/ToolsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.docSummary', titleKey: 'pageTitles.docSummary' },
    },
    {
      path: '/tools/mail-handler',
      name: 'tools-mail-handler',
      component: () => import('../views/ToolsView.vue'),
      meta: { requiresAuth: true, helpId: 'tools.mailHandler', titleKey: 'pageTitles.mailHandler' },
    },
    {
      path: '/plugins/:pluginName',
      name: 'plugin-view',
      component: () => import('../views/PluginView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.plugins' },
    },
    {
      path: '/files',
      name: 'files',
      component: () => import('../views/FilesView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.files' },
    },
    {
      path: '/memories',
      name: 'memories',
      component: () => import('../views/MemoriesView.vue'),
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
      path: '/rag',
      name: 'rag',
      component: () => import('../views/RagSearchView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.ragSearch' },
    },
    {
      path: '/config',
      redirect: '/config/inbound',
      meta: { requiresAuth: true },
    },
    {
      path: '/config/inbound',
      name: 'config-inbound',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configInbound' },
    },
    {
      path: '/config/ai-models',
      name: 'config-ai-models',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configAiModels' },
    },
    {
      path: '/config/task-prompts',
      name: 'config-task-prompts',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configTaskPrompts' },
    },
    {
      path: '/config/sorting-prompt',
      name: 'config-sorting-prompt',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configSortingPrompt' },
    },
    {
      path: '/config/api-keys',
      name: 'config-api-keys',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configApiKeys' },
    },
    {
      path: '/config/api-documentation',
      name: 'config-api-documentation',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.configApiDocs' },
    },
    {
      path: '/statistics',
      name: 'statistics',
      component: () => import('../views/StatisticsView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.statistics' },
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('../views/SettingsView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.settings' },
    },
    {
      path: '/testv',
      name: 'test',
      component: () => import('../views/TestView.vue'),
      meta: { requiresAuth: false }, // Test page accessible without auth
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/ProfileView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.profile' },
    },
    {
      path: '/admin',
      name: 'admin',
      component: () => import('../views/AdminView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.admin' },
    },
    {
      path: '/admin/features',
      name: 'admin-features',
      component: () => import('../views/FeatureStatusView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.adminFeatures' },
    },
    {
      path: '/admin/config',
      name: 'admin-config',
      component: () => import('../views/AdminConfigView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true, titleKey: 'pageTitles.adminConfig' },
    },
    {
      path: '/subscription',
      name: 'subscription',
      component: () => import('../views/SubscriptionView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscription' },
    },
    {
      path: '/subscription/success',
      name: 'subscription-success',
      component: () => import('../views/SubscriptionSuccessView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscriptionSuccess' },
    },
    {
      path: '/subscription/cancel',
      name: 'subscription-cancel',
      component: () => import('../views/SubscriptionCancelView.vue'),
      meta: { requiresAuth: true, titleKey: 'pageTitles.subscriptionCancel' },
    },
    // 404 - Must be last
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
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
    document.title = `${pageTitle} | ${APP_NAME}`
  } else {
    // Fallback for routes without titleKey (e.g., shared chat handles its own title)
    document.title = APP_NAME
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

// Global navigation guard for authentication
// With cookie-based auth, we wait for auth check then verify session
router.beforeEach(async (to, from, next) => {
  // Skip guard for error page to prevent loops
  if (to.name === 'error') {
    next()
    return
  }

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
      next({ name: 'error' })
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
      // Break the loop by going to error page or just proceeding
      next({ name: 'error', query: { reason: 'redirect_loop' } })
      return
    }

    console.warn('🔒 Protected route accessed without auth - redirecting to login')
    next({
      name: 'login',
      query: { redirect: to.fullPath, reason: 'auth_required' },
    })
  } else if (requiresAdminAccess && !isAdmin.value) {
    // Admin route without admin privileges
    next({ name: 'chat' })
  } else if (isPublicRoute && isAuthenticated.value && to.name === 'login') {
    // Already logged in, redirect to home (but check for loops)
    if (from.name === 'chat' || detectRedirectLoop('/')) {
      // Prevent ping-pong between login and chat
      next()
      return
    }
    next({ name: 'chat' })
  } else {
    next()
  }
})

// Global error handler for lazy-loaded components
router.onError((error) => {
  console.error('Router error:', error)

  // Handle chunk load failures (e.g., after deployment)
  if (error.message.includes('Failed to fetch dynamically imported module')) {
    window.location.reload()
  } else {
    router.push({
      name: 'error',
      params: { error: error.message },
    })
  }
})

export default router
