<template>
  <Teleport to="body">
    <Transition name="hint-fade">
      <div
        v-if="isOpen"
        data-testid="guest-hint-popover"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click.self="$emit('close')"
      >
        <div class="absolute inset-0 bg-black/30 backdrop-blur-[2px]" @click="$emit('close')" />

        <div
          class="relative w-full max-w-xs rounded-2xl overflow-hidden shadow-2xl surface-card animate-hint-enter"
          role="dialog"
          aria-modal="true"
        >
          <button
            class="absolute top-2.5 right-2.5 icon-ghost w-7 h-7 flex items-center justify-center rounded-lg"
            data-testid="guest-hint-close"
            :aria-label="$t('common.close')"
            @click="$emit('close')"
          >
            <Icon icon="mdi:close" class="w-4 h-4" />
          </button>

          <div class="px-5 pt-5 pb-4">
            <div
              class="mb-3 w-11 h-11 rounded-xl bg-[var(--brand)]/15 flex items-center justify-center"
            >
              <Icon :icon="featureIcon" class="w-6 h-6 text-[var(--brand)]" />
            </div>

            <p class="text-sm txt-primary leading-relaxed mb-1">
              {{ $t(`guest.featureGate.features.${resolvedFeatureKey}`) }}
            </p>
            <p class="text-xs txt-secondary mb-4">
              {{ $t('guest.featureGate.subtitle') }}
            </p>

            <router-link
              to="/register"
              data-testid="guest-hint-register"
              class="block w-full py-2.5 rounded-xl bg-[var(--brand)] text-white text-center font-semibold text-sm hover:brightness-110 transition-all"
              @click="$emit('close')"
            >
              {{ $t('guest.featureGate.registerButton') }}
            </router-link>
            <router-link
              to="/login"
              data-testid="guest-hint-login"
              class="block w-full py-2 mt-2 text-center text-xs font-medium txt-secondary hover:txt-primary transition-colors"
              @click="$emit('close')"
            >
              {{ $t('guest.featureGate.loginButton') }}
            </router-link>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  isOpen: boolean
  featureKey: string
}>()

const emit = defineEmits<{
  close: []
}>()

const handleKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && props.isOpen) emit('close')
}

onMounted(() => document.addEventListener('keydown', handleKeydown))
onUnmounted(() => document.removeEventListener('keydown', handleKeydown))

const featureIcons: Record<string, string> = {
  files: 'mdi:file-document-outline',
  attach: 'mdi:paperclip',
  memories: 'mdi:brain',
  settings: 'mdi:cog-outline',
  channels: 'mdi:transit-connection-variant',
  aiSetup: 'mdi:robot-outline',
  statistics: 'mdi:chart-bar',
  models: 'mdi:tune-vertical',
  tools: 'mdi:toolbox-outline',
  knowledge: 'mdi:folder-outline',
  enhance: 'mdi:auto-fix',
  general: 'mdi:lock-open-outline',
}

const resolvedFeatureKey = computed(() =>
  props.featureKey in featureIcons ? props.featureKey : 'general'
)

const featureIcon = computed(() => featureIcons[resolvedFeatureKey.value] ?? featureIcons.general)
</script>

<style scoped>
.hint-fade-enter-active,
.hint-fade-leave-active {
  transition: opacity 0.2s ease;
}
.hint-fade-enter-from,
.hint-fade-leave-to {
  opacity: 0;
}

@keyframes hint-enter {
  from {
    opacity: 0;
    transform: scale(0.96) translateY(8px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
.animate-hint-enter {
  animation: hint-enter 0.22s ease-out;
}
</style>
