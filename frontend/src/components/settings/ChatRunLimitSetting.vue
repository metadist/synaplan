<template>
  <div v-if="limit?.enabled" class="surface-card p-6" data-testid="section-chat-run-limit">
    <h2 class="text-lg font-semibold txt-primary mb-2">
      {{ $t('settings.chatRunLimit.title') }}
    </h2>
    <p class="txt-secondary text-sm mb-4">{{ $t('settings.chatRunLimit.description') }}</p>

    <p v-if="monthly === null" class="txt-secondary text-sm" data-testid="chat-run-limit-unlimited">
      {{ $t('settings.chatRunLimit.noBudget') }}
    </p>
    <template v-else>
      <label class="block text-sm font-medium txt-primary" for="chat-run-limit-percent">
        {{ $t('settings.chatRunLimit.share') }}
      </label>
      <select
        id="chat-run-limit-percent"
        v-model.number="selected"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="saving"
        data-testid="chat-run-limit-percent"
        @change="save"
      >
        <option v-for="choice in limit.choices" :key="choice" :value="choice">
          {{ optionLabel(choice) }}
        </option>
      </select>
      <p class="txt-secondary text-xs mt-2" data-testid="chat-run-limit-monthly">
        {{ $t('settings.chatRunLimit.monthly', { amount: money(monthly) }) }}
      </p>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import {
  getChatRunLimit,
  saveChatRunLimit,
  type ChatRunLimit,
} from '@/services/api/chatRunLimitApi'
import { formatUsd } from '@/utils/usageFormat'

const { t, locale } = useI18n()
const { success, error: showError } = useNotification()

const limit = ref<ChatRunLimit | null>(null)
const selected = ref(0)
const saving = ref(false)

const monthly = computed<number | null>(() => {
  const raw = limit.value?.monthlyBudget
  if (raw === null || raw === undefined) return null
  const value = Number(raw)
  return Number.isFinite(value) && value > 0 ? value : null
})

onMounted(() => {
  void load()
})

async function load(): Promise<void> {
  try {
    apply(await getChatRunLimit())
  } catch {
    limit.value = null
  }
}

function apply(next: ChatRunLimit): void {
  limit.value = next
  selected.value = next.percent
}

function money(amount: number): string {
  return formatUsd(amount, locale.value)
}

function optionLabel(percent: number): string {
  const amount = money(((monthly.value ?? 0) * percent) / 100)
  const label = t('settings.chatRunLimit.option', { percent, amount })
  return percent === limit.value?.default
    ? `${label} · ${t('settings.chatRunLimit.default')}`
    : label
}

async function save(): Promise<void> {
  const previous = limit.value?.percent ?? selected.value
  saving.value = true
  try {
    apply(await saveChatRunLimit(selected.value))
    success(t('settings.chatRunLimit.saved'))
  } catch (err) {
    selected.value = previous
    showError(err instanceof Error ? err.message : t('settings.chatRunLimit.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>
