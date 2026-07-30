<template>
  <div
    v-if="visible"
    class="mx-auto max-w-4xl w-full px-4 pt-4"
    data-testid="provider-setup-banner"
  >
    <div
      class="rounded-lg border border-[var(--status-warning)] bg-[var(--status-warning-muted)] p-4 flex items-start gap-3"
    >
      <Icon
        icon="mdi:key-alert-outline"
        class="w-6 h-6 shrink-0 text-[var(--status-warning)] mt-0.5"
      />
      <div class="flex-1 min-w-0">
        <p class="font-semibold txt-primary">{{ $t('setupBanner.title') }}</p>
        <p class="text-sm txt-secondary mt-0.5">
          {{ isAdmin ? $t('setupBanner.adminText') : $t('setupBanner.userText') }}
        </p>
        <RouterLink
          v-if="isAdmin"
          to="/admin/setup"
          class="inline-flex items-center gap-1 mt-2 btn-primary text-sm"
          data-testid="provider-setup-banner-cta"
        >
          <Icon icon="mdi:rocket-launch-outline" class="w-4 h-4" />
          {{ $t('setupBanner.cta') }}
        </RouterLink>
      </div>
      <button
        class="txt-secondary hover:txt-primary shrink-0"
        :aria-label="$t('common.close')"
        data-testid="provider-setup-banner-dismiss"
        @click="dismissed = true"
      >
        <Icon icon="mdi:close" class="w-5 h-5" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'

/**
 * "Connect an AI provider" banner for the chat, driven by the runtime-config
 * setup signal (setup.chatReady). Admins get a CTA to the setup wizard;
 * regular users are told to contact their administrator. Dismissable for the
 * session only — the underlying problem persists until a provider is set up.
 */
const authStore = useAuthStore()
const config = useConfigStore()

const dismissed = ref(false)

const isAdmin = computed(() => authStore.isAdmin)
const visible = computed(
  () => !dismissed.value && authStore.isAuthenticated && config.setup.chatReady === false
)
</script>
