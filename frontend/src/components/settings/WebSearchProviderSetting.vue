<template>
  <div v-if="allowed" class="surface-card p-6" data-testid="section-web-search-settings">
    <h2 class="text-lg font-semibold txt-primary mb-2">
      {{ $t('settings.webSearch.title') }}
    </h2>
    <p class="txt-secondary text-sm mb-4">{{ $t('settings.webSearch.description') }}</p>

    <label class="block text-sm font-medium txt-primary" for="user-web-search-provider">
      {{ $t('settings.webSearch.useMyOwn') }}
    </label>
    <select
      id="user-web-search-provider"
      v-model="selected"
      class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="saving"
      data-testid="user-web-search-provider"
      @change="save"
    >
      <option v-for="option in options" :key="option.key" :value="option.key">
        {{ option.label }}
      </option>
    </select>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { getUserWebSearch, saveUserWebSearch } from '@/services/api/userPlugsApi'

const { t } = useI18n()
const { success, error: showError } = useNotification()

const allowed = ref(false)
const selected = ref('')
const saving = ref(false)
const options = ref<Array<{ key: string; label: string }>>([])

onMounted(() => {
  void load()
})

async function load(): Promise<void> {
  try {
    const status = await getUserWebSearch()
    allowed.value = status.allowed
    selected.value = status.active
    options.value = status.options
  } catch {
    allowed.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  try {
    const status = await saveUserWebSearch(selected.value || null)
    selected.value = status.active
    success(t('settings.webSearch.saved'))
  } catch (err) {
    showError(err instanceof Error ? err.message : t('settings.webSearch.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>
