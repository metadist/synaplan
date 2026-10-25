<template>
  <div class="mt-3 border-t border-light-border/20 dark:border-dark-border/20 pt-3">
    <p class="text-xs font-medium txt-secondary mb-2">{{ $t('admin.signupCodes.uses.title') }}</p>
    <p v-if="loading" class="text-sm txt-secondary">{{ $t('common.loading') }}</p>
    <p
      v-else-if="failed"
      role="alert"
      class="text-sm text-red-600 dark:text-red-400"
      data-testid="text-signup-code-uses-error"
    >
      {{ $t('admin.signupCodes.uses.loadFailed') }}
    </p>
    <p
      v-else-if="!uses.length"
      class="text-sm txt-secondary"
      data-testid="text-signup-code-uses-empty"
    >
      {{ $t('admin.signupCodes.uses.empty') }}
    </p>
    <ul v-else class="space-y-1" data-testid="list-signup-code-uses">
      <li
        v-for="use in uses"
        :key="use.id"
        class="flex flex-wrap items-baseline gap-x-2 text-sm"
        data-testid="item-signup-code-use"
      >
        <span class="txt-primary break-words min-w-0">{{
          use.email ?? $t('admin.signupCodes.uses.deleted')
        }}</span>
        <span class="txt-secondary text-xs">{{ formatDateTime(new Date(use.usedAt * 1000)) }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useDateFormat } from '@/composables/useDateFormat'
import { signupCodesApi, type SignupCodeUse } from '@/services/api/signupCodesApi'

const props = defineProps<{ codeId: number }>()

const { formatDateTime } = useDateFormat()
const uses = ref<SignupCodeUse[]>([])
const loading = ref(true)
const failed = ref(false)

onMounted(async () => {
  try {
    uses.value = await signupCodesApi.uses(props.codeId)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})
</script>
