<template>
  <div class="my-3" data-testid="media-loading-skeleton">
    <div
      class="relative w-full aspect-video surface-card overflow-hidden border border-light-border/30 dark:border-dark-border/20"
    >
      <!-- Shimmer gradient overlay -->
      <div class="absolute inset-0 media-shimmer" />

      <!-- Blurred placeholder background -->
      <div class="absolute inset-0 media-blur-bg" />

      <!-- Center content -->
      <div class="absolute inset-0 flex flex-col items-center justify-center gap-3">
        <!-- Animated icon -->
        <div class="media-loading-icon">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            class="w-8 h-8 txt-tertiary"
          >
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
            <circle cx="8.5" cy="8.5" r="1.5" />
            <polyline points="21,15 16,10 5,21" />
          </svg>
        </div>

        <!-- Status text -->
        <span class="text-sm txt-secondary font-medium media-loading-text">
          {{ $t('chat.imageGenerating') }}
        </span>

        <!-- Progress dots -->
        <div class="flex gap-1.5">
          <span class="progress-dot" />
          <span class="progress-dot" />
          <span class="progress-dot" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts"></script>

<style scoped>
.media-blur-bg {
  background: linear-gradient(
    135deg,
    var(--surface-secondary, rgba(99, 102, 241, 0.05)) 0%,
    var(--surface-tertiary, rgba(139, 92, 246, 0.08)) 50%,
    var(--surface-secondary, rgba(99, 102, 241, 0.05)) 100%
  );
  filter: blur(0px);
}

.media-shimmer {
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255, 255, 255, 0.08) 50%,
    transparent 100%
  );
  background-size: 200% 100%;
  animation: shimmer 2s infinite linear;
}

:root.dark .media-shimmer {
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255, 255, 255, 0.04) 50%,
    transparent 100%
  );
  background-size: 200% 100%;
}

@keyframes shimmer {
  from {
    background-position: -200% 0;
  }
  to {
    background-position: 200% 0;
  }
}

.media-loading-icon {
  animation: media-pulse 2s ease-in-out infinite;
}

@keyframes media-pulse {
  0%,
  100% {
    opacity: 0.6;
    transform: scale(1);
  }
  50% {
    opacity: 1;
    transform: scale(1.05);
  }
}

.media-loading-text {
  animation: media-fade-in 0.4s ease-out;
}

@keyframes media-fade-in {
  from {
    opacity: 0;
    transform: translateY(4px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.progress-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--brand, #6366f1);
  opacity: 0.4;
  animation: dot-bounce 1.4s ease-in-out infinite;
}

.progress-dot:nth-child(1) {
  animation-delay: 0s;
}
.progress-dot:nth-child(2) {
  animation-delay: 0.2s;
}
.progress-dot:nth-child(3) {
  animation-delay: 0.4s;
}

@keyframes dot-bounce {
  0%,
  80%,
  100% {
    opacity: 0.3;
    transform: scale(0.8);
  }
  40% {
    opacity: 1;
    transform: scale(1.2);
  }
}
</style>
