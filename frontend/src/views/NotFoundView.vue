<template>
  <div
    class="min-h-screen flex items-center justify-center bg-app px-6"
    data-testid="page-not-found"
  >
    <div class="max-w-2xl w-full text-center" data-testid="section-card">
      <!-- Animated 404 -->
      <div class="relative mb-8" data-testid="section-illustration">
        <h1
          class="text-[150px] md:text-[200px] font-bold txt-primary opacity-10 select-none leading-none"
        >
          404
        </h1>
        <div class="absolute inset-0 flex items-center justify-center">
          <div class="relative">
            <!-- Floating icons animation -->
            <div class="absolute -top-8 -left-8 animate-bounce-slow">
              <div
                class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-500/20 to-purple-500/20 flex items-center justify-center"
              >
                <QuestionMarkCircleIcon class="w-8 h-8 text-blue-500" />
              </div>
            </div>
            <div class="absolute -top-4 -right-12 animate-float" style="animation-delay: 0.5s">
              <div
                class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500/20 to-pink-500/20 flex items-center justify-center"
              >
                <MagnifyingGlassIcon class="w-6 h-6 text-purple-500" />
              </div>
            </div>
            <div class="absolute -bottom-6 left-4 animate-float" style="animation-delay: 1s">
              <div
                class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-500/20 to-blue-500/20 flex items-center justify-center"
              >
                <MapIcon class="w-5 h-5 text-cyan-500" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="space-y-4 mb-8" data-testid="section-content">
        <h2 class="text-3xl md:text-4xl font-bold txt-primary">
          {{ $t('notFound.title') }}
        </h2>
        <p class="text-lg txt-secondary max-w-lg mx-auto">
          {{ $t('notFound.description') }}
        </p>
      </div>

      <!-- Actions -->
      <div
        class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-12"
        data-testid="section-actions"
      >
        <router-link
          to="/"
          class="btn-primary px-8 py-3 rounded-lg font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-home"
        >
          <HomeIcon class="w-5 h-5" />
          {{ $t('notFound.goHome') }}
        </router-link>
        <button
          class="px-8 py-3 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-back"
          @click="goBack"
        >
          <ArrowLeftIcon class="w-5 h-5" />
          {{ $t('notFound.goBack') }}
        </button>
      </div>

      <!-- Quick Links -->
      <div class="surface-card p-6 rounded-xl" data-testid="section-links">
        <h3 class="text-sm font-semibold txt-secondary uppercase tracking-wider mb-4">
          {{ $t('notFound.quickLinks') }}
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <router-link
            to="/"
            class="p-4 rounded-lg hover-surface transition-colors group"
            data-testid="item-quick-link"
          >
            <ChatBubbleLeftRightIcon
              class="w-6 h-6 mx-auto mb-2 txt-secondary group-hover:text-[var(--brand)] transition-colors"
            />
            <span class="text-sm txt-primary">{{ $t('nav.chat') }}</span>
          </router-link>
          <router-link
            to="/tools/chat-widget"
            class="p-4 rounded-lg hover-surface transition-colors group"
          >
            <Cog6ToothIcon
              class="w-6 h-6 mx-auto mb-2 txt-secondary group-hover:text-[var(--brand)] transition-colors"
            />
            <span class="text-sm txt-primary">{{ $t('nav.tools') }}</span>
          </router-link>
          <router-link to="/files" class="p-4 rounded-lg hover-surface transition-colors group">
            <FolderIcon
              class="w-6 h-6 mx-auto mb-2 txt-secondary group-hover:text-[var(--brand)] transition-colors"
            />
            <span class="text-sm txt-primary">{{ $t('nav.files') }}</span>
          </router-link>
          <router-link to="/profile" class="p-4 rounded-lg hover-surface transition-colors group">
            <UserCircleIcon
              class="w-6 h-6 mx-auto mb-2 txt-secondary group-hover:text-[var(--brand)] transition-colors"
            />
            <span class="text-sm txt-primary">{{ $t('nav.profile') }}</span>
          </router-link>
        </div>
      </div>

      <!-- Footer hint -->
      <p class="text-xs txt-secondary mt-8">
        {{ $t('notFound.hint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import {
  HomeIcon,
  ArrowLeftIcon,
  QuestionMarkCircleIcon,
  MagnifyingGlassIcon,
  MapIcon,
  ChatBubbleLeftRightIcon,
  Cog6ToothIcon,
  FolderIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()

const goBack = () => {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/')
  }
}
</script>

<style scoped>
@keyframes bounce-slow {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-20px);
  }
}

@keyframes float {
  0%,
  100% {
    transform: translateY(0) rotate(0deg);
  }
  50% {
    transform: translateY(-15px) rotate(5deg);
  }
}

.animate-bounce-slow {
  animation: bounce-slow 3s ease-in-out infinite;
}

.animate-float {
  animation: float 4s ease-in-out infinite;
}
</style>
