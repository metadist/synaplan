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
            <button type="button" class="btn-secondary" @click="close">
              {{ $t('iam.dialog.cancel') }}
            </button>
            <button
              type="button"
              class="btn-primary"
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
                class="flex items-center justify-between gap-2 text-sm"
              >
                <span class="txt-primary truncate">{{ rowLabel(row) }}</span>
                <span class="txt-secondary shrink-0">{{
                  $t(`iam.permission.${row.permission}`)
                }}</span>
                <button
                  type="button"
                  class="txt-secondary hover:text-red-500"
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
              class="btn-secondary"
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
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { iamApi, type IamShare, type IamSubject } from '@/services/api/iamApi'
import PermissionSelect from './PermissionSelect.vue'
import SubjectPicker from './SubjectPicker.vue'

const props = defineProps<{
  isOpen: boolean
  kind: 'conversation' | 'knowledge_folder'
  resourceId: string
  resourceName: string
}>()

const emit = defineEmits<{
  close: []
  publicLink: []
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const subject = ref<IamSubject | null>(null)
const permission = ref('use')
const shares = ref<IamShare[]>([])
const saving = ref(false)

const allowedPermissions = computed(() =>
  props.kind === 'conversation' ? ['read', 'use'] : ['read', 'use', 'edit', 'manage']
)

const load = async () => {
  if (!props.isOpen || !props.resourceId) return
  shares.value = await iamApi.listShares(props.kind, props.resourceId)
}

watch(
  () => [props.isOpen, props.kind, props.resourceId],
  () => {
    void load()
  },
  { immediate: true }
)

const close = () => emit('close')

const rowLabel = (row: IamShare) => {
  if (row.subjectType === 'everyone') return t('iam.everyone')
  return row.name || row.email || String(row.subjectId)
}

const grant = async () => {
  if (!subject.value) return
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
  await iamApi.revokeShare(props.kind, props.resourceId, row.subjectType, row.subjectId)
  await load()
}
</script>
