<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import type { Approval } from '@/services/api/approvalsApi'

const props = defineProps<{
  approval: Approval
  canAlwaysAllow?: boolean
  showOpenContext?: boolean
}>()

const emit = defineEmits<{
  approved: [id: number]
  rejected: [id: number, reason: string]
  alwaysAllow: [id: number]
  openContext: [id: number]
}>()

const { t } = useI18n()
const dialog = useDialog()

const expiresIn = computed(() => {
  const remaining = props.approval.expiresAt * 1000 - Date.now()
  if (remaining <= 0) return t('approvals.expired')
  const hours = Math.max(1, Math.round(remaining / 3600000))
  return t('approvals.expiresInHours', { hours }, hours)
})

const isPublishReview = computed(() => props.approval.tool === 'agent.publish')

const canDecide = computed(() => !isPublishReview.value || props.approval.canDecide === true)

const showAlwaysAllow = computed(
  () =>
    canDecide.value &&
    !isPublishReview.value &&
    props.canAlwaysAllow === true &&
    props.approval.canAlwaysAllow === true &&
    props.approval.sideEffect !== 'destructive'
)

const showApprove = computed(() => canDecide.value && props.approval.sideEffect !== 'destructive')

const showReject = computed(() => canDecide.value)

const onApprove = () => {
  emit('approved', props.approval.id)
}

const onAlwaysAllow = () => {
  emit('alwaysAllow', props.approval.id)
}

const onReject = async () => {
  const reason = await dialog.prompt({
    title: isPublishReview.value ? t('approvals.sendBack') : t('approvals.reject'),
    message: isPublishReview.value ? t('approvals.sendBackReason') : t('approvals.rejectReason'),
    placeholder: t('approvals.rejectReasonPlaceholder'),
  })
  if (reason === null) return
  emit('rejected', props.approval.id, reason)
}
</script>

<template>
  <section class="surface-card p-4 space-y-3" data-testid="approval-card">
    <p v-if="isPublishReview" class="txt-primary text-sm" data-testid="approval-share-assistant">
      {{
        canDecide
          ? $t('approvals.shareAssistant', { name: approval.preview || '' })
          : $t('approvals.shareAssistantWaiting', { name: approval.preview || '' })
      }}
    </p>
    <p v-else class="text-sm txt-secondary">{{ $t('approvals.nothingCreatedYet') }}</p>
    <div v-if="approval.resolvedRequest" class="space-y-1" data-testid="approval-request">
      <p class="text-xs txt-secondary">{{ $t('approvals.requestHeading') }}</p>
      <p class="txt-primary font-medium break-words">
        {{ approval.resolvedRequest.method }} {{ approval.resolvedRequest.url }}
      </p>
      <pre
        v-if="approval.resolvedRequest.body"
        class="text-xs txt-primary whitespace-pre-wrap break-words surface-chip p-2 rounded-xl"
        >{{ approval.resolvedRequest.body }}</pre>
    </div>
    <p v-else-if="!isPublishReview" class="txt-primary font-medium">
      {{ approval.preview || approval.tool }}
    </p>
    <p
      v-if="isPublishReview && !canDecide"
      class="text-sm txt-secondary"
      data-testid="approval-waiting-other-admin"
    >
      {{ $t('approvals.waitingForAnotherAdmin') }}
    </p>
    <p v-if="!isPublishReview" class="text-xs txt-secondary" data-testid="approval-expires">
      {{ expiresIn }}
    </p>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="showApprove"
        type="button"
        class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="approval-approve"
        @click="onApprove"
      >
        {{ $t('approvals.approve') }}
      </button>
      <button
        v-if="showReject"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="approval-reject"
        @click="onReject"
      >
        {{ isPublishReview ? $t('approvals.sendBack') : $t('approvals.reject') }}
      </button>
      <button
        v-if="showAlwaysAllow"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="approval-always-allow"
        @click="onAlwaysAllow"
      >
        {{ $t('approvals.alwaysAllow') }}
      </button>
      <button
        v-if="showOpenContext"
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
        data-testid="approval-open-context"
        @click="emit('openContext', approval.id)"
      >
        {{ $t('approvals.openContext') }}
      </button>
    </div>
  </section>
</template>
