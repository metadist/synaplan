<template>
  <div class="space-y-3" data-testid="usage-requests-panel">
    <p v-if="loading" class="text-sm txt-secondary">{{ $t('common.loading') }}</p>

    <div v-else-if="loadFailed" class="flex flex-col sm:flex-row sm:items-center gap-3">
      <p role="alert" class="flex-1 text-sm text-red-600 dark:text-red-400">
        {{ $t('admin.usageBoard.requestsLoadFailed') }}
      </p>
      <button type="button" class="btn-secondary px-4 py-2.5 text-sm font-medium" @click="load">
        {{ $t('common.retry') }}
      </button>
    </div>

    <p v-else-if="!requests.length" class="text-sm txt-secondary">
      {{ $t('admin.usageBoard.requestsEmpty') }}
    </p>

    <template v-else>
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[44rem]">
          <thead>
            <tr
              class="text-left text-xs txt-secondary border-b border-light-border/30 dark:border-dark-border/20"
            >
              <th class="py-2 pr-3 font-medium">{{ $t('admin.usageBoard.colTime') }}</th>
              <th class="py-2 pr-3 font-medium">{{ $t('admin.usageBoard.colKind') }}</th>
              <th class="py-2 pr-3 font-medium">{{ $t('admin.usageBoard.colModel') }}</th>
              <th class="py-2 pr-3 font-medium text-right">
                {{ $t('admin.usageBoard.colTokens') }}
              </th>
              <th class="py-2 pr-3 font-medium text-right">
                {{ $t('admin.usageBoard.colLatency') }}
              </th>
              <th class="py-2 font-medium text-right">{{ $t('admin.usageBoard.colCost') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="request in requests"
              :key="request.id"
              class="border-b border-light-border/20 dark:border-dark-border/10 align-top"
              data-testid="usage-request-row"
            >
              <td class="py-2 pr-3 whitespace-nowrap txt-secondary">
                {{ formatTime(request.at) }}
              </td>
              <td class="py-2 pr-3">
                <span class="font-medium txt-primary">{{ kindLabel(request.kind) }}</span>
                <span class="block text-xs txt-secondary">
                  {{ request.channel
                  }}<template v-if="request.agentId">
                    · {{ $t('admin.usageBoard.agent') }} #{{ request.agentId }}</template
                  ><template v-if="request.chatId">
                    · {{ $t('admin.usageBoard.chat') }} #{{ request.chatId }}</template
                  >
                </span>
              </td>
              <td class="py-2 pr-3">
                <span class="txt-primary">{{ request.model || '—' }}</span>
                <span v-if="request.provider" class="block text-xs txt-secondary">{{
                  request.provider
                }}</span>
              </td>
              <td class="py-2 pr-3 text-right whitespace-nowrap">
                {{ formatTokens(request.tokens, locale) }}
                <span class="block text-xs txt-secondary">
                  {{ formatTokens(request.promptTokens, locale) }} /
                  {{ formatTokens(request.completionTokens, locale) }}
                </span>
              </td>
              <td class="py-2 pr-3 text-right whitespace-nowrap txt-secondary">
                {{ formatLatency(request.latencyMs) }}
                <span
                  v-if="request.status !== 'success'"
                  class="block text-xs text-red-600 dark:text-red-400"
                  >{{ request.status }}</span
                >
              </td>
              <td class="py-2 text-right whitespace-nowrap font-medium txt-primary">
                {{ formatUsdPrecise(request.cost, locale) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-between gap-3">
        <p class="text-xs txt-secondary">
          {{ $t('admin.usageBoard.requestsCount', { total }) }}
        </p>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :disabled="page <= 1 || loading"
            data-testid="usage-requests-prev"
            @click="goTo(page - 1)"
          >
            {{ $t('common.previous') }}
          </button>
          <span class="text-xs txt-secondary">{{ page }} / {{ totalPages }}</span>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            :disabled="page >= totalPages || loading"
            data-testid="usage-requests-next"
            @click="goTo(page + 1)"
          >
            {{ $t('common.next') }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminUsageApi, type UsagePeriod, type UsageRequest } from '@/services/api/adminUsageApi'
import { formatTokens, formatUsdPrecise } from '@/utils/usageFormat'

const props = defineProps<{
  userId: number
  period: UsagePeriod
}>()

const { t, locale } = useI18n()

const requests = ref<UsageRequest[]>([])
const total = ref(0)
const page = ref(1)
const totalPages = ref(1)
const loading = ref(true)
const loadFailed = ref(false)

function kindLabel(kind: UsageRequest['kind']): string {
  const key = `admin.usageBoard.kind.${kind}`
  return t(key) === key ? kind : t(key)
}

function formatTime(unixSeconds: number): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(unixSeconds * 1000))
}

function formatLatency(ms: number): string {
  if (!ms) return '—'
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const result = await adminUsageApi.requests(props.userId, {
      period: props.period,
      page: page.value,
    })
    requests.value = result.requests
    total.value = result.total
    totalPages.value = result.totalPages
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function goTo(next: number) {
  page.value = Math.min(Math.max(1, next), totalPages.value)
  void load()
}

watch(
  () => props.period,
  () => {
    page.value = 1
    void load()
  }
)

onMounted(load)
</script>
