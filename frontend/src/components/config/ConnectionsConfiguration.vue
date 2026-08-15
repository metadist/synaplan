<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { connectionsApi, type ConnectionItem } from '@/services/api/connectionsApi'
import ConnectionStatusPill from '@/components/config/ConnectionStatusPill.vue'

const { t } = useI18n()
const router = useRouter()
const { success, error: showError } = useNotification()
const items = ref<ConnectionItem[]>([])
const loading = ref(true)

const load = async () => {
  loading.value = true
  try {
    items.value = await connectionsApi.list()
  } catch {
    showError(t('config.connections.loadFailed'))
  } finally {
    loading.value = false
  }
}

const onTest = async (item: ConnectionItem) => {
  if (item.source !== 'registry') {
    if (item.manage_path) {
      await router.push(item.manage_path)
    }
    return
  }
  try {
    const updated = await connectionsApi.test(item.id)
    items.value = items.value.map((row) => (row.id === item.id ? updated : row))
    success(t('config.connections.testOk'))
  } catch {
    showError(t('config.connections.testFailed'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6" data-testid="page-connections">
    <div class="surface-card p-6">
      <h2 class="text-2xl font-semibold txt-primary">{{ $t('config.connections.title') }}</h2>
      <p class="txt-secondary text-sm mt-1">{{ $t('config.connections.subtitle') }}</p>
    </div>

    <div v-if="loading" class="txt-secondary text-sm">{{ $t('config.connections.loading') }}</div>
    <div v-else-if="items.length === 0" class="surface-card p-6 txt-secondary text-sm">
      {{ $t('config.connections.empty') }}
    </div>
    <ul v-else class="space-y-3">
      <li
        v-for="item in items"
        :key="item.id"
        class="surface-card p-4 flex flex-wrap items-center gap-3"
        data-testid="connection-row"
      >
        <div class="flex-1 min-w-0">
          <p class="font-medium txt-primary truncate">{{ item.name }}</p>
          <p class="text-xs txt-secondary">
            {{ $t(`config.connections.types.${item.type}`, item.type) }}
          </p>
        </div>
        <ConnectionStatusPill :status="item.status" />
        <button type="button" class="btn-secondary text-sm" @click="onTest(item)">
          <Icon icon="heroicons:signal" class="w-4 h-4 inline mr-1" />
          {{ $t('config.connections.test') }}
        </button>
      </li>
    </ul>
  </div>
</template>
