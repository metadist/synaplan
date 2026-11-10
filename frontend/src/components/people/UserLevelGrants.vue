<script setup lang="ts">
import { computed } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import {
  adminApi,
  type AdminUser,
  type LevelChange,
  type LevelGrant,
} from '@/services/api/adminApi'

const props = defineProps<{
  user: AdminUser
  currentUserId: number | null | undefined
}>()

const emit = defineEmits<{
  changed: [change: { level: AdminUser['level']; grants: LevelGrant[] }]
}>()

const { t, te } = useI18n()
const { confirm } = useDialog()
const { success, warning, error: showError } = useNotification()

const levels = ['NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN'] as const
const grants = computed(() => props.user.grants ?? [])

function levelWord(level: string): string {
  const key = `admin.users.levelName.${level}`
  return te(key) ? t(key) : level
}

function sourceWord(source: string): string {
  const key = `admin.users.grantSource.${source}`
  return te(key) ? t(key) : source
}

function noticeText(notice: NonNullable<LevelChange['notice']>): string {
  const level = levelWord(notice.level)
  const source = notice.source ? sourceWord(notice.source) : ''
  if (notice.kind === 'kept') {
    return t('admin.users.levelKept', { level, source })
  }
  if (notice.source) {
    return t('admin.users.grantRemovedKeeps', {
      removed: levelWord(notice.removed ?? notice.level),
      level,
      source,
    })
  }
  return t('admin.users.grantRemovedNow', {
    removed: levelWord(notice.removed ?? notice.level),
    level,
  })
}

function apply(result: LevelChange, select?: HTMLSelectElement): void {
  if (select) select.value = result.user.level
  emit('changed', { level: result.user.level, grants: result.user.grants })
  if (result.notice) {
    const text = noticeText(result.notice)
    if (result.notice.kind === 'kept') warning(text)
    else success(text)
    return
  }
  success(t('admin.users.levelUpdated', { level: levelWord(result.user.level) }))
}

async function onChange(event: Event): Promise<void> {
  const select = event.target as HTMLSelectElement
  const next = select.value
  const previous = props.user.level
  if (next === previous) return

  const email = props.user.email ?? `#${props.user.id}`
  const confirmed = await confirm({
    title: t('admin.users.levelConfirmTitle', { email }),
    message:
      next === 'ADMIN'
        ? t('admin.users.levelConfirmAdmin', { email })
        : t('admin.users.levelConfirmMessage', {
            email,
            oldLevel: levelWord(previous),
            newLevel: levelWord(next),
          }),
    confirmText: t('admin.users.levelConfirmAction'),
    danger: next === 'ADMIN',
  })
  if (!confirmed) {
    select.value = previous
    return
  }

  try {
    apply(await adminApi.updateUserLevel(props.user.id, next), select)
  } catch (err) {
    select.value = previous
    showError(err instanceof Error ? err.message : t('admin.users.levelUpdateFailed'))
  }
}

async function removeGrant(grant: LevelGrant): Promise<void> {
  const confirmed = await confirm({
    title: t('admin.users.grantRemove'),
    message: t('admin.users.grantRemoveConfirm', { removed: levelWord(grant.level) }),
    confirmText: t('admin.users.grantRemove'),
    danger: true,
  })
  if (!confirmed) return

  try {
    apply(await adminApi.removeAdminGrant(props.user.id))
  } catch (err) {
    showError(err instanceof Error ? err.message : t('admin.users.levelUpdateFailed'))
  }
}
</script>

<template>
  <div class="min-w-0 space-y-2">
    <select
      :value="user.level"
      class="w-full max-w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
      :disabled="user.id === currentUserId"
      :data-testid="`select-user-level-${user.id}`"
      @change="onChange"
    >
      <option v-if="user.level === 'ANONYMOUS'" value="ANONYMOUS">
        {{ levelWord('ANONYMOUS') }}
      </option>
      <option v-for="level in levels" :key="level" :value="level">
        {{ levelWord(level) }}
      </option>
    </select>

    <p v-if="grants.length === 0" class="text-xs txt-secondary">
      {{ t('admin.users.grantNone') }}
    </p>
    <ul v-else class="space-y-1">
      <li
        v-for="grant in grants"
        :key="grant.source"
        class="flex flex-wrap items-center gap-2 text-xs txt-secondary"
      >
        <span>{{ levelWord(grant.level) }} · {{ sourceWord(grant.source) }}</span>
        <span v-if="grant.reference" class="break-all">{{ grant.reference }}</span>
        <button
          v-if="grant.source === 'admin' && user.id !== currentUserId"
          type="button"
          class="btn-secondary inline-flex items-center gap-1 px-4 py-2.5 text-sm font-medium"
          :data-testid="`btn-remove-admin-grant-${user.id}`"
          @click="removeGrant(grant)"
        >
          <XMarkIcon class="w-4 h-4" />
          {{ t('admin.users.grantRemove') }}
        </button>
      </li>
    </ul>
  </div>
</template>
