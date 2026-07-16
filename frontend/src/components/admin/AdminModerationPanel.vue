<template>
  <div class="space-y-6" data-testid="admin-moderation-panel">
    <div class="surface-card p-6">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
          <h3 class="text-lg font-semibold txt-primary">
            {{ $t('admin.moderation.title') }}
          </h3>
          <p class="text-sm txt-secondary mt-1">
            {{ $t('admin.moderation.description') }}
          </p>
        </div>

        <!-- Status filter -->
        <div class="flex items-center gap-1 rounded-lg surface-chip p-1">
          <button
            v-for="option in statusFilters"
            :key="option"
            type="button"
            class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
            :class="
              statusFilter === option
                ? 'bg-[var(--brand)] text-white'
                : 'txt-secondary hover:txt-primary'
            "
            :data-testid="`filter-${option || 'all'}`"
            @click="setFilter(option)"
          >
            {{ $t(`admin.moderation.filters.${option || 'all'}`) }}
          </button>
        </div>
      </div>

      <div v-if="loading" class="flex justify-center py-12" data-testid="loading">
        <div
          class="w-8 h-8 border-4 border-gray-300 dark:border-gray-600 border-t-[var(--brand)] rounded-full animate-spin"
        />
      </div>

      <div
        v-else-if="reports.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="empty-state"
      >
        {{ $t('admin.moderation.empty') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="reports-table">
          <thead>
            <tr class="border-b border-light-border/30 dark:border-dark-border/20">
              <th class="text-left py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.reason') }}
              </th>
              <th class="text-left py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.content') }}
              </th>
              <th class="text-left py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.reportedUser') }}
              </th>
              <th class="text-left py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.created') }}
              </th>
              <th class="text-center py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.status') }}
              </th>
              <th class="text-center py-3 px-3 font-medium txt-secondary">
                {{ $t('admin.moderation.col.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="report in reports"
              :key="report.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors align-top"
            >
              <td class="py-3 px-3">
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400"
                >
                  {{ $t(`moderation.reasons.${report.reason}.label`, report.reason ?? '') }}
                </span>
                <p v-if="report.details" class="text-[11px] txt-secondary mt-1 max-w-xs">
                  {{ report.details }}
                </p>
              </td>
              <td class="py-3 px-3 txt-secondary whitespace-nowrap">
                {{ report.contentType }} #{{ report.contentId }}
              </td>
              <td class="py-3 px-3">
                <template v-if="report.reportedUserId">
                  <p class="txt-primary">
                    {{ report.reportedUserEmail || `#${report.reportedUserId}` }}
                  </p>
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium mt-0.5"
                    :class="accountStatusBadgeClass(report.reportedUserStatus)"
                  >
                    {{
                      $t(`admin.moderation.accountStatus.${report.reportedUserStatus || 'active'}`)
                    }}
                  </span>
                </template>
                <span v-else class="txt-secondary text-xs italic">
                  {{ $t('admin.moderation.unresolvedUser') }}
                </span>
              </td>
              <td class="py-3 px-3 txt-secondary whitespace-nowrap">
                {{ report.created }}
              </td>
              <td class="py-3 px-3 text-center">
                <select
                  :value="report.status"
                  class="px-2 py-1 text-xs rounded border border-light-border dark:border-dark-border bg-white dark:bg-gray-800 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  :data-testid="`report-status-${report.id}`"
                  @change="changeReportStatus(report, ($event.target as HTMLSelectElement).value)"
                >
                  <option v-for="s in reportStatuses" :key="s" :value="s">
                    {{ $t(`admin.moderation.reportStatus.${s}`) }}
                  </option>
                </select>
              </td>
              <td class="py-3 px-3">
                <div
                  v-if="report.reportedUserId"
                  class="flex flex-wrap items-center justify-center gap-1.5"
                >
                  <button
                    v-if="report.reportedUserStatus !== 'suspended'"
                    class="px-2.5 py-1 text-xs font-medium rounded border border-amber-500/40 text-amber-600 dark:text-amber-400 hover:bg-amber-500/10 transition-colors"
                    :data-testid="`btn-suspend-${report.id}`"
                    @click="changeUserStatus(report, 'suspended')"
                  >
                    {{ $t('admin.moderation.suspend') }}
                  </button>
                  <button
                    v-if="report.reportedUserStatus !== 'banned'"
                    class="px-2.5 py-1 text-xs font-medium rounded border border-red-500/40 text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors"
                    :data-testid="`btn-ban-${report.id}`"
                    @click="changeUserStatus(report, 'banned')"
                  >
                    {{ $t('admin.moderation.ban') }}
                  </button>
                  <button
                    v-if="report.reportedUserStatus !== 'active'"
                    class="px-2.5 py-1 text-xs font-medium rounded border border-green-500/40 text-green-600 dark:text-green-400 hover:bg-green-500/10 transition-colors"
                    :data-testid="`btn-reactivate-${report.id}`"
                    @click="changeUserStatus(report, 'active')"
                  >
                    {{ $t('admin.moderation.reactivate') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="!loading && total > perPage" class="flex items-center justify-between mt-4">
        <p class="text-xs txt-secondary">
          {{ $t('admin.moderation.totalCount', { count: total }) }}
        </p>
        <div class="flex items-center gap-2">
          <button
            class="px-3 py-1 text-xs rounded border border-light-border dark:border-dark-border txt-secondary hover:txt-primary transition-colors disabled:opacity-40"
            :disabled="page <= 1"
            @click="goToPage(page - 1)"
          >
            {{ $t('common.back') }}
          </button>
          <span class="text-xs txt-secondary">{{ page }} / {{ totalPages }}</span>
          <button
            class="px-3 py-1 text-xs rounded border border-light-border dark:border-dark-border txt-secondary hover:txt-primary transition-colors disabled:opacity-40"
            :disabled="page >= totalPages"
            @click="goToPage(page + 1)"
          >
            {{ $t('common.continue') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import {
  adminModerationApi,
  type ModerationReport,
  type ReportStatus,
  type AccountStatus,
} from '@/services/api/moderationApi'

const { t } = useI18n()
const { success, error: notifyError } = useNotification()
const { confirm } = useDialog()

const reportStatuses: ReportStatus[] = ['open', 'reviewed', 'actioned', 'dismissed']
const statusFilters: (ReportStatus | '')[] = ['', 'open', 'reviewed', 'actioned', 'dismissed']

const reports = ref<ModerationReport[]>([])
const loading = ref(true)
const statusFilter = ref<ReportStatus | ''>('open')
const page = ref(1)
const perPage = 25
const total = ref(0)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

const loadReports = async () => {
  loading.value = true
  try {
    const response = await adminModerationApi.listReports(statusFilter.value, page.value, perPage)
    reports.value = response.reports ?? []
    total.value = response.total ?? 0
  } catch {
    notifyError(t('admin.moderation.loadError'))
  } finally {
    loading.value = false
  }
}

const setFilter = (status: ReportStatus | '') => {
  if (statusFilter.value === status) {
    return
  }
  statusFilter.value = status
  page.value = 1
  loadReports()
}

const goToPage = (target: number) => {
  if (target < 1 || target > totalPages.value) {
    return
  }
  page.value = target
  loadReports()
}

const changeReportStatus = async (report: ModerationReport, status: string) => {
  const newStatus = status as ReportStatus
  if (report.status === newStatus) {
    return
  }
  try {
    const updated = await adminModerationApi.updateReportStatus(report.id as number, newStatus)
    report.status = updated.status
    success(t('admin.moderation.reportUpdated'))
    // A closed report drops out of a narrower filter view.
    if (statusFilter.value !== '' && statusFilter.value !== newStatus) {
      loadReports()
    }
  } catch {
    notifyError(t('admin.moderation.updateError'))
  }
}

const changeUserStatus = async (report: ModerationReport, status: AccountStatus) => {
  const userId = report.reportedUserId
  if (!userId) {
    return
  }
  const confirmed = await confirm({
    title: t(`admin.moderation.confirm.${status}.title`),
    message: t(`admin.moderation.confirm.${status}.message`, {
      user: report.reportedUserEmail || `#${userId}`,
    }),
    confirmText: t(
      `admin.moderation.${status === 'active' ? 'reactivate' : status === 'banned' ? 'ban' : 'suspend'}`
    ),
    danger: status !== 'active',
  })
  if (!confirmed) {
    return
  }
  try {
    const updated = await adminModerationApi.updateUserStatus(userId, status)
    // Reflect the new account status on every report for this user.
    for (const r of reports.value) {
      if (r.reportedUserId === userId) {
        r.reportedUserStatus = updated.accountStatus
      }
    }
    success(t('admin.moderation.userStatusUpdated'))
  } catch {
    notifyError(t('admin.moderation.updateError'))
  }
}

const accountStatusBadgeClass = (status?: string | null) => {
  const classes: Record<string, string> = {
    active: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    suspended: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
    banned: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  }
  return classes[status ?? 'active'] ?? classes.active
}

onMounted(loadReports)
</script>
