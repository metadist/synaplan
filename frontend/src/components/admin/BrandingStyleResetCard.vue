<script setup lang="ts">
/**
 * One-click return to the default Synaplan style.
 *
 * Lives on Operate › System configuration › Branding, above the color and font
 * fields. Resets BOTH theme modes at once (the fields below only ever show one
 * mode), while brand identity — name, logos, legal links, navigation and
 * attribution — is deliberately kept.
 */
import { ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import type { SystemConfigHandle } from '@/composables/useSystemConfig'

const props = defineProps<{
  config: SystemConfigHandle
}>()

const { t } = useI18n()
const dialog = useDialog()

const resetting = ref(false)

async function resetStyle(): Promise<void> {
  const confirmed = await dialog.confirm({
    title: t('admin.config.brandingReset.confirmTitle'),
    message: t('admin.config.brandingReset.confirmMessage'),
    confirmText: t('admin.config.brandingReset.button'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  resetting.value = true
  try {
    await props.config.resetBrandingStyle()
  } finally {
    resetting.value = false
  }
}
</script>

<template>
  <div class="surface-card rounded-lg p-4" data-testid="branding-style-reset">
    <div class="flex items-start gap-3">
      <Icon
        icon="heroicons:paint-brush"
        class="w-5 h-5 text-[var(--brand)] flex-shrink-0 mt-0.5"
        aria-hidden="true"
      />
      <div class="flex-1 min-w-0">
        <h3 class="text-sm font-medium txt-primary">
          {{ $t('admin.config.brandingReset.title') }}
        </h3>
        <p class="text-sm txt-secondary mt-1">
          {{ $t('admin.config.brandingReset.description') }}
        </p>
        <button
          type="button"
          class="btn-secondary mt-3 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="resetting"
          data-testid="btn-reset-branding-style"
          @click="resetStyle"
        >
          <Icon
            :icon="resetting ? 'mdi:loading' : 'heroicons:arrow-path'"
            :class="['w-4 h-4', resetting && 'animate-spin']"
            aria-hidden="true"
          />
          {{
            resetting
              ? $t('admin.config.brandingReset.resetting')
              : $t('admin.config.brandingReset.button')
          }}
        </button>
      </div>
    </div>
  </div>
</template>
