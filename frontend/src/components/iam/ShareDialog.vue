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
          <div class="flex items-start justify-between gap-3 mb-1">
            <div class="min-w-0">
              <h2 class="text-lg font-semibold txt-primary">
                {{ $t('iam.dialog.title', { name: resourceName }) }}
              </h2>
              <p class="text-sm txt-secondary mt-0.5" data-testid="iam-share-owner">
                {{
                  ownerName.trim()
                    ? $t('iam.dialog.ownerLine', { name: ownerName.trim() })
                    : $t('iam.owner')
                }}
              </p>
            </div>
            <button
              type="button"
              class="icon-ghost shrink-0"
              data-testid="btn-iam-share-close"
              :aria-label="$t('common.close')"
              @click="close"
            >
              ×
            </button>
          </div>

          <div class="mt-4 flex flex-col sm:flex-row gap-2" data-testid="iam-share-add-row">
            <SubjectPicker ref="picker" v-model="subject" class="sm:flex-1" :active="isOpen" />
            <PermissionSelect
              v-model="permission"
              class="sm:w-44 shrink-0"
              :allowed="allowedPermissions"
              :kind="kind"
            />
            <button
              type="button"
              class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium w-full sm:w-auto shrink-0"
              :disabled="!subject || saving"
              data-testid="btn-iam-share-confirm"
              @click="grant"
            >
              {{ $t('iam.share') }}
            </button>
          </div>

          <p class="mt-3 text-sm txt-primary" data-testid="iam-share-consequence">
            {{ $t(shareConsequenceKey(kind, permission)) }}
          </p>
          <p class="text-sm txt-secondary" data-testid="iam-share-find">
            {{ $t(shareFindKey(kind)) }}
          </p>

          <div class="mt-6 pt-4 border-t border-light-border/20 dark:border-dark-border/8">
            <h3 class="text-sm font-medium txt-primary mb-2">{{ $t('iam.dialog.sharedWith') }}</h3>
            <div v-if="loadError" class="text-sm txt-secondary" data-testid="iam-share-load-error">
              <p>{{ $t('iam.dialog.loadFailed') }}</p>
              <button
                type="button"
                class="btn-secondary mt-2 px-4 py-2.5 rounded-lg text-sm font-medium"
                data-testid="btn-iam-share-retry"
                @click="load"
              >
                {{ $t('iam.dialog.tryAgain') }}
              </button>
            </div>
            <p
              v-else-if="shares.length === 0"
              class="text-sm txt-secondary"
              data-testid="iam-share-empty"
            >
              {{ $t('iam.dialog.empty') }}
            </p>
            <ul v-else class="space-y-2">
              <li
                v-for="row in shares"
                :key="row.id"
                class="flex flex-col sm:flex-row sm:items-center gap-2 text-sm rounded-lg px-2 py-2 -mx-2"
                :class="
                  shareKey(row) === highlightedShareKey ? 'bg-[var(--brand-alpha-light)]' : ''
                "
                :data-testid="`iam-share-row-${row.id}`"
              >
                <div class="min-w-0 flex-1">
                  <p class="txt-primary truncate font-medium">{{ rowLabel(row) }}</p>
                  <p v-if="row.subjectType !== 'everyone'" class="text-xs txt-secondary">
                    {{ $t(`iam.dialog.subjectType.${row.subjectType}`) }}
                  </p>
                </div>
                <PermissionSelect
                  class="sm:w-40 shrink-0"
                  :model-value="row.permission"
                  :allowed="allowedPermissions"
                  :kind="kind"
                  @update:model-value="changePermission(row, $event)"
                />
                <button
                  type="button"
                  class="icon-ghost icon-ghost--danger text-sm shrink-0"
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
            <h3 class="text-sm font-medium txt-primary mb-1">{{ $t('iam.dialog.publicLink') }}</h3>
            <p class="text-sm txt-secondary mb-3">{{ $t('iam.dialog.publicLinkHint') }}</p>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
              data-testid="btn-iam-public-link"
              @click="emit('publicLink')"
            >
              {{ $t('iam.dialog.managePublicLink') }}
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
import {
  defaultSharePermission,
  SHARE_PERMISSIONS,
  shareConsequenceKey,
  shareFindKey,
  type ShareKind,
} from '@/utils/shareCopy'
import PermissionSelect from './PermissionSelect.vue'
import SubjectPicker from './SubjectPicker.vue'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    kind: ShareKind
    resourceId: string
    resourceName: string
    ownerName?: string
  }>(),
  { ownerName: '' }
)

const emit = defineEmits<{
  close: []
  publicLink: []
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const { error: showError, success: showSuccess } = useNotification()
const subject = ref<IamSubject | null>(null)
const permission = ref(defaultSharePermission(props.kind))
const shares = ref<IamShare[]>([])
const saving = ref(false)
const loadError = ref(false)
const highlightedShareKey = ref('')
const picker = ref<{ resetQuery: () => void } | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | null = null

const allowedPermissions = computed(() => [...SHARE_PERMISSIONS[props.kind]])

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

const load = async () => {
  if (!props.isOpen || !props.resourceId) return
  try {
    shares.value = await iamApi.listShares(props.kind, props.resourceId)
    loadError.value = false
  } catch {
    shares.value = []
    loadError.value = true
    showError(t('iam.dialog.loadFailed'))
  }
}

watch(
  () => [props.isOpen, props.kind, props.resourceId],
  () => {
    if (props.isOpen) {
      subject.value = null
      permission.value = defaultSharePermission(props.kind)
      picker.value?.resetQuery()
      void load()
    }
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
    picker.value?.resetQuery()
    await load()
    markHighlighted(key)
    showSuccess(t('iam.dialog.sharedFind', { name: who, find: t(shareFindKey(props.kind)) }))
  } catch {
    showError(t('iam.dialog.saveFailed'))
  } finally {
    saving.value = false
  }
}

const changePermission = async (row: IamShare, next: string) => {
  if (next === row.permission) return
  const previous = row.permission
  row.permission = next as IamShare['permission']
  try {
    await iamApi.grantShare({
      kind: props.kind,
      resource: props.resourceId,
      subjectType: row.subjectType,
      subjectId: row.subjectId,
      permission: next,
    })
  } catch {
    row.permission = previous
    showError(t('iam.dialog.permissionFailed'))
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
