<template>
  <div class="space-y-6" data-testid="page-config-platform-links">
    <PageHeader
      :title="$t('linkedPlatforms.title')"
      :subtitle="$t('linkedPlatforms.description')"
      icon="heroicons:link"
      data-testid="section-header"
    />

    <div
      v-if="error"
      class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 flex items-start gap-3"
      data-testid="alert-error"
    >
      <p class="flex-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="btn-alert-retry"
        @click="loadLinks"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <div
      v-if="loading && links.length === 0"
      class="surface-card p-12 text-center"
      data-testid="section-loading"
    >
      <p class="txt-secondary">{{ $t('common.loading') }}</p>
    </div>

    <div
      v-else-if="links.length === 0"
      class="surface-card p-12 text-center"
      data-testid="section-empty"
    >
      <p class="txt-secondary text-lg">{{ $t('linkedPlatforms.empty') }}</p>
    </div>

    <div v-else class="surface-card overflow-hidden" data-testid="section-links-table">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="border-b border-light-border/30 dark:border-dark-border/20">
            <tr class="bg-black/5 dark:bg-white/5">
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.client') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.host') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.uid') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.key') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.lastSeen') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('linkedPlatforms.table.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-light-border/30 dark:divide-dark-border/20">
            <tr
              v-for="link in links"
              :key="link.id"
              class="hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
              data-testid="item-platform-link"
            >
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium txt-primary">
                {{ clientLabel(link.client) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">{{ link.host }}</td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-primary">
                {{ link.external_id }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ link.key_label }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm txt-secondary">
                {{ formatSeen(link.last_seen) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <button
                  type="button"
                  class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
                  data-testid="btn-disconnect"
                  @click="disconnect(link)"
                >
                  {{ $t('linkedPlatforms.disconnect') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useDateFormat } from '@/composables/useDateFormat'
import { platformLinksApi, type PlatformLink } from '@/services/api/platformLinksApi'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()
const { formatDateTime } = useDateFormat()

const links = ref<PlatformLink[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

function clientLabel(client: string): string {
  const key = `platformConnect.clients.${client}`
  const translated = t(key)
  return translated === key ? client : translated
}

function formatSeen(value: number | undefined): string {
  if (!value) return '—'
  return formatDateTime(new Date(value * 1000))
}

async function loadLinks(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    links.value = await platformLinksApi.listMine()
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('linkedPlatforms.loadError')
  } finally {
    loading.value = false
  }
}

async function disconnect(link: PlatformLink): Promise<void> {
  const confirmed = await dialog.confirm({
    title: t('linkedPlatforms.disconnectTitle'),
    message: t('linkedPlatforms.disconnectConfirm', { host: link.host }),
    danger: true,
  })
  if (!confirmed) return
  try {
    await platformLinksApi.disconnect(link.id)
    success(t('linkedPlatforms.disconnected'))
    await loadLinks()
  } catch {
    showError(t('linkedPlatforms.disconnectError'))
  }
}

onMounted(() => {
  void loadLinks()
})
</script>
