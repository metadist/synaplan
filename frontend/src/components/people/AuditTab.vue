<template>
  <div data-testid="section-audit">
    <div class="surface-card rounded-lg p-4 mb-6">
      <div class="flex flex-wrap gap-2" data-testid="audit-filters">
        <button
          v-for="chip in filterChips"
          :key="chip.action ?? 'all'"
          type="button"
          class="pill text-xs"
          :class="selectedAction === chip.action ? 'pill--active' : ''"
          :data-testid="`audit-filter-${chip.action ?? 'all'}`"
          @click="selectedAction = chip.action"
        >
          {{ chip.label }}
        </button>
      </div>
    </div>

    <div class="surface-card rounded-lg p-6 overflow-x-auto">
      <div v-if="loading && entries.length === 0" class="text-center py-12">
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
      </div>
      <div
        v-else-if="entries.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="audit-empty"
      >
        <p>{{ $t('people.audit.empty') }}</p>
        <p class="mt-2">{{ $t('people.audit.emptyNote') }}</p>
      </div>
      <table v-else class="w-full" data-testid="table-audit">
        <thead>
          <tr class="border-b border-light-border/30 dark:border-dark-border/20">
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.when') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.what') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="entry in entries"
            :key="entry.id"
            class="border-b border-light-border/30 dark:border-dark-border/20"
          >
            <td class="py-3 px-3 txt-secondary text-sm whitespace-nowrap align-top">
              {{ formatWhen(entry.created) }}
            </td>
            <td class="py-3 px-3 txt-primary text-sm">{{ rowSentence(entry) }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="nextCursor !== null" class="mt-4 text-center">
        <button
          type="button"
          class="btn-secondary px-4 py-2 rounded-lg"
          :disabled="loading"
          data-testid="btn-audit-more"
          @click="loadMore"
        >
          {{ $t('people.audit.more') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { iamApi, type IamAuditEntry } from '@/services/api/iamApi'
import { useNotification } from '@/composables/useNotification'
import { useDateFormat } from '@/composables/useDateFormat'

const { t } = useI18n()
const { error: showError } = useNotification()
const { formatDateTime } = useDateFormat()

const ACTION_KEYS: Record<string, string> = {
  'share.grant': 'people.audit.action.share_grant',
  'share.revoke': 'people.audit.action.share_revoke',
  'group.create': 'people.audit.action.group_create',
  'group.update': 'people.audit.action.group_update',
  'group.rename': 'people.audit.action.group_rename',
  'group.delete': 'people.audit.action.group_delete',
  'group.member_set': 'people.audit.action.group_member_set',
  'group.member_remove': 'people.audit.action.group_member_remove',
  'group.member_leave': 'people.audit.action.group_member_leave',
  'directory.sync': 'people.audit.action.directory_sync',
  'impersonation.start': 'people.audit.action.impersonation_start',
  'impersonation.stop': 'people.audit.action.impersonation_stop',
  'admin.metadata_view': 'people.audit.action.admin_metadata_view',
  'policy.set': 'people.audit.action.policy_set',
  'policy.lock': 'people.audit.action.policy_lock',
  'platform_link.linked': 'people.audit.action.platform_link_linked',
  'platform_link.disconnected': 'people.audit.action.platform_link_disconnected',
  'platform_link.reassigned': 'people.audit.action.platform_link_reassigned',
  'platform_link.exchange_failed': 'people.audit.action.platform_link_exchange_failed',
  'platform_link.redirect_rejected': 'people.audit.action.platform_link_redirect_rejected',
  'platform_instance.registered': 'people.audit.action.platform_instance_registered',
  'platform_instance.approved': 'people.audit.action.platform_instance_approved',
  'platform_instance.revoked': 'people.audit.action.platform_instance_revoked',
  'admin.user_level_change': 'people.audit.action.admin_user_level_change',
  'messages_gateway.flags': 'people.audit.action.messages_gateway_flags',
  'messages_gateway.upstream': 'people.audit.action.messages_gateway_upstream',
  'messages_gateway.aliases': 'people.audit.action.messages_gateway_aliases',
}

const selectedAction = ref<string | undefined>(undefined)
const entries = ref<IamAuditEntry[]>([])
const nextCursor = ref<number | null>(null)
const loading = ref(false)

const filterChips = computed(() => [
  { action: undefined, label: t('people.audit.filterAll') },
  ...Object.entries(ACTION_KEYS).map(([action, key]) => ({ action, label: t(key) })),
])

function actionLabel(action: string): string {
  const key = ACTION_KEYS[action]
  return key ? t(key) : action
}

function formatWhen(created: number): string {
  return formatDateTime(new Date(created * 1000))
}

function rowSentence(entry: IamAuditEntry): string {
  const who = entry.actorName?.trim() || t('people.audit.someone')
  const resource = entry.resourceName?.trim() || t('people.audit.anItem')
  const subject = subjectLabel(entry)
  if (entry.action === 'share.grant' || entry.action === 'share.revoke') {
    const key =
      entry.action === 'share.grant'
        ? 'people.audit.sentence.shareGrant'
        : 'people.audit.sentence.shareRevoke'
    const permission = permissionLabel(entry)
    const sentence = t(key, { who, resource, subject: subject || t('people.audit.someone') })
    return permission ? `${sentence} (${permission})` : sentence
  }
  if (subject) {
    return t('people.audit.sentence.withSubject', {
      who,
      action: actionLabel(entry.action),
      resource,
      subject,
    })
  }
  return t('people.audit.sentence.generic', {
    who,
    action: actionLabel(entry.action),
    resource,
  })
}

function subjectLabel(entry: IamAuditEntry): string {
  const named = entry.subjectName?.trim()
  if (named) return named
  const subject = entry.subject
  if (subject && typeof subject === 'object' && 'subjectType' in subject) {
    const type = String((subject as { subjectType?: unknown }).subjectType ?? '')
    if (type === 'everyone') return t('iam.everyone')
  }
  return ''
}

function permissionLabel(entry: IamAuditEntry): string {
  const subject = entry.subject
  if (!subject || typeof subject !== 'object' || !('permission' in subject)) return ''
  const permission = String((subject as { permission?: unknown }).permission ?? '')
  if (!permission) return ''
  const key = `iam.permission.${permission}`
  const label = t(key)
  return label === key ? permission : label
}

async function load(reset: boolean): Promise<void> {
  loading.value = true
  try {
    const page = await iamApi.listAudit({
      action: selectedAction.value,
      cursor: reset ? undefined : (nextCursor.value ?? undefined),
    })
    entries.value = reset ? page.entries : [...entries.value, ...page.entries]
    nextCursor.value = page.nextCursor
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.audit.loadError'))
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  await load(false)
}

watch(selectedAction, () => {
  void load(true)
})

onMounted(() => {
  void load(true)
})
</script>
