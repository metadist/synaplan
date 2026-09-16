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
      <p
        v-else-if="entries.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="audit-empty"
      >
        {{ $t('people.audit.empty') }}
      </p>
      <table v-else class="w-full" data-testid="table-audit">
        <thead>
          <tr class="border-b border-light-border/30 dark:border-dark-border/20">
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.when') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.who') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.actionLabel') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.kind') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.resource') }}
            </th>
            <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
              {{ $t('people.audit.subject') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="entry in entries"
            :key="entry.id"
            class="border-b border-light-border/30 dark:border-dark-border/20"
          >
            <td class="py-3 px-3 txt-secondary text-sm">{{ formatWhen(entry.created) }}</td>
            <td class="py-3 px-3 txt-primary text-sm">#{{ entry.actorId }}</td>
            <td class="py-3 px-3 txt-primary text-sm">{{ actionLabel(entry.action) }}</td>
            <td class="py-3 px-3 txt-secondary text-sm">{{ entry.kind }}</td>
            <td class="py-3 px-3 txt-secondary text-sm">{{ entry.resourceId }}</td>
            <td class="py-3 px-3 txt-secondary text-sm">{{ subjectText(entry.subject) }}</td>
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
}

const selectedAction = ref<string | undefined>(undefined)
const entries = ref<IamAuditEntry[]>([])
const nextCursor = ref<number | null>(null)
const loading = ref(false)

const filterChips = computed(() => [
  { action: undefined, label: t('people.audit.filterAll') },
  { action: 'share.grant', label: t('people.audit.action.share_grant') },
  { action: 'share.revoke', label: t('people.audit.action.share_revoke') },
  { action: 'directory.sync', label: t('people.audit.action.directory_sync') },
  { action: 'impersonation.start', label: t('people.audit.action.impersonation_start') },
  { action: 'admin.metadata_view', label: t('people.audit.action.admin_metadata_view') },
])

function actionLabel(action: string): string {
  const key = ACTION_KEYS[action]
  return key ? t(key) : action
}

function formatWhen(created: number): string {
  return formatDateTime(new Date(created * 1000))
}

function subjectText(subject: IamAuditEntry['subject']): string {
  if (!subject || typeof subject !== 'object') return '—'
  const parts = Object.entries(subject as Record<string, unknown>)
    .filter(([, value]) => value !== null && value !== undefined && value !== '')
    .map(([key, value]) => `${key}: ${String(value)}`)
  return parts.length > 0 ? parts.join(', ') : '—'
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
