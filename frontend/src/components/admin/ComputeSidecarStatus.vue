<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getFeaturesStatus } from '@/services/featuresService'

/**
 * One sentence next to the File work switch. A stopped sidecar says so here,
 * not only later in a chat.
 */
const { t } = useI18n()
const loaded = ref(false)
const reachable = ref(false)

onMounted(async () => {
  try {
    const status = await getFeaturesStatus()
    if (status.compute) {
      reachable.value = status.compute.reachable
    } else {
      const module = status.modules?.find((row) => row.id === 'compute')
      reachable.value = module?.healthy === true && module.state !== 'absent'
    }
  } catch {
    reachable.value = false
  } finally {
    loaded.value = true
  }
})
</script>

<template>
  <p
    v-if="loaded"
    class="text-sm txt-secondary"
    data-testid="compute-sidecar-status"
    :data-reachable="reachable ? 'true' : 'false'"
  >
    {{ reachable ? t('modules.compute.reachable') : t('modules.compute.notRunning') }}
  </p>
</template>
