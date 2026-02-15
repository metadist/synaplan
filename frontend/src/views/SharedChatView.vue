<template>
  <div data-testid="shared-chat-root" class="shared-chat-root">
    <div class="min-h-screen bg-chat" data-testid="page-shared-chat">
    <!-- Header -->
    <header
      class="sticky top-0 z-10 backdrop-blur-lg bg-surface/80 border-b border-light-border dark:border-dark-border"
      data-testid="section-header"
    >
      <div class="max-w-4xl mx-auto px-3 sm:px-4 py-3 sm:py-4">
        <!-- Mobile: Two-row layout, Desktop: Single-row layout -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <!-- Logo & Title -->
          <div class="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
            <svg
              class="w-7 h-7 sm:w-8 sm:h-8 text-[var(--brand)] flex-shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
              />
            </svg>
            <div class="min-w-0 flex-1">
              <h1 class="text-base sm:text-xl font-bold txt-primary truncate">
                {{ chat?.title || $t('shared.title') }}
              </h1>
              <p class="text-xs sm:text-sm txt-secondary">
                {{ $t('shared.subtitle') }}
              </p>
            </div>
          </div>

          <!-- Actions - New row on mobile, same row on desktop -->
          <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Language Selector -->
            <div class="relative">
              <select
                v-model="currentLang"
                class="appearance-none px-3 py-2 rounded-lg surface-chip txt-primary text-sm font-medium cursor-pointer focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                @change="switchLanguage"
              >
                <option value="de">DE</option>
                <option value="en">EN</option>
                <option value="es">ES</option>
                <option value="tr">TR</option>
              </select>
            </div>
            <a
              href="https://synaplan.com"
              target="_blank"
              class="btn-primary px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
              data-testid="btn-try-synaplan"
            >
              {{ $t('shared.trySynaplan') }}
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-20" data-testid="state-loading">
      <div class="text-center">
        <div
          class="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--brand)] mx-auto mb-4"
        ></div>
        <p class="txt-secondary">{{ $t('shared.loading') }}</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="max-w-4xl mx-auto px-4 py-20" data-testid="state-error">
      <div class="text-center">
        <svg
          class="w-16 h-16 text-red-500 mx-auto mb-4"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
        <h2 class="text-2xl font-bold txt-primary mb-2">{{ $t('shared.notFound') }}</h2>
        <p class="txt-secondary mb-6">{{ $t('shared.notFoundDesc') }}</p>
        <a href="https://synaplan.com" class="btn-primary px-6 py-3 rounded-lg inline-block">
          {{ $t('shared.visitSynaplan') }}
        </a>
      </div>
    </div>

    <!-- Chat Content -->
    <main v-else class="max-w-4xl mx-auto px-4 py-8" data-testid="section-chat-content">
      <!-- Chat Info Banner -->
      <div
        class="mb-8 p-6 rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20"
        data-testid="section-info-banner"
      >
        <div class="flex items-start gap-4">
          <svg
            class="w-6 h-6 text-[var(--brand)] mt-1 flex-shrink-0"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <div class="flex-1">
            <h3 class="font-semibold txt-primary mb-1">{{ $t('shared.infoTitle') }}</h3>
            <p class="text-sm txt-secondary">
              {{ $t('shared.infoDesc') }}
              <a
                href="https://synaplan.com"
                target="_blank"
                class="text-[var(--brand)] hover:underline"
              >
                {{ $t('shared.createOwn') }}
              </a>
            </p>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <div data-testid="shared-message-list" class="space-y-6">
        <div
          v-for="message in messages"
          :key="message.id"
          class="flex gap-4"
          :class="message.direction === 'IN' ? 'flex-row' : 'flex-row-reverse'"
          data-testid="item-message"
        >
          <!-- Avatar -->
          <div class="flex-shrink-0">
            <div
              class="w-10 h-10 rounded-full flex items-center justify-center"
              :class="
                message.direction === 'IN' ? 'bg-gray-200 dark:bg-gray-700' : 'bg-[var(--brand)]'
              "
            >
              <svg
                v-if="message.direction === 'IN'"
                class="w-6 h-6 txt-secondary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                />
              </svg>
              <svg
                v-else
                class="w-6 h-6 text-white"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                />
              </svg>
            </div>
          </div>

          <!-- Message Content -->
          <div
            class="flex-1 max-w-2xl p-4 rounded-lg"
            :class="
              message.direction === 'IN'
                ? 'surface-card'
                : 'bg-[var(--brand)]/10 border border-[var(--brand)]/20'
            "
          >
            <div class="flex items-baseline justify-between mb-2">
              <span class="font-semibold txt-primary text-sm">
                {{ message.direction === 'IN' ? $t('shared.user') : $t('shared.assistant') }}
              </span>
              <span class="text-xs txt-secondary">
                {{ formatDate(message.timestamp) }}
              </span>
            </div>
            <!-- Message parts (text, code blocks) -->
            <template v-for="(part, partIndex) in parseMessageParts(message)" :key="partIndex">
              <MessageCode
                v-if="part.type === 'code'"
                :content="part.content"
                :language="part.language"
              />
              <MessageText
                v-else-if="part.type === 'text'"
                :content="part.content"
                :is-streaming="false"
                :readonly="true"
              />
            </template>

            <!-- File attachments (images, videos) -->
            <div v-if="message.file" class="mt-3">
              <MessageImage
                v-if="message.file.type === 'image'"
                :url="message.file.path"
                :alt="message.text || 'Generated image'"
              />
              <MessageVideo v-if="message.file.type === 'video'" :url="message.file.path" />
            </div>

            <!-- Topic Badge -->
            <div v-if="message.topic" class="mt-3 flex items-center gap-2 flex-wrap">
              <span
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                style="background-color: #1e40af; color: white"
              >
                {{ message.topic }}
              </span>
              <span
                v-if="message.language"
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                style="background-color: #4b5563; color: white"
              >
                {{ message.language }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer CTA -->
      <div
        class="mt-12 p-8 rounded-lg bg-gradient-to-r from-[var(--brand)]/10 to-purple-500/10 border border-[var(--brand)]/20 text-center"
      >
        <h3 class="text-2xl font-bold txt-primary mb-3">
          {{ $t('shared.ctaTitle') }}
        </h3>
        <p class="txt-secondary mb-6 max-w-2xl mx-auto">
          {{ $t('shared.ctaDesc') }}
        </p>
        <div class="flex gap-4 justify-center">
          <a
            href="https://synaplan.com/register"
            class="btn-primary px-6 py-3 rounded-lg font-medium inline-block"
          >
            {{ $t('shared.getStarted') }}
          </a>
          <a
            href="https://synaplan.com"
            class="px-6 py-3 rounded-lg border border-light-border dark:border-dark-border hover-surface transition-colors font-medium inline-block"
          >
            {{ $t('shared.learnMore') }}
          </a>
        </div>
      </div>
    </main>

    <!-- Footer -->
    <footer class="mt-20 border-t border-light-border dark:border-dark-border py-8">
      <div class="max-w-4xl mx-auto px-4 text-center txt-secondary text-sm">
        <p>
          {{ $t('shared.poweredBy') }}
          <a
            href="https://synaplan.com"
            target="_blank"
            class="text-[var(--brand)] hover:underline font-medium"
          >
            Synaplan AI
          </a>
          ·
          <a href="https://synaplan.com/privacy" target="_blank" class="hover:underline">{{
            $t('shared.privacy')
          }}</a>
          ·
          <a href="https://synaplan.com/terms" target="_blank" class="hover:underline">{{
            $t('shared.terms')
          }}</a>
        </p>
      </div>
    </footer>

    <!-- GDPR Cookie Consent Banner -->
    <CookieConsent @consent="handleCookieConsent" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MessageImage from '../components/MessageImage.vue'
import MessageVideo from '../components/MessageVideo.vue'
import MessageCode from '../components/MessageCode.vue'
import MessageText from '../components/MessageText.vue'
import CookieConsent from '../components/CookieConsent.vue'
import { type CookieConsent as CookieConsentType } from '../composables/useCookieConsent'
import { useGoogleTag } from '../composables/useGoogleTag'
import { httpClient } from '@/services/api/httpClient'
import { supportedLanguages, type SupportedLanguage } from '@/i18n'
import { parseAIResponse } from '@/utils/responseParser'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const { injectGoogleTag } = useGoogleTag()

const loading = ref(true)
const error = ref(false)
const currentLang = ref<string>('en')

interface Message {
  id: number
  text: string
  direction: 'IN' | 'OUT'
  timestamp: number
  topic?: string
  language?: string
  provider?: string
  file?: {
    path: string
    type: string
  }
}

interface Chat {
  title: string
  createdAt: string
}

const chat = ref<Chat | null>(null)
const messages = ref<Message[]>([])

// Get token from route (works with both /shared/:token and /shared/:lang/:token)
const token = computed(() => {
  return (route.params.token as string) || ''
})

// Initialize language from URL parameter
const initLanguage = () => {
  const langParam = route.params.lang as string | undefined

  if (langParam && supportedLanguages.includes(langParam as SupportedLanguage)) {
    currentLang.value = langParam
    locale.value = langParam
  } else {
    // Default to English for backwards compatibility
    currentLang.value = 'en'
    locale.value = 'en'
  }
}

// Switch language and update URL
const switchLanguage = () => {
  locale.value = currentLang.value

  // Update URL to include language
  const newPath = `/shared/${currentLang.value}/${token.value}`
  router.replace(newPath)

  // Update meta tags
  updateMetaTags()
}

// Handle cookie consent - inject Google Tag only after user accepts
const handleCookieConsent = (consent: CookieConsentType) => {
  if (consent.analytics) {
    injectGoogleTag()
  }
}

const pageTitle = computed(() => {
  if (!chat.value) return `${t('shared.title')} | Synaplan AI`
  return `${chat.value.title} | ${t('shared.title')} | Synaplan AI`
})

const pageDescription = computed(() => {
  if (!messages.value.length) return t('shared.subtitle')
  const firstMessage = messages.value.find((m) => m.direction === 'IN')?.text || ''
  return firstMessage.substring(0, 160) + (firstMessage.length > 160 ? '...' : '')
})

const currentUrl = computed(() => {
  return window.location.href
})

const baseUrl = computed(() => {
  return window.location.origin
})

// Update document title and meta tags including hreflang
const updateMetaTags = () => {
  // Title
  document.title = pageTitle.value

  // Meta Description
  updateOrCreateMeta('name', 'description', pageDescription.value)

  // Open Graph
  updateOrCreateMeta('property', 'og:type', 'website')
  updateOrCreateMeta('property', 'og:url', currentUrl.value)
  updateOrCreateMeta('property', 'og:title', pageTitle.value)
  updateOrCreateMeta('property', 'og:description', pageDescription.value)
  updateOrCreateMeta('property', 'og:site_name', 'Synaplan AI')
  updateOrCreateMeta('property', 'og:locale', currentLang.value)

  // Twitter
  updateOrCreateMeta('property', 'twitter:card', 'summary_large_image')
  updateOrCreateMeta('property', 'twitter:url', currentUrl.value)
  updateOrCreateMeta('property', 'twitter:title', pageTitle.value)
  updateOrCreateMeta('property', 'twitter:description', pageDescription.value)

  // SEO
  updateOrCreateMeta('name', 'robots', 'index, follow')
  updateOrCreateMeta('name', 'googlebot', 'index, follow')

  // Canonical - always point to language-specific URL
  const canonicalUrl = `${baseUrl.value}/shared/${currentLang.value}/${token.value}`
  updateOrCreateLink('canonical', canonicalUrl)

  // hreflang tags for SEO - tell search engines about all language variants
  updateHreflangTags()

  // JSON-LD Structured Data
  if (chat.value && messages.value.length > 0) {
    updateStructuredData()
  }
}

// Add hreflang tags for all supported languages
const updateHreflangTags = () => {
  // Remove existing hreflang tags
  document.querySelectorAll('link[hreflang]').forEach((el) => el.remove())

  // Add hreflang for each supported language
  supportedLanguages.forEach((lang) => {
    const link = document.createElement('link')
    link.rel = 'alternate'
    link.hreflang = lang
    link.href = `${baseUrl.value}/shared/${lang}/${token.value}`
    document.head.appendChild(link)
  })

  // Add x-default (points to English)
  const defaultLink = document.createElement('link')
  defaultLink.rel = 'alternate'
  defaultLink.hreflang = 'x-default'
  defaultLink.href = `${baseUrl.value}/shared/en/${token.value}`
  document.head.appendChild(defaultLink)
}

const updateOrCreateMeta = (attr: string, key: string, content: string) => {
  let element = document.querySelector(`meta[${attr}="${key}"]`)
  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attr, key)
    document.head.appendChild(element)
  }
  element.setAttribute('content', content)
}

const updateOrCreateLink = (rel: string, href: string) => {
  let element = document.querySelector(`link[rel="${rel}"]`)
  if (!element) {
    element = document.createElement('link')
    element.setAttribute('rel', rel)
    document.head.appendChild(element)
  }
  element.setAttribute('href', href)
}

const updateStructuredData = () => {
  let script = document.querySelector('script[type="application/ld+json"]')
  if (!script) {
    script = document.createElement('script')
    script.setAttribute('type', 'application/ld+json')
    document.head.appendChild(script)
  }

  script.textContent = JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'Conversation',
    name: chat.value?.title,
    description: pageDescription.value,
    datePublished: chat.value?.createdAt,
    inLanguage: currentLang.value,
    author: {
      '@type': 'Organization',
      name: 'Synaplan AI',
    },
    commentCount: messages.value.length,
  })
}

// Watch for changes and update meta tags
watch([chat, messages, pageTitle, pageDescription, currentLang], () => {
  if (chat.value) {
    updateMetaTags()
  }
})

onMounted(async () => {
  // Initialize language from URL
  initLanguage()

  if (!token.value) {
    error.value = true
    loading.value = false
    return
  }

  try {
    const data = await httpClient<any>(`/api/v1/chats/shared/${token.value}`, {
      skipAuth: true,
    })

    if (!data.success) {
      throw new Error('Chat not found or not shared')
    }

    chat.value = data.chat
    messages.value = data.messages || []
  } catch (err) {
    console.error('Failed to load shared chat:', err)
    error.value = true
  } finally {
    loading.value = false
  }
})

const formatDate = (timestamp: number): string => {
  return new Date(timestamp * 1000).toLocaleString(currentLang.value)
}

interface MessagePart {
  type: 'text' | 'code'
  content: string
  language?: string
}

/**
 * Parse message text into parts (text and code blocks).
 * Uses the same parser as the main chat for consistent rendering.
 */
const parseMessageParts = (message: Message): MessagePart[] => {
  if (!message.text) return []

  // For user messages, just return as text
  if (message.direction === 'IN') {
    return [{ type: 'text', content: message.text }]
  }

  // For assistant messages, parse into parts
  const parsed = parseAIResponse(message.text)
  return parsed.parts
    .filter((part) => part.type === 'text' || part.type === 'code')
    .map((part) => ({
      type: part.type as 'text' | 'code',
      content: part.content,
      language: part.language,
    }))
    .filter((part) => part.content.trim())
}
</script>

<style scoped>
/* No additional styles needed - MessageText and MessageCode components have their own styles */
</style>
