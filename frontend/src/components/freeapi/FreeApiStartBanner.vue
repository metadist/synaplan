<template>
  <div
    class="surface-chip rounded-xl px-4 py-3 text-sm flex items-start gap-3"
    data-testid="freeapi-start-banner"
  >
    <SparklesIcon class="w-5 h-5 shrink-0 mt-0.5 txt-brand" aria-hidden="true" />
    <div class="min-w-0 flex-1" aria-live="polite">
      <p class="txt-primary">
        <strong>{{ $t('freeApi.banner.title') }}</strong>
        — {{ starting ? $t('freeApi.startOnSend.starting') : $t('freeApi.startOnSend.lead') }}
      </p>
      <p class="txt-secondary mt-0.5" data-testid="freeapi-consent">
        {{ $t('freeApi.consent', { server }) }}
      </p>
      <p class="txt-secondary mt-0.5">
        <i18n-t keypath="freeApi.startOnSend.ownSetup" tag="span" scope="global">
          <template #ollama>
            <RouterLink
              :to="{ path: '/admin/setup', query: { section: 'local-ai' } }"
              class="txt-brand underline underline-offset-2"
              data-testid="freeapi-start-ollama"
            >
              {{ $t('freeApi.startOnSend.ollama') }}
            </RouterLink>
          </template>
          <template #key>
            <RouterLink
              :to="{ path: '/admin/setup', query: { section: 'providers' } }"
              class="txt-brand underline underline-offset-2"
              data-testid="freeapi-start-key"
            >
              {{ $t('freeApi.startOnSend.key') }}
            </RouterLink>
          </template>
        </i18n-t>
      </p>
      <p
        v-if="errorKey"
        role="alert"
        class="text-sm text-red-600 dark:text-red-400 mt-2"
        data-testid="freeapi-start-error"
      >
        {{ $t(errorKey) }} {{ $t('freeApi.startOnSend.notSent') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { SparklesIcon } from '@heroicons/vue/24/outline'
import { useFreeApiStore } from '@/stores/freeApi'

/**
 * Above the composer while the Synaplan Demo has not started yet: what the
 * first message does, where it goes, and the way to an own AI instead.
 */
defineProps<{ errorKey?: string }>()

const { t } = useI18n()
const freeApi = useFreeApiStore()

const starting = computed(() => freeApi.busy)
const server = computed(() => freeApi.status?.server ?? t('freeApi.serverFallback'))

onMounted(() => {
  void freeApi.load()
})
</script>
