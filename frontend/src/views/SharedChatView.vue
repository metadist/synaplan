<template>
  <div class="min-h-screen bg-chat" data-testid="page-shared-chat">

    <!-- Header -->
    <header class="sticky top-0 z-10 backdrop-blur-lg bg-surface/80 border-b border-light-border dark:border-dark-border" data-testid="section-header">
      <div class="max-w-4xl mx-auto px-4 py-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <svg class="w-8 h-8 text-[var(--brand)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            <div>
              <h1 class="text-xl font-bold txt-primary">{{ chat?.title || 'Shared Chat' }}</h1>
              <p class="text-sm txt-secondary">Shared conversation via Synaplan AI</p>
            </div>
          </div>
          <a 
            href="https://synaplan.com" 
            target="_blank"
            class="btn-primary px-4 py-2 rounded-lg text-sm font-medium"
            data-testid="btn-try-synaplan"
          >
            Try Synaplan
          </a>
        </div>
      </div>
    </header>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-20" data-testid="state-loading">
      <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--brand)] mx-auto mb-4"></div>
        <p class="txt-secondary">Loading chat...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="max-w-4xl mx-auto px-4 py-20" data-testid="state-error">
      <div class="text-center">
        <svg class="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <h2 class="text-2xl font-bold txt-primary mb-2">Chat Not Found</h2>
        <p class="txt-secondary mb-6">This chat doesn't exist or is no longer shared publicly.</p>
        <a 
          href="https://synaplan.com" 
          class="btn-primary px-6 py-3 rounded-lg inline-block"
        >
          Visit Synaplan
        </a>
      </div>
    </div>

    <!-- Chat Content -->
    <main v-else class="max-w-4xl mx-auto px-4 py-8" data-testid="section-chat-content">
      <!-- Chat Info Banner -->
      <div class="mb-8 p-6 rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20" data-testid="section-info-banner">
        <div class="flex items-start gap-4">
          <svg class="w-6 h-6 text-[var(--brand)] mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div class="flex-1">
            <h3 class="font-semibold txt-primary mb-1">This is a shared conversation</h3>
            <p class="text-sm txt-secondary">
              This chat was shared publicly and can be found by search engines like Google. 
              <a href="https://synaplan.com" target="_blank" class="text-[var(--brand)] hover:underline">
                Create your own AI-powered conversations
              </a>
            </p>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <div class="space-y-6" data-testid="section-messages">
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
              :class="message.direction === 'IN' 
                ? 'bg-gray-200 dark:bg-gray-700' 
                : 'bg-[var(--brand)]'
              "
            >
              <svg 
                v-if="message.direction === 'IN'" 
                class="w-6 h-6 txt-secondary" 
                fill="none" 
                stroke="currentColor" 
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <svg 
                v-else 
                class="w-6 h-6 text-white" 
                fill="none" 
                stroke="currentColor" 
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
            </div>
          </div>
          
          <!-- Message Content -->
          <div 
            class="flex-1 max-w-2xl p-4 rounded-lg"
            :class="message.direction === 'IN' 
              ? 'surface-card' 
              : 'bg-[var(--brand)]/10 border border-[var(--brand)]/20'
            "
          >
            <div class="flex items-baseline justify-between mb-2">
              <span class="font-semibold txt-primary text-sm">
                {{ message.direction === 'IN' ? 'User' : 'Synaplan AI' }}
              </span>
              <span class="text-xs txt-secondary">
                {{ formatDate(message.timestamp) }}
              </span>
            </div>
            <div class="txt-primary whitespace-pre-wrap break-words" v-html="formatMessageText(message.text)"></div>
            
            <!-- File attachments (images, videos) -->
            <div v-if="message.file" class="mt-3">
              <MessageImage 
                v-if="message.file.type === 'image'" 
                :url="message.file.path" 
                :alt="message.text || 'Generated image'" 
              />
              <MessageVideo 
                v-if="message.file.type === 'video'" 
                :url="message.file.path" 
              />
            </div>
            
            <!-- Topic Badge -->
            <div v-if="message.topic" class="mt-3 flex items-center gap-2 flex-wrap">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300">
                {{ message.topic }}
              </span>
              <span v-if="message.language" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 txt-secondary">
                {{ message.language }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer CTA -->
      <div class="mt-12 p-8 rounded-lg bg-gradient-to-r from-[var(--brand)]/10 to-purple-500/10 border border-[var(--brand)]/20 text-center">
        <h3 class="text-2xl font-bold txt-primary mb-3">Want to create your own AI conversations?</h3>
        <p class="txt-secondary mb-6 max-w-2xl mx-auto">
          Synaplan AI helps you build intelligent chatbots, automate workflows, and create amazing AI-powered experiences.
        </p>
        <div class="flex gap-4 justify-center">
          <a 
            href="https://synaplan.com/register" 
            class="btn-primary px-6 py-3 rounded-lg font-medium inline-block"
          >
            Get Started Free
          </a>
          <a 
            href="https://synaplan.com" 
            class="px-6 py-3 rounded-lg border border-light-border dark:border-dark-border hover-surface transition-colors font-medium inline-block"
          >
            Learn More
          </a>
        </div>
      </div>
    </main>

    <!-- Footer -->
    <footer class="mt-20 border-t border-light-border dark:border-dark-border py-8">
      <div class="max-w-4xl mx-auto px-4 text-center txt-secondary text-sm">
        <p>
          Powered by 
          <a href="https://synaplan.com" target="_blank" class="text-[var(--brand)] hover:underline font-medium">
            Synaplan AI
          </a>
          · 
          <a href="https://synaplan.com/privacy" target="_blank" class="hover:underline">Privacy</a>
          · 
          <a href="https://synaplan.com/terms" target="_blank" class="hover:underline">Terms</a>
        </p>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import MessageImage from '../components/MessageImage.vue'
import MessageVideo from '../components/MessageVideo.vue'

const route = useRoute()
const loading = ref(true)
const error = ref(false)

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

const pageTitle = computed(() => {
  if (!chat.value) return 'Shared Chat | Synaplan AI'
  return `${chat.value.title} | Shared Chat | Synaplan AI`
})

const pageDescription = computed(() => {
  if (!messages.value.length) return 'A shared conversation powered by Synaplan AI'
  const firstMessage = messages.value.find(m => m.direction === 'IN')?.text || ''
  return firstMessage.substring(0, 160) + (firstMessage.length > 160 ? '...' : '')
})

const currentUrl = computed(() => {
  return window.location.href
})

// Update document title and meta tags
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
  
  // Twitter
  updateOrCreateMeta('property', 'twitter:card', 'summary_large_image')
  updateOrCreateMeta('property', 'twitter:url', currentUrl.value)
  updateOrCreateMeta('property', 'twitter:title', pageTitle.value)
  updateOrCreateMeta('property', 'twitter:description', pageDescription.value)
  
  // SEO
  updateOrCreateMeta('name', 'robots', 'index, follow')
  updateOrCreateMeta('name', 'googlebot', 'index, follow')
  
  // Canonical
  updateOrCreateLink('canonical', currentUrl.value)
  
  // JSON-LD Structured Data
  if (chat.value && messages.value.length > 0) {
    updateStructuredData()
  }
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
    'name': chat.value?.title,
    'description': pageDescription.value,
    'datePublished': chat.value?.createdAt,
    'author': {
      '@type': 'Organization',
      'name': 'Synaplan AI'
    },
    'commentCount': messages.value.length
  })
}

// Watch for changes and update meta tags
watch([chat, messages, pageTitle, pageDescription], () => {
  if (chat.value) {
    updateMetaTags()
  }
})

onMounted(async () => {
  const token = route.params.token as string
  if (!token) {
    error.value = true
    loading.value = false
    return
  }

  try {
    const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
    const response = await fetch(`${API_BASE}/api/v1/chats/shared/${token}`)
    
    if (!response.ok) {
      throw new Error('Chat not found')
    }

    const data = await response.json()
    
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
  return new Date(timestamp * 1000).toLocaleString()
}

const formatMessageText = (text: string): string => {
  // Basic markdown-like formatting
  return text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/`(.*?)`/g, '<code class="px-1 py-0.5 rounded bg-black/10 dark:bg-white/10 font-mono text-sm">$1</code>')
    .replace(/\n/g, '<br />')
}
</script>
