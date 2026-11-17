<template>
  <section class="surface-card p-6" data-testid="section-api-usage">
    <h2 class="text-lg font-semibold txt-primary">{{ $t('config.apiKeys.usage.title') }}</h2>
    <p class="mt-2 text-sm txt-secondary leading-relaxed">
      {{ $t('config.apiKeys.usage.intro') }}
    </p>
    <ul class="mt-4 space-y-3">
      <li
        v-for="endpoint in endpoints"
        :key="endpoint.id"
        class="flex flex-col gap-2 sm:flex-row sm:items-center"
      >
        <div class="min-w-0 flex-1">
          <p class="text-xs font-medium txt-secondary">{{ endpoint.label }}</p>
          <code
            class="mt-1 block px-3 py-2 rounded-xl surface-chip txt-primary font-mono text-sm break-all"
            :data-testid="`text-api-url-${endpoint.id}`"
            >{{ endpoint.url }}</code
          >
        </div>
        <button
          type="button"
          class="w-full sm:w-auto shrink-0 btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center justify-center gap-2"
          :data-testid="`btn-copy-api-url-${endpoint.id}`"
          :aria-label="endpoint.copyLabel"
          @click="copy(endpoint)"
        >
          <CheckIcon v-if="copiedId === endpoint.id" class="w-4 h-4" />
          <ClipboardDocumentIcon v-else class="w-4 h-4" />
          {{
            copiedId === endpoint.id
              ? $t('config.apiKeys.actions.copied')
              : $t('config.apiKeys.actions.copy')
          }}
        </button>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckIcon, ClipboardDocumentIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
import { publicApiEndpoints, type PublicApiEndpoint } from '@/utils/publicApiEndpoints'

interface EndpointRow extends PublicApiEndpoint {
  label: string
  copyLabel: string
}

const { t } = useI18n()
const { success, error: showError } = useNotification()
const copiedId = ref<string | null>(null)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

const endpoints = computed<EndpointRow[]>(() =>
  publicApiEndpoints().map((endpoint) => {
    const label = t(`config.apiKeys.usage.${endpoint.id}`)
    return {
      ...endpoint,
      label,
      copyLabel: t('config.apiKeys.usage.copy', { label }),
    }
  })
)

const copy = async (endpoint: EndpointRow): Promise<void> => {
  try {
    await navigator.clipboard.writeText(endpoint.url)
    copiedId.value = endpoint.id
    success(t('config.apiKeys.actions.copied'))
    if (copiedTimer !== null) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => {
      copiedId.value = null
      copiedTimer = null
    }, 2000)
  } catch {
    showError(t('config.apiKeys.errorCopying'))
  }
}

onUnmounted(() => {
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})
</script>
