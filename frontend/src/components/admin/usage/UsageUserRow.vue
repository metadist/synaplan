<template>
  <li class="surface-card rounded-xl" data-testid="usage-user-row">
    <button
      type="button"
      class="w-full text-left px-4 py-3 flex flex-col gap-3 sm:flex-row sm:items-center cursor-pointer"
      :aria-expanded="expanded"
      :data-testid="`usage-user-toggle-${user.userId}`"
      @click="expanded = !expanded"
    >
      <span class="flex items-center gap-3 min-w-0 sm:w-64 shrink-0">
        <span class="text-sm font-semibold txt-secondary w-8 shrink-0">#{{ rank }}</span>
        <span class="min-w-0">
          <span class="block text-sm font-medium txt-primary truncate">{{ user.displayName }}</span>
          <span class="block text-xs txt-secondary truncate">
            {{ user.email }} · {{ user.level
            }}<template v-if="user.accountStatus !== 'active'">
              · {{ user.accountStatus }}</template
            >
          </span>
        </span>
      </span>

      <span class="flex-1 flex flex-wrap gap-1.5" data-testid="usage-user-kinds">
        <span
          v-for="kind in user.byKind"
          :key="kind.kind"
          class="inline-flex items-center rounded-full surface-card border border-light-border/30 dark:border-dark-border/20 text-xs px-2 py-1"
          :title="`${formatTokens(kind.tokens, locale)} ${$t('admin.usageBoard.tokensUnit')}`"
        >
          {{ kindLabel(kind.kind) }} · {{ formatUsd(kind.cost, locale) }}
        </span>
        <span v-if="!user.byKind.length" class="text-xs txt-secondary">—</span>
      </span>

      <span class="flex items-center gap-4 shrink-0 text-sm">
        <span class="text-right">
          <span class="block font-semibold txt-primary">{{ formatUsd(user.cost, locale) }}</span>
          <span class="block text-xs txt-secondary">
            {{ $t('admin.usageBoard.requestsCount', { total: user.requests }) }} ·
            {{ formatTokens(user.tokens, locale) }} {{ $t('admin.usageBoard.tokensUnit') }}
          </span>
        </span>
        <ChevronDownIcon
          class="w-5 h-5 txt-secondary transition-transform"
          :class="expanded && 'rotate-180'"
        />
      </span>
    </button>

    <div
      v-if="expanded"
      class="px-4 pb-4 pt-1 border-t border-light-border/20 dark:border-dark-border/10"
    >
      <UsageRequestsPanel :user-id="user.userId" :period="period" />
    </div>
  </li>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'
import UsageRequestsPanel from '@/components/admin/usage/UsageRequestsPanel.vue'
import type { UsagePeriod, UsageUser } from '@/services/api/adminUsageApi'
import { formatTokens, formatUsd } from '@/utils/usageFormat'

defineProps<{
  user: UsageUser
  rank: number
  period: UsagePeriod
}>()

const { t, locale } = useI18n()
const expanded = ref(false)

function kindLabel(kind: string): string {
  const key = `admin.usageBoard.kind.${kind}`
  return t(key) === key ? kind : t(key)
}
</script>
