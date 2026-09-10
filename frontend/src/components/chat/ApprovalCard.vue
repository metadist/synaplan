<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import type { Approval } from '@/services/api/approvalsApi'

const props = defineProps<{
  approval: Approval
  canAlwaysAllow?: boolean
}>()

const emit = defineEmits<{
  approved: [id: number]
  rejected: [id: number, reason: string]
  alwaysAllow: [id: number]
}>()

const { t } = useI18n()
const dialog = useDialog()

const expiresIn = computed(() => {
  const remaining = props.approval.expiresAt * 1000 - Date.now()
  if (remaining <= 0) return t('approvals.expired')
  const hours = Math.max(1, Math.round(remaining / 3600000))
  return t('approvals.expiresInHours', { hours })
})

const showAlwaysAllow = computed(
  () =>
    props.canAlwaysAllow === true &&
    props.approval.canAlwaysAllow === true &&
    props.approval.sideEffect !== 'destructive'
)

const showApprove = computed(() => props.approval.sideEffect !== 'destructive')

const onApprove = () => {
  emit('approved', props.approval.id)
}

const onAlwaysAllow = () => {
  emit('alwaysAllow', props.approval.id)
}

const onReject = async () => {
  const reason = await dialog.prompt({
    title: t('approvals.reject'),
    message: t('approvals.rejectReason'),
    placeholder: t('approvals.rejectReasonPlaceholder'),
  })
  if (reason === null) return
  emit('rejected', props.approval.id, reason)
}
</script>

<template>
  <section
    class="surface-card p-4 space-y-3"
    data-testid="approval-card"
  >
    <p class="text-sm txt-secondary">{{ $t('approvals.nothingCreatedYet') }}</p>
    <p class="txt-primary font-medium">{{ approval.preview || approval.tool }}</p>
    <p class="text-xs txt-secondary">{{ expiresIn }}</p>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="showApprove"
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="approval-approve"
        @click="onApprove"
      >
        {{ $t('approvals.approve') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="approval-reject"
        @click="onReject"
      >
        {{ $t('approvals.reject') }}
      </button>
      <button
        v-if="showAlwaysAllow"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        data-testid="approval-always-allow"
        @click="onAlwaysAllow"
      >
        {{ $t('approvals.alwaysAllow') }}
      </button>
    </div>
  </section>
</template>
