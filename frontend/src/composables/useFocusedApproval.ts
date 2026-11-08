import { computed, ref, watch, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { approvalsApi, type Approval } from '@/services/api/approvalsApi'
import { useApprovalsStore } from '@/stores/approvals'

/**
 * The approval named in the address, kept in step with the inbox lists.
 *
 * A decision in this inbox or in another session replaces the pending card,
 * so approve and reject do not stay on a row that already finished.
 */
export function useFocusedApproval(tab: Ref<'pending' | 'decided'>) {
  const route = useRoute()
  const store = useApprovalsStore()
  const focusedApproval = ref<Approval | null>(null)
  let focusRequest = 0
  let statusRequest = 0

  const focusedApprovalId = computed(() => positiveQueryId(route.query.approval))
  const taskFilter = computed(() => numericQueryId(route.query.task))

  function adoptFresher(): void {
    focusedApproval.value = adoptListed(focusedApproval.value, store.pending, store.decided)
  }

  async function rereadIfDecidedElsewhere(): Promise<void> {
    const openedAt = focusRequest
    const request = ++statusRequest
    const again = await rereadPending(focusedApproval.value, store.pending, store.decided)
    if (again === undefined || request !== statusRequest || openedAt !== focusRequest) return
    if (focusedApproval.value?.id !== again.id || focusedApproval.value.status !== 'pending') return
    focusedApproval.value = again
    if (again.status !== 'pending' && tab.value === 'pending') {
      tab.value = 'decided'
      await store.load('decided')
    }
  }

  async function openFocusedApproval(): Promise<void> {
    const request = ++focusRequest
    statusRequest += 1
    focusedApproval.value = null
    const id = focusedApprovalId.value
    if (id === null) {
      tab.value = 'pending'
      await store.load('pending')
      return
    }
    try {
      const approval = await approvalsApi.get(id)
      if (request !== focusRequest) return
      tab.value = approval.status === 'pending' ? 'pending' : 'decided'
      await store.load(tab.value)
      if (request !== focusRequest) return
      const source = tab.value === 'pending' ? store.pending : store.decided
      focusedApproval.value = source.find((row) => row.id === approval.id) ?? approval
      await rereadIfDecidedElsewhere()
    } catch {
      if (request !== focusRequest) return
      focusedApproval.value = null
      await store.load(tab.value)
    }
  }

  watch(focusedApprovalId, () => {
    void openFocusedApproval()
  })
  watch(
    () => store.pending,
    () => {
      adoptFresher()
      void rereadIfDecidedElsewhere()
    }
  )
  watch(
    () => store.decided,
    () => {
      adoptFresher()
    }
  )

  const rows = computed(() =>
    inboxRows(tab.value, store.pending, store.decided, focusedApproval.value, taskFilter.value)
  )

  return { focusedApproval, openFocusedApproval, rows }
}

function positiveQueryId(raw: unknown): number | null {
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

function numericQueryId(raw: unknown): number | null {
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isFinite(id) ? id : null
}

function adoptListed(
  focused: Approval | null,
  pending: Approval[],
  decided: Approval[]
): Approval | null {
  if (focused === null) return null
  const decidedRow = decided.find((row) => row.id === focused.id)
  if (decidedRow && decidedRow.status !== 'pending' && focused.status === 'pending') {
    return decidedRow
  }
  const source = focused.status === 'pending' ? pending : decided
  return source.find((row) => row.id === focused.id) ?? focused
}

async function rereadPending(
  focused: Approval | null,
  pending: Approval[],
  decided: Approval[]
): Promise<Approval | undefined> {
  if (focused === null || focused.status !== 'pending') return undefined
  if (pending.some((row) => row.id === focused.id)) return undefined
  const decidedRow = decided.find((row) => row.id === focused.id)
  if (decidedRow && decidedRow.status !== 'pending') return decidedRow
  try {
    return await approvalsApi.get(focused.id)
  } catch {
    // A failed refresh is not a decision. The card stays until the next load.
    return undefined
  }
}

export function inboxRows(
  tab: 'pending' | 'decided',
  pending: Approval[],
  decided: Approval[],
  focused: Approval | null,
  taskId: number | null
): Approval[] {
  const source = tab === 'pending' ? pending : decided
  const stored = focused ? source.find((row) => row.id === focused.id) : undefined
  const stalePending =
    tab === 'pending' &&
    focused !== null &&
    focused.status !== 'pending' &&
    stored?.status === 'pending'
  const visible = stalePending ? source.filter((row) => row.id !== focused.id) : source
  const head = stalePending ? focused : (stored ?? focused)
  const onThisTab =
    head !== null && head !== undefined && (tab === 'pending') === (head.status === 'pending')
  const list = onThisTab ? [head, ...visible.filter((row) => row.id !== head.id)] : visible
  if (taskId === null) return list
  return list.filter((row) => row.requestedBy.taskId === taskId)
}
