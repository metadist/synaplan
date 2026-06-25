<template>
  <Transition
    enter-active-class="transition-opacity duration-200 ease-out"
    leave-active-class="transition-opacity duration-200 ease-in"
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
  >
    <!--
      Hard, non-dismissable gate: must sit above EVERY other overlay (dialogs are
      z-[10000], cookie consent z-[9999]) so nothing can be interacted with while
      a forced update is required.
    -->
    <div
      v-if="show"
      class="fixed inset-0 z-[10050] flex flex-col items-center justify-center gap-8 bg-app px-6"
      data-testid="force-update"
      role="alertdialog"
      aria-modal="true"
      :aria-label="$t('forceUpdate.title')"
    >
      <div class="flex flex-col items-center gap-3 text-center">
        <div
          class="w-20 h-20 rounded-full bg-[var(--brand)]/15 flex items-center justify-center"
          aria-hidden="true"
        >
          <Icon icon="mdi:cellphone-arrow-down" class="w-11 h-11 text-[var(--brand)]" />
        </div>
        <h1 class="text-2xl font-bold txt-primary">{{ $t('forceUpdate.title') }}</h1>
        <p class="txt-secondary max-w-xs">{{ $t('forceUpdate.subtitle') }}</p>
        <p v-if="minVersion" class="text-sm txt-tertiary">
          {{ $t('forceUpdate.minVersion', { version: minVersion }) }}
        </p>
      </div>
      <a
        v-if="storeUrl"
        :href="storeUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="btn-primary px-8 py-3 rounded-lg font-medium"
        data-testid="btn-force-update"
      >
        {{ $t('forceUpdate.cta') }}
      </a>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import { getNativePlatform, isNativeApp } from '@/services/api/nativeRuntime'

const config = useConfigStore()

// Only ever block inside the native shell; the gate is meaningless on web,
// where users always run the latest deployed bundle.
const show = computed(() => isNativeApp() && config.mobile.updateRequired)

const minVersion = computed(() => config.mobile.minVersion)

const storeUrl = computed(() => {
  const platform = getNativePlatform()
  if ('android' === platform && config.mobile.androidAppUrl) {
    return config.mobile.androidAppUrl
  }
  if ('ios' === platform && config.mobile.iosAppUrl) {
    return config.mobile.iosAppUrl
  }
  // Fallback: whichever link the operator configured.
  return config.mobile.iosAppUrl || config.mobile.androidAppUrl || ''
})
</script>
