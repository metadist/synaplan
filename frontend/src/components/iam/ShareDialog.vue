<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        data-testid="modal-iam-share"
        @click.self="close"
      >
        <div
          class="surface-elevated w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto scroll-thin"
          role="dialog"
          aria-modal="true"
        >
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold txt-primary">
              {{ $t('iam.dialog.title', { name: resourceName }) }}
            </h2>
            <button
              type="button"
              class="icon-ghost"
              data-testid="btn-iam-share-close"
              :aria-label="$t('common.close')"
              @click="close"
            >
              ×
            </button>
          </div>

          <SubjectPicker v-model="subject" />
          <div class="mt-4">
            <PermissionSelect v-model="permission" :allowed="allowedPermissions" />
          </div>

          <div class="mt-4 flex justify-end gap-2">
            <button
              type="button"
              class="btn-secondary px-4 py-2 rounded-lg font-medium"
              @click="close"
            >
              {{ $t('iam.dialog.cancel') }}
            </button>
            <button
              type="button"
              class="btn-primary px-4 py-2 rounded-lg font-medium"
              :disabled="!subject || saving"
              data-testid="btn-iam-share-confirm"
              @click="grant"
            >
              {{ $t('iam.share') }}
            </button>
          </div>

          <div class="mt-6 pt-4 border-t border-light-border/20 dark:border-dark-border/8">
            <h3 class="text-sm font-medium txt-primary mb-2">{{ $t('iam.dialog.sharedWith') }}</h3>
            <p v-if="shares.length === 0" class="text-sm txt-secondary">
              {{ $t('iam.dialog.empty') }}
            </p>
            <ul class="space-y-2">
              <li
                v-for="row in shares"
                :key="row.id"
                class="flex items-center justify-between gap-2 text-sm rounded-lg px-2 py-1 -mx-2"
                :class="
                  shareKey(row) === highlightedShareKey ? 'bg-[var(--brand-alpha-light)]' : ''
                "
              >
                <span class="txt-primary truncate">{{ rowLabel(row) }}</span>
                <span class="txt-secondary shrink-0">{{
                  $t(`iam.permission.${row.permission}`)
                }}</span>
                <button
                  type="button"
                  class="icon-ghost icon-ghost--danger text-sm"
                  :data-testid="`btn-iam-share-remove-${row.id}`"
                  @click="remove(row)"
                >
                  {{ $t('iam.dialog.remove') }}
                </button>
              </li>
            </ul>
          </div>

          <div
            v-if="kind === 'conversation'"
            class="mt-6 pt-4 border-t border-light-border/20 dark:border-dark-border/8"
          >
            <h3 class="text-sm font-medium txt-primary mb-2">{{ $t('iam.dialog.publicLink') }}</h3>
            <button
              type="button"
              class="btn-secondary px-4 py-2 rounded-lg font-medium"
              data-testid="btn-iam-public-link"
              @click="emit('publicLink')"
            >
              {{ $t('iam.dialog.openPublicLink') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { iamApi, type IamShare, type IamSubject } from '@/services/api/iamApi'
import PermissionSelect from './PermissionSelect.vue'
import SubjectPicker from './SubjectPicker.vue'

const props = defineProps<{
  isOpen: boolean
  kind: 'conversation' | 'knowledge_folder' | 'assistant' | 'saved_task' | 'widget'
  resourceId: string
  resourceName: string
}>()

const emit = defineEmits<{
  close: []
  publicLink: []
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const { error: showError, success: showSuccess } = useNotification()
const subject = ref<IamSubject | null>(null)
const permission = ref('use')
const shares = ref<IamShare[]>([])
const saving = ref(false)
const highlightedShareKey = ref('')
let highlightTimer: ReturnType<typeof setTimeout> | null = null

const shareKey = (row: Pick<IamShare, 'subjectType' | 'subjectId'>) =>
  `${row.subjectType}:${row.subjectId}`

const markHighlighted = (key: string) => {
  highlightedShareKey.value = key
  if (highlightTimer) clearTimeout(highlightTimer)
  highlightTimer = setTimeout(() => {
    highlightedShareKey.value = ''
    highlightTimer = null
  }, 4000)
}

const allowedPermissions = computed(() => {
  if (props.kind === 'conversation' || props.kind === 'saved_task') {
    return ['read', 'use']
  }
  if (props.kind === 'assistant') {
    return ['read', 'use', 'edit']
  }
  if (props.kind === 'widget') {
    return ['read', 'edit', 'manage']
  }
  return ['read', 'use', 'edit', 'manage']
})

const load = async () => {
  if (!props.isOpen || !props.resourceId) return
  try {
    shares.value = await iamApi.listShares(props.kind, props.resourceId)
  } catch {
    shares.value = []
    showError(t('iam.dialog.loadFailed'))
  }
}

watch(
  () => [props.isOpen, props.kind, props.resourceId],
  () => {
    void load()
  },
  { immediate: true }
)

const close = () => emit('close')

const subjectLabel = (item: IamSubject) => {
  if (item.type === 'everyone') return t('iam.everyone')
  return item.name || item.email || String(item.id)
}

const rowLabel = (row: IamShare) => {
  if (row.subjectType === 'everyone') return t('iam.everyone')
  return row.name || row.email || String(row.subjectId)
}

const grant = async () => {
  if (!subject.value) return
  const who = subjectLabel(subject.value)
  const key = `${subject.value.type}:${subject.value.id}`
  saving.value = true
  try {
    await iamApi.grantShare({
      kind: props.kind,
      resource: props.resourceId,
      subjectType: subject.value.type,
      subjectId: subject.value.id,
      permission: permission.value,
    })
    subject.value = null
    await load()
    markHighlighted(key)
    showSuccess(t('iam.dialog.shared', { name: who }))
  } catch {
    showError(t('iam.dialog.saveFailed'))
  } finally {
    saving.value = false
  }
}

const remove = async (row: IamShare) => {
  const ok = await confirm({
    title: t('iam.dialog.removeTitle'),
    message: t('iam.dialog.removeConfirm', { name: rowLabel(row) }),
    danger: true,
  })
  if (!ok) return
  try {
    await iamApi.revokeShare(props.kind, props.resourceId, row.subjectType, row.subjectId)
    await load()
    showSuccess(t('iam.dialog.removed', { name: rowLabel(row) }))
  } catch {
    showError(t('iam.dialog.removeFailed'))
  }
}

onUnmounted(() => {
  if (highlightTimer) clearTimeout(highlightTimer)
})
</script>
