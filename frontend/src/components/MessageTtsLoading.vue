<template>
  <div class="tts-loading flex items-center gap-3 py-3 px-4 rounded-xl surface-chip mt-2" data-testid="tts-loading">
    <!-- Animated sound wave bars -->
    <div class="tts-wave flex items-end gap-[3px] h-5">
      <span class="tts-bar" />
      <span class="tts-bar" />
      <span class="tts-bar" />
      <span class="tts-bar" />
      <span class="tts-bar" />
    </div>

    <span class="text-sm txt-secondary font-medium">
      {{ $t('chat.ttsGenerating') }}
    </span>
  </div>
</template>

<script setup lang="ts">
</script>

<style scoped>
.tts-bar {
  width: 3px;
  border-radius: 2px;
  background: var(--brand, #6366f1);
  animation: tts-wave 1.2s ease-in-out infinite;
}

.tts-bar:nth-child(1) { height: 6px;  animation-delay: 0s; }
.tts-bar:nth-child(2) { height: 12px; animation-delay: 0.15s; }
.tts-bar:nth-child(3) { height: 18px; animation-delay: 0.3s; }
.tts-bar:nth-child(4) { height: 12px; animation-delay: 0.45s; }
.tts-bar:nth-child(5) { height: 6px;  animation-delay: 0.6s; }

@keyframes tts-wave {
  0%, 100% { transform: scaleY(0.4); opacity: 0.5; }
  50%      { transform: scaleY(1);   opacity: 1; }
}

.tts-loading {
  animation: tts-fade-in 0.3s ease-out;
}

@keyframes tts-fade-in {
  from { opacity: 0; transform: translateY(4px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>
