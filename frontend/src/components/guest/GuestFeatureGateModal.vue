<template>
  <Teleport to="body">
    <Transition name="gate-fade">
      <div
        v-if="isOpen"
        data-testid="guest-feature-gate-modal"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$emit('close')" />

        <div
          class="relative w-full max-w-md rounded-2xl overflow-hidden shadow-2xl animate-gate-enter"
        >
          <!-- Gradient header -->
          <div
            class="relative px-8 pt-8 pb-6 text-center text-white"
            style="background: linear-gradient(135deg, var(--brand) 0%, #1a2980 100%)"
          >
            <button
              class="absolute top-3 right-3 w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center transition-colors"
              data-testid="guest-feature-gate-close"
              @click="$emit('close')"
            >
              <Icon icon="mdi:close" class="w-4 h-4" />
            </button>

            <div
              class="mx-auto mb-3 w-14 h-14 rounded-2xl bg-white/15 backdrop-blur-sm flex items-center justify-center"
            >
              <Icon :icon="featureIcon" class="w-7 h-7" />
            </div>
            <h2 class="text-xl font-bold mb-1">
              {{ $t('guest.featureGate.title') }}
            </h2>
            <p class="text-sm text-white/75">
              {{ $t('guest.featureGate.subtitle') }}
            </p>
          </div>

          <!-- Feature highlight -->
          <div class="bg-white dark:bg-gray-900 px-8 py-5">
            <div
              class="flex items-start gap-3 p-3.5 rounded-xl bg-[var(--brand)]/5 dark:bg-[var(--brand)]/10 border border-[var(--brand)]/10"
            >
              <div
                class="flex-shrink-0 w-9 h-9 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center"
              >
                <Icon :icon="featureIcon" class="w-5 h-5 text-[var(--brand)]" />
              </div>
              <p class="text-sm txt-primary leading-relaxed pt-1.5">
                {{ $t(`guest.featureGate.features.${resolvedFeatureKey}`) }}
              </p>
            </div>

            <ul class="mt-4 space-y-2.5">
              <li
                v-for="perk in perks"
                :key="perk.key"
                class="flex items-center gap-2.5 text-sm txt-secondary"
              >
                <Icon :icon="perk.icon" class="w-4 h-4 text-[var(--brand)] flex-shrink-0" />
                <span>{{ $t(`guest.featureGate.features.${perk.key}`) }}</span>
              </li>
            </ul>
          </div>

          <!-- CTA buttons -->
          <div class="bg-white dark:bg-gray-900 px-8 pb-7 pt-1 space-y-2.5">
            <router-link
              to="/register"
              data-testid="guest-feature-gate-register"
              class="block w-full py-3 rounded-xl bg-[var(--brand)] text-white text-center font-semibold text-sm hover:brightness-110 transition-all shadow-lg shadow-[var(--brand)]/25"
              @click="$emit('close')"
            >
              {{ $t('guest.featureGate.registerButton') }}
            </router-link>
            <router-link
              to="/login"
              data-testid="guest-feature-gate-login"
              class="block w-full py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-center text-sm font-medium txt-secondary hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
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
import { computed } from 'vue'
import { Icon } from '@iconify/vue'

const props = defineProps<{
  isOpen: boolean
  featureKey: string
}>()

defineEmits<{
  close: []
}>()

const featureIcons: Record<string, string> = {
  files: 'mdi:file-document-outline',
  memories: 'mdi:brain',
  settings: 'mdi:cog-outline',
  statistics: 'mdi:chart-bar',
  general: 'mdi:lock-open-outline',
}

const resolvedFeatureKey = computed(() =>
  props.featureKey in featureIcons ? props.featureKey : 'general'
)

const featureIcon = computed(() => featureIcons[resolvedFeatureKey.value] ?? featureIcons.general)

const perks = computed(() => {
  const allPerks = [
    { key: 'files', icon: 'mdi:file-document-outline' },
    { key: 'memories', icon: 'mdi:brain' },
    { key: 'settings', icon: 'mdi:cog-outline' },
    { key: 'statistics', icon: 'mdi:chart-bar' },
  ]
  return allPerks.filter((p) => p.key !== resolvedFeatureKey.value)
})
</script>

<style scoped>
.gate-fade-enter-active,
.gate-fade-leave-active {
  transition: opacity 0.25s ease;
}
.gate-fade-enter-from,
.gate-fade-leave-to {
  opacity: 0;
}

@keyframes gate-enter {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
.animate-gate-enter {
  animation: gate-enter 0.3s ease-out;
}
</style>
