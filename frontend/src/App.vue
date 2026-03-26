<template>
  <div class="min-h-screen bg-app txt-primary text-[16px] leading-6" :data-build="buildInfo">
    <ErrorBoundary>
      <Suspense>
        <template #default>
          <router-view />
        </template>
        <template #fallback>
          <LoadingView />
        </template>
      </Suspense>
    </ErrorBoundary>
    <NotificationContainer />
    <Dialog />
    <CookieConsent @consent="handleCookieConsent" />
  </div>
</template>

<script setup lang="ts">
import { watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useTheme } from './composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { APP_NAME } from '@/router'
import NotificationContainer from '@/components/NotificationContainer.vue'
import Dialog from '@/components/Dialog.vue'
import ErrorBoundary from '@/components/ErrorBoundary.vue'
import LoadingView from '@/views/LoadingView.vue'
import CookieConsent from '@/components/CookieConsent.vue'
import { useGoogleTag } from '@/composables/useGoogleTag'
import type { CookieConsent as CookieConsentType } from '@/composables/useCookieConsent'

useTheme()

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js')
}

const { injectGoogleTag, trackPageView } = useGoogleTag()

const handleCookieConsent = (consent: CookieConsentType) => {
  if (consent.analytics) {
    injectGoogleTag()
    // Track initial page view if just consented
    setTimeout(() => {
      trackPageView(route.path)
    }, 500)
  }
}

// Build info for debugging (visible in HTML source as comment)
const configStore = useConfigStore()
const buildInfo = computed(() => {
  const b = configStore.build
  return `v${b.version} @ ${b.ip}`
})

// SECURITY: Clean up any legacy localStorage entries from before cookie-based auth
// These should NEVER exist - if they do, they're from old code and must be removed
const legacyKeys = ['auth_token', 'auth_user', 'refresh_token', 'dev-token']
legacyKeys.forEach((key) => {
  if (localStorage.getItem(key)) {
    console.warn(`🧹 Removing legacy localStorage key: ${key}`)
    localStorage.removeItem(key)
  }
})

// Initialize auth state on app start (validates cookies via /me endpoint)
const authStore = useAuthStore()
authStore.checkAuth()

// Update page title when language changes
const route = useRoute()
const { t, locale } = useI18n()

watch(locale, () => {
  const titleKey = route.meta.titleKey as string | undefined
  if (titleKey) {
    const pageTitle = t(titleKey)
    document.title = `${pageTitle} | ${APP_NAME}`
  }
})

// Track page views when route changes
watch(
  () => route.path,
  (newPath) => {
    // Use setTimeout to ensure document.title has been updated by the router
    setTimeout(() => {
      trackPageView(newPath)
    }, 100)
  },
  { immediate: true }
)

// Google reCAPTCHA v3 Badge visibility control
// Only show badge on auth-related pages (login, register)
// Hide everywhere else for better UX (v3 works invisibly in background)

// Auth routes where reCAPTCHA badge should be visible
const authRoutes = ['login', 'register']

function updateRecaptchaBadgeVisibility() {
  const badge = document.querySelector('.grecaptcha-badge') as HTMLElement | null
  if (!badge) return

  const isAuthRoute = route.name ? authRoutes.includes(route.name.toString()) : false

  // Use CSS class instead of inline style to work with !important rules
  if (isAuthRoute) {
    badge.classList.add('visible')
  } else {
    badge.classList.remove('visible')
  }
}

// Watch route changes to show/hide badge
watch(() => route.name, updateRecaptchaBadgeVisibility, { immediate: true })

// Also watch for DOM changes in case badge loads after initial check
// (reCAPTCHA script loads asynchronously)
const observer = new MutationObserver((mutations) => {
  // Only check if a grecaptcha-badge was added
  const badgeAdded = mutations.some((mutation) => {
    return Array.from(mutation.addedNodes).some((node) => {
      if (node instanceof HTMLElement) {
        return (
          node.classList?.contains('grecaptcha-badge') || node.querySelector?.('.grecaptcha-badge')
        )
      }
      return false
    })
  })

  if (badgeAdded) {
    updateRecaptchaBadgeVisibility()
  }
})
observer.observe(document.body, { childList: true, subtree: true })
</script>
