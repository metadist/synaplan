<template>
  <div class="surface-card rounded-lg p-6" data-testid="admin-system-info-panel">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
        <Icon icon="mdi:server" class="w-5 h-5" />
        {{ t('admin.systemInfo.title') }}
      </h3>
      <button
        class="p-2 rounded-lg txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50"
        :disabled="loading"
        :title="t('admin.systemInfo.refresh')"
        data-testid="btn-refresh"
        @click="load"
      >
        <Icon icon="mdi:refresh" class="w-5 h-5" :class="{ 'animate-spin': loading }" />
      </button>
    </div>

    <div v-if="loading && !info" class="flex justify-center py-10" data-testid="loading">
      <div
        class="w-8 h-8 border-4 border-gray-300 dark:border-gray-600 border-t-[var(--brand)] rounded-full animate-spin"
      />
    </div>

    <div v-else-if="error" class="text-center py-10 txt-secondary" data-testid="error-state">
      {{ t('admin.systemInfo.loadError') }}
    </div>

    <div v-else-if="info" class="space-y-5">
      <!-- Headline stat tiles -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="surface-elevated rounded-lg p-4">
          <div class="flex items-center justify-between mb-1">
            <span class="text-sm txt-secondary">{{ t('admin.systemInfo.phpVersion') }}</span>
            <Icon icon="mdi:language-php" class="w-5 h-5 txt-secondary" />
          </div>
          <div class="text-2xl font-bold txt-primary">{{ info.php.version }}</div>
          <div class="text-xs txt-secondary mt-1">{{ info.php.sapi }}</div>
        </div>

        <div class="surface-elevated rounded-lg p-4">
          <div class="flex items-center justify-between mb-1">
            <span class="text-sm txt-secondary">{{ t('admin.systemInfo.memoryLimit') }}</span>
            <Icon icon="mdi:memory" class="w-5 h-5 txt-secondary" />
          </div>
          <div class="text-2xl font-bold txt-primary">{{ info.memory.limit }}</div>
          <div class="text-xs txt-secondary mt-1">
            {{ t('admin.systemInfo.inUse', { value: formatBytes(info.memory.currentUsageBytes) }) }}
          </div>
        </div>

        <div class="surface-elevated rounded-lg p-4">
          <div class="flex items-center justify-between mb-1">
            <span class="text-sm txt-secondary">{{ t('admin.systemInfo.diskFree') }}</span>
            <Icon icon="mdi:harddisk" class="w-5 h-5 txt-secondary" />
          </div>
          <div class="text-2xl font-bold txt-primary">{{ formatBytes(info.disk.freeBytes) }}</div>
          <div class="text-xs txt-secondary mt-1">
            {{ t('admin.systemInfo.ofTotal', { value: formatBytes(info.disk.totalBytes) }) }}
          </div>
        </div>
      </div>

      <!-- Disk usage bar -->
      <div v-if="info.disk.usedPercent !== null">
        <div class="flex items-center justify-between text-xs txt-secondary mb-1">
          <span>{{ t('admin.systemInfo.diskUsage') }}</span>
          <span>{{ info.disk.usedPercent }}%</span>
        </div>
        <div class="w-full h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden">
          <div
            class="h-full rounded-full transition-all"
            :class="diskBarClass"
            :style="{ width: `${Math.min(info.disk.usedPercent, 100)}%` }"
          />
        </div>
      </div>

      <!-- Detail rows -->
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 text-sm">
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.opcache') }}</dt>
          <dd class="txt-primary font-medium">
            {{
              info.php.opcacheEnabled
                ? t('admin.systemInfo.enabled')
                : t('admin.systemInfo.disabled')
            }}
          </dd>
        </div>
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.peakMemory') }}</dt>
          <dd class="txt-primary font-medium">{{ formatBytes(info.memory.peakUsageBytes) }}</dd>
        </div>
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.uploadMax') }}</dt>
          <dd class="txt-primary font-medium">{{ info.limits.uploadMaxFilesize }}</dd>
        </div>
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.postMax') }}</dt>
          <dd class="txt-primary font-medium">{{ info.limits.postMaxSize }}</dd>
        </div>
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.maxExecTime') }}</dt>
          <dd class="txt-primary font-medium">{{ info.limits.maxExecutionTime }}s</dd>
        </div>
        <div
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.os') }}</dt>
          <dd class="txt-primary font-medium">{{ info.server.os }}</dd>
        </div>
        <div
          v-if="info.server.software"
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.server') }}</dt>
          <dd class="txt-primary font-medium truncate ml-4">{{ info.server.software }}</dd>
        </div>
        <div
          v-if="info.server.hostname"
          class="flex items-center justify-between py-1 border-b border-light-border/20 dark:border-dark-border/10"
        >
          <dt class="txt-secondary">{{ t('admin.systemInfo.hostname') }}</dt>
          <dd class="txt-primary font-medium truncate ml-4">{{ info.server.hostname }}</dd>
        </div>
      </dl>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { adminSystemInfoApi, type SystemInfo } from '@/services/api/adminSystemInfoApi'

const { t } = useI18n()

const info = ref<SystemInfo | null>(null)
const loading = ref(true)
const error = ref(false)

const diskBarClass = computed(() => {
  const pct = info.value?.disk.usedPercent ?? 0
  if (pct >= 90) return 'bg-[var(--status-error)]'
  if (pct >= 75) return 'bg-[var(--status-warning)]'
  return 'bg-[var(--brand)]'
})

const formatBytes = (bytes: number | null): string => {
  if (bytes === null) return '—'
  if (bytes < 0) return '∞'
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  const value = bytes / Math.pow(1024, i)
  return `${value.toFixed(value >= 100 || i === 0 ? 0 : 1)} ${units[i]}`
}

const load = async () => {
  loading.value = true
  error.value = false
  try {
    const response = await adminSystemInfoApi.get()
    info.value = response.system
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
