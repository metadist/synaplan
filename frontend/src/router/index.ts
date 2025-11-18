import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import LoadingView from '@/views/LoadingView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    // Public routes (no auth required)
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('../views/RegisterView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('../views/ForgotPasswordView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('../views/ResetPasswordView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('../views/VerifyEmailView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/verify-email-callback',
      name: 'verify-email-callback',
      component: () => import('../views/VerifyEmailCallbackView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/email-verified',
      name: 'email-verified',
      component: () => import('../views/EmailVerifiedView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    {
      path: '/shared/:token',
      name: 'shared-chat',
      component: () => import('../views/SharedChatView.vue'),
      meta: { requiresAuth: false, public: true }
    },
    
    // Error pages (always accessible)
    {
      path: '/error',
      name: 'error',
      component: () => import('../views/ErrorView.vue'),
      meta: { requiresAuth: false }
    },
    {
      path: '/loading',
      name: 'loading',
      component: LoadingView,
      meta: { requiresAuth: false }
    },
    
    // Protected routes (require authentication)
    {
      path: '/',
      name: 'chat',
      component: () => import('../views/ChatView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/tools',
      redirect: '/tools/introduction',
      meta: { requiresAuth: true }
    },
        {
          path: '/tools/introduction',
          name: 'tools-introduction',
          component: () => import('../views/ToolsView.vue'),
          meta: { requiresAuth: true, helpId: 'tools.introduction' }
        },
        {
          path: '/tools/chat-widget',
          name: 'tools-chat-widget',
          component: () => import('../views/WidgetsView.vue'),
          meta: { requiresAuth: true, helpId: 'tools.chatWidget' }
        },
        {
          path: '/tools/doc-summary',
          name: 'tools-doc-summary',
          component: () => import('../views/ToolsView.vue'),
          meta: { requiresAuth: true, helpId: 'tools.docSummary' }
        },
        {
          path: '/tools/mail-handler',
          name: 'tools-mail-handler',
          component: () => import('../views/ToolsView.vue'),
          meta: { requiresAuth: true, helpId: 'tools.mailHandler' }
        },
    {
      path: '/files',
      name: 'files',
      component: () => import('../views/FilesView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/rag',
      name: 'rag',
      component: () => import('../views/RagSearchView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/config',
      redirect: '/config/inbound',
      meta: { requiresAuth: true }
    },
    {
      path: '/config/inbound',
      name: 'config-inbound',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/config/ai-models',
      name: 'config-ai-models',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/config/task-prompts',
      name: 'config-task-prompts',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/config/sorting-prompt',
      name: 'config-sorting-prompt',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/config/api-keys',
      name: 'config-api-keys',
      component: () => import('../views/ConfigView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/statistics',
      name: 'statistics',
      component: () => import('../views/StatisticsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/settings',
      name: 'settings',
      component: () => import('../views/SettingsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/testv',
      name: 'test',
      component: () => import('../views/TestView.vue'),
      meta: { requiresAuth: false } // Test page accessible without auth
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/ProfileView.vue'),
      meta: { requiresAuth: true }
    },
    // 404 - Must be last
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
      meta: { requiresAuth: false }
    },
  ],
})

// Global navigation guard for authentication
router.beforeEach((to, _from, next) => {
  const { isAuthenticated } = useAuth()
  const requiresAuth = to.meta.requiresAuth !== false // Default to true
  const isPublicRoute = to.meta.public === true
  const autoLoginEnabled = import.meta.env.VITE_AUTO_LOGIN_DEV === 'true'

  // Auto-login in development if enabled
  if (autoLoginEnabled && !isAuthenticated.value && requiresAuth) {
    localStorage.setItem('auth_token', 'dev-token')
    const { checkAuth } = useAuth()
    checkAuth()
  }

  if (requiresAuth && !isAuthenticated.value) {
    // Redirect to login, save intended destination
    next({
      name: 'login',
      query: { redirect: to.fullPath }
    })
  } else if (isPublicRoute && isAuthenticated.value && to.name === 'login' && !autoLoginEnabled) {
    // Already logged in, redirect to home (but not in dev mode with auto-login)
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
      params: { error: error.message }
    })
  }
})

export default router
