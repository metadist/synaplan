<template>
  <div class="space-y-6" data-testid="section-platform-instances">
    <div
      v-if="error"
      class="bg-red-500/10 border border-red-500/30 rounded-lg p-4"
      data-testid="alert-error"
    >
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <div v-if="loading && instances.length === 0" class="surface-card p-12 text-center">
      <p class="txt-secondary">{{ $t('common.loading') }}</p>
    </div>

    <div
      v-else-if="instances.length === 0"
      class="surface-card p-12 text-center"
      data-testid="section-empty"
    >
      <p class="txt-secondary">{{ $t('people.linkedPlatforms.empty') }}</p>
    </div>

    <div v-else class="surface-card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="border-b border-light-border/30 dark:border-dark-border/20">
            <tr class="bg-black/5 dark:bg-white/5">
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('people.linkedPlatforms.client') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('people.linkedPlatforms.host') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('people.linkedPlatforms.status') }}
              </th>
              <th
                class="px-6 py-3 text-left text-xs font-semibold txt-primary uppercase tracking-wider"
              >
                {{ $t('people.linkedPlatforms.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-light-border/30 dark:divide-dark-border/20">
            <tr
              v-for="instance in instances"
              :key="instance.id"
              data-testid="item-platform-instance"
            >
              <td class="px-6 py-4 text-sm font-medium txt-primary">
                {{ clientLabel(instance.client) }}
              </td>
              <td class="px-6 py-4 text-sm txt-secondary">{{ instance.host }}</td>
              <td class="px-6 py-4 text-sm txt-secondary">
                {{ statusLabel(instance.status) }}
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="instance.status === 'pending'"
                    type="button"
                    class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
                    data-testid="btn-approve"
                    @click="approve(instance.id)"
                  >
                    {{ $t('people.linkedPlatforms.approve') }}
                  </button>
                  <button
                    v-if="instance.id !== 'outlook-builtin' && instance.status !== 'revoked'"
                    type="button"
                    class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
                    data-testid="btn-revoke"
                    @click="revoke(instance.id)"
                  >
                    {{ $t('people.linkedPlatforms.revoke') }}
                  </button>
                </div>
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
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { platformLinksApi, type AdminPlatformInstance } from '@/services/api/platformLinksApi'

const { t } = useI18n()
const dialog = useDialog()
const { success, error: showError } = useNotification()

const instances = ref<AdminPlatformInstance[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

function clientLabel(client: string): string {
  const key = `platformConnect.clients.${client}`
  const translated = t(key)
  return translated === key ? client : translated
}

function statusLabel(status: string): string {
  const key = `people.linkedPlatforms.statusValue.${status}`
  const translated = t(key)
  return translated === key ? status : translated
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    instances.value = await platformLinksApi.listAdminInstances()
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('people.linkedPlatforms.loadError')
  } finally {
    loading.value = false
  }
}

async function approve(id: string): Promise<void> {
  try {
    await platformLinksApi.approveInstance(id)
    success(t('people.linkedPlatforms.approved'))
    await load()
  } catch {
    showError(t('people.linkedPlatforms.approveError'))
  }
}

async function revoke(id: string): Promise<void> {
  const confirmed = await dialog.confirm({
    title: t('people.linkedPlatforms.revokeTitle'),
    message: t('people.linkedPlatforms.revokeConfirm'),
    danger: true,
  })
  if (!confirmed) return
  try {
    await platformLinksApi.revokeInstance(id)
    success(t('people.linkedPlatforms.revoked'))
    await load()
  } catch {
    showError(t('people.linkedPlatforms.revokeError'))
  }
}

onMounted(() => {
  void load()
})
</script>
