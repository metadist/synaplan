<template>
  <div class="space-y-4" data-testid="ai-tab-gateway">
    <p class="text-sm txt-secondary">
      <i18n-t keypath="adminSetup.intro.gateway" tag="span">
        <template #app>
          <RouterLink
            to="/apps/claude-code"
            class="text-[var(--brand)] hover:underline font-medium"
            data-testid="link-gateway-user-view"
          >
            {{ $t('adminSetup.gatewayUserView') }}
          </RouterLink>
        </template>
      </i18n-t>
    </p>

    <div v-if="loading" class="text-center py-12">
      <div
        class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
      ></div>
      <p class="mt-2 txt-secondary text-sm">{{ $t('common.loading') }}</p>
    </div>

    <div
      v-else-if="!status"
      class="surface-card rounded-lg p-4 flex flex-wrap items-center justify-between gap-3"
      data-testid="gateway-load-error"
    >
      <p class="text-sm txt-secondary">{{ $t('messagesGateway.loadError') }}</p>
      <button type="button" class="btn-secondary px-4 py-2.5 text-sm font-medium" @click="load()">
        {{ $t('common.retry') }}
      </button>
    </div>

    <MessagesGatewayAdminSettings v-else :status="status" @saved="load(true)" />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import MessagesGatewayAdminSettings from '@/components/config/messagesGateway/MessagesGatewayAdminSettings.vue'
import {
  getMessagesGatewayStatus,
  type MessagesGatewayStatus,
} from '@/services/api/messagesGatewayApi'
import { rememberGatewayEnabled } from '@/composables/useAiAccounts'

const loading = ref(true)
const status = ref<MessagesGatewayStatus | null>(null)

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    status.value = await getMessagesGatewayStatus()
    // The Apps directory caches whether the gateway is on; a toggle here must show up there.
    rememberGatewayEnabled(status.value.enabled === true)
  } catch {
    if (!silent) status.value = null
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>
