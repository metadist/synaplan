<template>
  <div class="space-y-6" data-testid="panel-app-email">
    <section class="surface-card p-6 space-y-3" data-testid="section-email">
      <h2 class="text-lg font-semibold txt-primary">{{ $t('apps.email.addressesTitle') }}</h2>
      <ul class="space-y-2">
        <li
          v-for="address in addresses"
          :key="address.id"
          class="flex flex-wrap items-center justify-between gap-2 p-3 surface-chip rounded-xl"
          data-testid="item-email-channel"
        >
          <span class="min-w-0">
            <span class="block font-mono text-sm txt-primary break-all">{{ address.email }}</span>
            <span class="block text-xs txt-secondary mt-0.5">{{ address.hint }}</span>
          </span>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2"
            :data-testid="`btn-copy-email-${address.id}`"
            @click="copy(address.email)"
          >
            <ClipboardDocumentIcon class="w-4 h-4" aria-hidden="true" />
            {{ $t('apps.email.copy') }}
          </button>
        </li>
      </ul>
    </section>

    <section class="surface-card p-6 space-y-3" data-testid="section-email-keyword">
      <h2 class="text-lg font-semibold txt-primary">{{ $t('apps.email.keywordTitle') }}</h2>
      <p class="text-sm txt-secondary">{{ $t('apps.email.keywordHint') }}</p>
      <form class="flex flex-wrap items-center gap-2" @submit.prevent="save">
        <span class="font-mono text-sm txt-primary">{{ EMAIL_KEYWORD_PREFIX }}</span>
        <input
          v-model="keyword"
          type="text"
          class="flex-1 min-w-0 max-w-xs px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
          :placeholder="$t('channels.keywordPlaceholder')"
          :disabled="saving"
          data-testid="input-email-keyword"
        />
        <span class="font-mono text-sm txt-primary">{{ EMAIL_DOMAIN }}</span>
        <button
          type="submit"
          class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="saving || keyword.trim() === savedKeyword"
          data-testid="btn-save-email-keyword"
        >
          {{ $t('common.save') }}
        </button>
      </form>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ClipboardDocumentIcon } from '@heroicons/vue/24/outline'
import { profileApi } from '@/services/api/profileApi'
import { useNotification } from '@/composables/useNotification'
import { getApiErrorMessage } from '@/utils/errorMessage'

/** The inbound address is fixed server-side (SmartEmailHelper). */
const EMAIL_KEYWORD_PREFIX = 'smart+'
const EMAIL_DOMAIN = '@synaplan.net'
const BASE_ADDRESS = `smart${EMAIL_DOMAIN}`

const { t } = useI18n()
const { success, error } = useNotification()

const keyword = ref('')
const savedKeyword = ref('')
const personalAddress = ref('')
const saving = ref(false)

const addresses = computed(() => {
  const list = [{ id: 'base', email: BASE_ADDRESS, hint: t('apps.email.baseHint') }]
  if (personalAddress.value && personalAddress.value !== BASE_ADDRESS) {
    list.push({ id: 'personal', email: personalAddress.value, hint: t('apps.email.personalHint') })
  }
  return list
})

function apply(response: { keyword?: string | null; emailAddress?: string }): void {
  keyword.value = response.keyword || ''
  savedKeyword.value = keyword.value
  personalAddress.value = response.emailAddress ?? ''
}

onMounted(async () => {
  try {
    const response = await profileApi.getEmailKeyword()
    if (response.success) apply(response)
  } catch {
    // The base address works without a keyword; the form stays empty.
  }
})

async function save(): Promise<void> {
  saving.value = true
  try {
    const response = await profileApi.setEmailKeyword(keyword.value.trim())
    if (response.success) {
      apply(response)
      success(t('channels.keywordSaved'))
    }
  } catch (err: unknown) {
    error(getApiErrorMessage(err) || t('apps.email.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function copy(value: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(value)
    success(t('apps.email.copied'))
  } catch {
    error(t('apps.email.copyFailed'))
  }
}
</script>
