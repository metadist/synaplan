<template>
  <div
    class="storage-quota-widget surface-card rounded-lg p-4 border border-light-border/30 dark:border-dark-border/20"
    data-testid="widget-storage-quota"
  >
    <!-- Header -->
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="text-sm font-medium txt-primary">{{ $t('storage.title') }}</h3>
        <p class="text-xs txt-secondary mt-0.5">
          {{ $t('storage.plan') }}: <span class="font-medium txt-brand">{{ planName }}</span>
        </p>
      </div>
      <Icon :icon="storageIcon" :class="['w-6 h-6', storageIconColor]" />
    </div>

    <!-- Progress Bar -->
    <div>
      <div class="h-2 bg-black/10 dark:bg-white/10 rounded-full overflow-hidden mb-1.5">
        <div
          :class="['h-full transition-all duration-300 rounded-full', progressBarColor]"
          :style="{ width: `${percentage}%` }"
        ></div>
      </div>
      <div class="text-xs txt-secondary">
        {{ stats?.storage.usage_formatted || '0 B' }} / {{ stats?.storage.limit_formatted || '100 MB' }}
      </div>
    </div>

    <!-- Warning/Upgrade Message -->
    <div
      v-if="percentage > 80"
      class="mt-3 p-2 rounded-lg bg-orange-500/10 dark:bg-orange-500/20 border border-orange-500/30 dark:border-orange-500/40"
    >
      <p class="text-xs text-orange-600 dark:text-orange-400">
        <Icon icon="mdi:alert" class="inline w-4 h-4 mr-1" />
        {{
          percentage >= 100
            ? $t('storage.limitReached')
            : $t('storage.almostFull', { remaining: stats?.storage.remaining_formatted })
        }}
      </p>
      <button
        v-if="stats?.user_level === 'NEW'"
        class="mt-2 w-full btn-primary text-xs py-1.5 px-3 rounded"
        data-testid="btn-storage-upgrade"
        @click="$emit('upgrade')"
      >
        {{ $t('storage.upgradePlan') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import filesService from '@/services/filesService'

defineEmits<{
  upgrade: []
}>()

const stats = ref<any>(null)
const loading = ref(true)

const percentage = computed(() => {
  if (!stats.value?.storage) return 0
  return Math.min(100, stats.value.storage.percentage)
})

const planLabels = {
  NEW: 'Free',
  PRO: 'Pro',
  TEAM: 'Team',
  BUSINESS: 'Business',
} as const

const planName = computed(() => {
  const level = (stats.value?.user_level as keyof typeof planLabels | undefined) ?? 'NEW'
  return planLabels[level] ?? level
})

const storageIcon = computed(() => {
  if (percentage.value >= 100) return 'mdi:cloud-alert'
  if (percentage.value >= 80) return 'mdi:cloud-sync'
  return 'mdi:cloud-check'
})

const storageIconColor = computed(() => {
  if (percentage.value >= 100) return 'text-red-500 dark:text-red-400'
  if (percentage.value >= 80) return 'text-orange-500 dark:text-orange-400'
  return 'text-green-500 dark:text-green-400'
})

const progressBarColor = computed(() => {
  if (percentage.value >= 100) return 'bg-red-500'
  if (percentage.value >= 80) return 'bg-orange-500'
  return 'bg-[var(--brand)]'
})

const loadStats = async () => {
  loading.value = true
  try {
    const response = await filesService.getStorageStats()
    stats.value = response
  } catch (error) {
    console.error('Failed to load storage stats:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadStats()
})

// Expose refresh method for parent component
defineExpose({
  refresh: loadStats,
})
</script>
